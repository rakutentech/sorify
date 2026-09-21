<?php

namespace Tests\Feature;

use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\CoverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneRunsTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(array $runAttrs = []): TestRun
    {
        $admin = User::factory()->admin()->create();

        $suite = TestSuite::create([
            'name' => 'Suite',
            'base_url' => 'https://example.com',
            'created_by' => $admin->id,
        ]);

        $test = $suite->tests()->create(['name' => 'A', 'playwright_code' => 'code', 'status' => 'active']);

        $run = $suite->testRuns()->create(array_merge([
            'status' => 'completed',
            'triggered_by' => 'mcp',
            'total_tests' => 1,
        ], $runAttrs));

        $result = TestResult::create([
            'test_run_id' => $run->id,
            'test_id' => $test->id,
            'status' => 'passed',
            'completed_at' => now(),
        ]);

        // Seed the artifact files the run owns.
        $coverage = app(CoverageService::class);
        Storage::disk('coverage')->put($coverage->runDir($suite->id, $run->id).'/report/index.html', '<html>');
        Storage::disk('screenshots')->put("{$suite->id}/{$run->id}/{$test->id}/shot.png", 'png');

        return $run->refresh();
    }

    public function test_it_deletes_old_runs_with_their_files_and_keeps_recent_ones(): void
    {
        Storage::fake('coverage');
        Storage::fake('screenshots');
        config(['sorify.run_retention_days' => 90]);

        $old = $this->makeRun(['created_at' => now()->subDays(91)]);
        $recent = $this->makeRun(['created_at' => now()->subDays(1)]);

        $this->artisan('sorify:prune-runs')->assertExitCode(0);

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);

        $coverage = app(CoverageService::class);
        Storage::disk('coverage')->assertMissing($coverage->runDir($old->test_suite_id, $old->id));
        Storage::disk('coverage')->assertExists($coverage->runDir($recent->test_suite_id, $recent->id));
        Storage::disk('screenshots')->assertMissing("{$old->test_suite_id}/{$old->id}");
        Storage::disk('screenshots')->assertExists("{$recent->test_suite_id}/{$recent->id}");
    }

    public function test_it_never_prunes_pending_or_running_runs_regardless_of_age(): void
    {
        Storage::fake('coverage');
        Storage::fake('screenshots');
        config(['sorify.run_retention_days' => 90]);

        $pending = $this->makeRun(['status' => 'pending', 'created_at' => now()->subDays(200)]);
        $running = $this->makeRun(['status' => 'running', 'created_at' => now()->subDays(200)]);

        $this->artisan('sorify:prune-runs')->assertExitCode(0);

        $this->assertModelExists($pending);
        $this->assertModelExists($running);
    }

    public function test_deleting_a_run_removes_coverage_and_screenshot_files(): void
    {
        Storage::fake('coverage');
        Storage::fake('screenshots');

        $admin = User::factory()->admin()->create();
        $run = $this->makeRun();
        $suiteId = $run->test_suite_id;
        $runId = $run->id;

        $response = $this->actingAs($admin)->delete("/sorify/runs/{$run->id}");

        $response->assertRedirect();
        $this->assertModelMissing($run);
        Storage::disk('coverage')->assertMissing("{$suiteId}/{$runId}");
        Storage::disk('screenshots')->assertMissing("{$suiteId}/{$runId}");
    }

    public function test_deleting_a_suite_removes_all_run_files(): void
    {
        Storage::fake('coverage');
        Storage::fake('screenshots');

        $admin = User::factory()->admin()->create();
        $runA = $this->makeRun();
        $runB = $runA->testSuite->testRuns()->create([
            'status' => 'completed',
            'triggered_by' => 'mcp',
            'total_tests' => 1,
        ]);
        $suiteId = $runA->test_suite_id;
        $suite = $runA->testSuite;

        $response = $this->actingAs($admin)->delete("/sorify/suites/{$suite->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('test_runs', ['test_suite_id' => $suiteId]);
        Storage::disk('coverage')->assertMissing("{$suiteId}/{$runA->id}");
        Storage::disk('coverage')->assertMissing("{$suiteId}/{$runB->id}");
        Storage::disk('screenshots')->assertMissing("{$suiteId}/{$runA->id}");
    }
}
