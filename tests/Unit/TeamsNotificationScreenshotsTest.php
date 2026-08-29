<?php

namespace Tests\Unit;

use App\Models\Screenshot;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Services\TeamsNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TeamsNotificationScreenshotsTest extends TestCase
{
    use RefreshDatabase;

    private function makeRunWithScreenshots(int $maxScreenshots): TestRun
    {
        config(['sorify.teams_max_screenshots' => $maxScreenshots]);

        $suite = TestSuite::create([
            'name' => 'Suite',
            'base_url' => 'https://example.com',
            'teams_webhook_url' => 'https://outlook.office.com/webhook/fake',
            'teams_notify_on_success' => true,
            'teams_notify_on_failure' => true,
        ]);
        $run = $suite->testRuns()->create([
            'status' => 'completed',
            'triggered_by' => 'manual',
            'total_tests' => 2,
            'passed_count' => 1,
            'failed_count' => 1,
        ]);
        $passedTest = $suite->tests()->create(['name' => 'Passing', 'playwright_code' => 'code', 'status' => 'active']);
        $failedTest = $suite->tests()->create(['name' => 'Failing', 'playwright_code' => 'code', 'status' => 'active']);

        $passedResult = TestResult::create(['test_run_id' => $run->id, 'test_id' => $passedTest->id, 'status' => 'passed']);
        $failedResult = TestResult::create(['test_run_id' => $run->id, 'test_id' => $failedTest->id, 'status' => 'failed']);

        Storage::disk('screenshots')->put('passed.png', 'bytes');
        Storage::disk('screenshots')->put('failed.png', 'bytes');

        Screenshot::create(['test_result_id' => $passedResult->id, 'filename' => 'passed.png', 'path' => 'passed.png', 'taken_at_ms' => 1]);
        Screenshot::create(['test_result_id' => $failedResult->id, 'filename' => 'failed.png', 'path' => 'failed.png', 'taken_at_ms' => 1]);

        return $run;
    }

    /**
     * The TextBlock that immediately follows the "Screenshots" heading
     * TextBlock — i.e. the one carrying the markdown links.
     */
    private function screenshotsTextBlock(array $body): ?array
    {
        $foundHeading = false;
        foreach ($body as $element) {
            $type = $element['type'] ?? '';
            if ($foundHeading && $type === 'TextBlock') {
                return $element;
            }
            if ($type === 'TextBlock' && ($element['text'] ?? '') === 'Screenshots') {
                $foundHeading = true;
            }
        }

        return null;
    }

    public function test_it_renders_screenshots_as_markdown_links_not_action_buttons(): void
    {
        Storage::fake('screenshots');
        Http::fake();

        $run = $this->makeRunWithScreenshots(maxScreenshots: 3);

        app(TeamsNotificationService::class)->notifyRunCompleted($run);

        Http::assertSent(function ($request) {
            $body = $request['attachments'][0]['content']['body'];

            // No ActionSet anywhere — screenshots are now links, not buttons.
            if (collect($body)->contains(fn ($el) => ($el['type'] ?? '') === 'ActionSet')) {
                return false;
            }

            $textBlock = $this->screenshotsTextBlock($body);
            if (! $textBlock) {
                return false;
            }

            $text = $textBlock['text'];

            // Both screenshots are present as markdown links pointing at the
            // screenshots route. No "+N more" line because nothing was capped.
            return str_contains($text, '[Passing](')
                && str_contains($text, '[Failing](')
                && str_contains($text, '/screenshots/')
                && ! str_contains($text, 'more]');
        });
    }

    public function test_it_prioritizes_failed_screenshots_when_capped(): void
    {
        Storage::fake('screenshots');
        Http::fake();

        $run = $this->makeRunWithScreenshots(maxScreenshots: 1);

        app(TeamsNotificationService::class)->notifyRunCompleted($run);

        Http::assertSent(function ($request) {
            $body = $request['attachments'][0]['content']['body'];
            $textBlock = $this->screenshotsTextBlock($body);

            if (! $textBlock) {
                return false;
            }

            $text = $textBlock['text'];

            // Only one screenshot shown, and it's the failed one (sorted first).
            return str_contains($text, '[Failing](')
                && ! str_contains($text, '[Passing](');
        });
    }

    public function test_it_adds_more_link_to_run_when_screenshots_are_capped(): void
    {
        Storage::fake('screenshots');
        Http::fake();

        $run = $this->makeRunWithScreenshots(maxScreenshots: 1);

        app(TeamsNotificationService::class)->notifyRunCompleted($run);

        // Compare on the route path (scheme/host can differ between
        // route() and the service's absoluteUrl() helper in tests).
        $runPath = route('runs.show', $run, absolute: false);

        Http::assertSent(function ($request) use ($runPath) {
            $body = $request['attachments'][0]['content']['body'];
            $textBlock = $this->screenshotsTextBlock($body);

            if (! $textBlock) {
                return false;
            }

            $text = $textBlock['text'];

            // "+1 more" link pointing back at the run page.
            return str_contains($text, '[+1 more](')
                && str_contains($text, $runPath);
        });
    }

    public function test_summary_uses_pass_fraction_format(): void
    {
        Storage::fake('screenshots');
        Http::fake();

        $run = $this->makeRunWithScreenshots(maxScreenshots: 5);

        app(TeamsNotificationService::class)->notifyRunCompleted($run);

        Http::assertSent(function ($request) {
            $body = $request['attachments'][0]['content']['body'];
            $text = collect($body)->pluck('text')->implode("\n");

            return str_contains($text, '1/2 passed')
                && str_contains($text, '1 failed')
                && str_contains($text, '0 errors')
                && ! str_contains($text, 'Passed:');
        });
    }

    public function test_summary_includes_triggered_by_user_name(): void
    {
        Storage::fake('screenshots');
        Http::fake();

        $run = $this->makeRunWithScreenshots(maxScreenshots: 5);
        $run->triggeredByUser()->associate(\App\Models\User::factory()->create(['name' => 'Alice']))->save();

        app(TeamsNotificationService::class)->notifyRunCompleted($run);

        Http::assertSent(function ($request) {
            $body = $request['attachments'][0]['content']['body'];
            $text = collect($body)->pluck('text')->implode("\n");

            return str_contains($text, 'Triggered by: Alice');
        });
    }

    public function test_summary_falls_back_to_source_label_for_ci_runs(): void
    {
        Storage::fake('screenshots');
        Http::fake();

        $run = $this->makeRunWithScreenshots(maxScreenshots: 5);
        $run->update(['triggered_by' => 'ci', 'triggered_by_user_id' => null]);

        app(TeamsNotificationService::class)->notifyRunCompleted($run);

        Http::assertSent(function ($request) {
            $body = $request['attachments'][0]['content']['body'];
            $text = collect($body)->pluck('text')->implode("\n");

            return str_contains($text, 'Triggered by: CI Webhook');
        });
    }
}
