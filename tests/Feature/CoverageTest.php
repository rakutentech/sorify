<?php

namespace Tests\Feature;

use App\Jobs\GenerateRunCoverageReportJob;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\CoverageService;
use App\Services\TestRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('coverage');
    }

    // ─── HTTP: suite settings persistence ─────────────────────────────────

    public function test_coverage_settings_can_be_persisted(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->put("/sorify/suites/{$suite->id}", [
                'collect_coverage' => true,
                'coverage_url_filter' => 'example.com/assets/*.js',
            ])
            ->assertRedirect();

        $suite->refresh();
        $this->assertTrue((bool) $suite->collect_coverage);
        $this->assertSame('example.com/assets/*.js', $suite->coverage_url_filter);
    }

    public function test_coverage_filter_needs_no_escaping(): void
    {
        // The filter is a simple glob (literal text + * wildcards), so even
        // regex-special characters are stored and matched verbatim.
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->put("/sorify/suites/{$suite->id}", [
                'collect_coverage' => true,
                'coverage_url_filter' => 'cdn+1.example.com/app*.js',
            ])
            ->assertRedirect();

        $this->assertSame('cdn+1.example.com/app*.js', $suite->refresh()->coverage_url_filter);
    }

    public function test_multiple_coverage_filters_are_normalized(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        // Parts are trimmed and empties (stray commas) are dropped.
        $this->actingAs($admin)
            ->put("/sorify/suites/{$suite->id}", [
                'collect_coverage' => true,
                'coverage_url_filter' => ' example.com/assets/*.js , , cdn.shop.com/*.js ,',
            ])
            ->assertRedirect();

        $this->assertSame(
            'example.com/assets/*.js,cdn.shop.com/*.js',
            $suite->refresh()->coverage_url_filter
        );
    }

    public function test_coverage_report_job_matches_any_of_multiple_filters(): void
    {
        // The second pattern matches the collected script, so the report is
        // generated even though the first pattern matches nothing.
        $run = $this->makeRun(['collect_coverage' => true, 'coverage_url_filter' => 'nomatch.example.com, example.com/app*.js']);
        $run->testResults()->update(['status' => 'passed', 'completed_at' => now()]);
        $run->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $result = $run->testResults()->first();
        Storage::disk('coverage')->put(
            app(CoverageService::class)->resultPath($run->test_suite_id, $run->id, $result->test_id),
            json_encode($this->coverageFixture())
        );

        (new GenerateRunCoverageReportJob($run->refresh()))
            ->handle(app(CoverageService::class));

        $this->assertNotNull($run->refresh()->coverage_summary);
    }

    public function test_coverage_report_job_honors_the_filter(): void
    {
        // A filter matching none of the collected scripts drops every entry,
        // so no report or summary is produced.
        $run = $this->makeRun(['collect_coverage' => true, 'coverage_url_filter' => 'nomatch.example.com']);
        $run->testResults()->update(['status' => 'passed', 'completed_at' => now()]);
        $run->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $result = $run->testResults()->first();
        Storage::disk('coverage')->put(
            app(CoverageService::class)->resultPath($run->test_suite_id, $run->id, $result->test_id),
            json_encode($this->coverageFixture())
        );

        (new GenerateRunCoverageReportJob($run->refresh()))
            ->handle(app(CoverageService::class));

        $this->assertNull($run->refresh()->coverage_summary);
    }

    public function test_empty_coverage_filter_clears_the_setting(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create([
            'name' => 'Suite',
            'created_by' => $admin->id,
            'collect_coverage' => true,
            'coverage_url_filter' => 'old',
        ]);

        $this->actingAs($admin)
            ->put("/sorify/suites/{$suite->id}", ['coverage_url_filter' => ''])
            ->assertRedirect();

        $this->assertNull($suite->refresh()->coverage_url_filter);
    }

    // ─── Finalization: report job dispatch ─────────────────────────────────

    public function test_finalize_run_dispatches_coverage_job_when_suite_collects_coverage(): void
    {
        Queue::fake();

        $run = $this->makeRun(['collect_coverage' => true]);

        app(TestRunService::class)->finalizeRun($run);

        Queue::assertPushed(GenerateRunCoverageReportJob::class, fn ($job) => $job->testRun->is($run));
    }

    public function test_finalize_run_skips_coverage_job_when_disabled(): void
    {
        Queue::fake();

        $run = $this->makeRun(['collect_coverage' => false]);

        app(TestRunService::class)->finalizeRun($run);

        Queue::assertNotPushed(GenerateRunCoverageReportJob::class);
    }

    // ─── Report generation ──────────────────────────────────────────────────

    public function test_coverage_report_job_waits_for_incomplete_results(): void
    {
        $run = $this->makeRun(['collect_coverage' => true]);
        $run->testResults()->update(['status' => 'running', 'completed_at' => null]);

        // Simulate the batch finally firing while a timed-out worker has not
        // closed its result row yet: the job must not aggregate anything.
        (new GenerateRunCoverageReportJob($run->refresh()))
            ->handle(app(CoverageService::class));

        $this->assertNull($run->refresh()->coverage_summary);
    }

    public function test_coverage_report_job_merges_and_persists_report(): void
    {
        $run = $this->makeRun(['collect_coverage' => true]);
        $run->testResults()->update(['status' => 'passed', 'completed_at' => now()]);
        $run->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $result = $run->testResults()->first();
        Storage::disk('coverage')->put(
            app(CoverageService::class)->resultPath($run->test_suite_id, $run->id, $result->test_id),
            json_encode($this->coverageFixture())
        );

        (new GenerateRunCoverageReportJob($run->refresh()))
            ->handle(app(CoverageService::class));

        $run = $run->refresh();

        $this->assertNotNull($run->coverage_summary);
        $this->assertIsNumeric($run->coverage_summary['lines']['pct']);
        $this->assertIsNumeric($run->coverage_summary['functions']['pct']);
        $this->assertIsNumeric($run->coverage_summary['branches']['pct']);

        Storage::disk('coverage')->assertExists(
            app(CoverageService::class)->reportPath($run)
        );
        Storage::disk('coverage')->assertExists(
            app(CoverageService::class)->lcovPath($run)
        );
    }

    public function test_coverage_report_job_without_files_sets_no_summary(): void
    {
        $run = $this->makeRun(['collect_coverage' => true]);
        $run->testResults()->update(['status' => 'passed', 'completed_at' => now()]);
        $run->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        (new GenerateRunCoverageReportJob($run->refresh()))
            ->handle(app(CoverageService::class));

        $this->assertNull($run->refresh()->coverage_summary);
    }

    // ─── Serving: authenticated routes ──────────────────────────────────────

    public function test_coverage_report_requires_suite_view_access(): void
    {
        $run = $this->makeRun(['collect_coverage' => true]);
        $run->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        $run->testResults()->update(['status' => 'passed', 'completed_at' => now()]);

        $coverage = app(CoverageService::class);
        Storage::disk('coverage')->put(
            $coverage->reportPath($run),
            '<html>report</html>'
        );
        Storage::disk('coverage')->put(
            $coverage->lcovPath($run),
            'TN:/app.js'
        );

        // Unauthenticated requests are bounced to the login page.
        $this->get("/sorify/runs/{$run->id}/coverage/report")->assertRedirect('/sorify/login');
        $this->get("/sorify/runs/{$run->id}/coverage/lcov")->assertRedirect('/sorify/login');

        $member = $run->testSuite->members()->first() ?? User::factory()->admin()->create();

        // The bare report URL redirects to the canonical index.html so the
        // report's relative asset links resolve inside the report directory.
        $this->actingAs($member)
            ->get("/sorify/runs/{$run->id}/coverage/report")
            ->assertRedirect("/sorify/runs/{$run->id}/coverage/report/index.html");

        $this->actingAs($member)
            ->get("/sorify/runs/{$run->id}/coverage/report/index.html")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=utf-8');

        // Report assets are served from inside the report directory.
        Storage::disk('coverage')->put(
            app(CoverageService::class)->runDir($run->test_suite_id, $run->id).'/report/base.css',
            'body {}'
        );

        // Content-Type is forced by extension — finfo sniffing misidentifies
        // CSS/JS under FrankenPHP and browsers refuse to load them.
        $this->actingAs($member)
            ->get("/sorify/runs/{$run->id}/coverage/report/base.css")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/css; charset=utf-8');

        $this->actingAs($member)
            ->get("/sorify/runs/{$run->id}/coverage/lcov")
            ->assertOk();

        // Path traversal is not served.
        $this->actingAs($member)
            ->get("/sorify/runs/{$run->id}/coverage/report/../../.env")
            ->assertNotFound();
    }

    public function test_coverage_report_serves_files_with_query_string_names(): void
    {
        // Istanbul names per-file pages after the script's full URL including
        // its query string (e.g. "hn.js?Ro13umrbtHiT536hT1ka.html"). Browsers
        // send that "?" suffix as the request's query string, so the
        // controller reattaches it when resolving the file.
        $run = $this->makeRun(['collect_coverage' => true]);
        $coverage = app(CoverageService::class);

        Storage::disk('coverage')->put(
            $coverage->runDir($run->test_suite_id, $run->id).'/report/hn.js?Ro13umrbtHiT536hT1ka.html',
            '<html>file page</html>'
        );

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get("/sorify/runs/{$run->id}/coverage/report/hn.js?Ro13umrbtHiT536hT1ka.html")
            ->assertOk();
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function makeRun(array $suiteAttrs = []): TestRun
    {
        $admin = User::factory()->admin()->create();

        $suite = TestSuite::create(array_merge([
            'name' => 'Suite',
            'base_url' => 'https://example.com',
            'created_by' => $admin->id,
        ], $suiteAttrs));

        $test = $suite->tests()->create(['name' => 'Test', 'playwright_code' => 'code', 'status' => 'active']);

        $run = $suite->testRuns()->create([
            'status' => 'running',
            'triggered_by' => 'manual',
            'total_tests' => 1,
            'started_at' => now(),
        ]);

        TestResult::create([
            'test_run_id' => $run->id,
            'test_id' => $test->id,
            'status' => 'passed',
            'completed_at' => now(),
        ]);

        return $run->refresh();
    }

    /**
     * A minimal, valid Chromium V8 coverage payload for a small script with
     * one called function (add) and one uncalled function (unused) — enough
     * for v8-to-istanbul to produce lines/functions/branches data offline.
     */
    private function coverageFixture(): array
    {
        $source = "function add(a, b) {\n  return a + b;\n}\n\nfunction unused() {\n  return 2;\n}\n\nadd(1, 2);\n";

        $unusedStart = strpos($source, 'function unused');
        $unusedEnd = strpos($source, '}', $unusedStart) + 1;

        return [
            'url' => 'https://example.com',
            'entries' => [
                [
                    'url' => 'https://example.com/app.js',
                    'scriptId' => '1',
                    'source' => $source,
                    'functions' => [
                        [
                            'functionName' => '',
                            'ranges' => [['startOffset' => 0, 'endOffset' => strlen($source), 'count' => 1]],
                            'isBlockCoverage' => true,
                        ],
                        [
                            'functionName' => 'add',
                            'ranges' => [['startOffset' => 0, 'endOffset' => strpos($source, '}') + 1, 'count' => 1]],
                            'isBlockCoverage' => true,
                        ],
                        [
                            'functionName' => 'unused',
                            'ranges' => [['startOffset' => $unusedStart, 'endOffset' => $unusedEnd, 'count' => 0]],
                            'isBlockCoverage' => true,
                        ],
                    ],
                ],
            ],
        ];
    }
}
