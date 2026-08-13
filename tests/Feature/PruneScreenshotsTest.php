<?php

namespace Tests\Feature;

use App\Models\Screenshot;
use App\Models\TestResult;
use App\Models\TestSuite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneScreenshotsTest extends TestCase
{
    use RefreshDatabase;

    private function makeTestResult(): TestResult
    {
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $run = $suite->testRuns()->create(['status' => 'completed', 'triggered_by' => 'mcp']);
        $test = $suite->tests()->create(['name' => 'A', 'playwright_code' => 'code', 'status' => 'active']);

        return TestResult::create([
            'test_run_id' => $run->id,
            'test_id' => $test->id,
            'status' => 'passed',
        ]);
    }

    public function test_it_deletes_screenshots_older_than_retention_and_keeps_recent_ones(): void
    {
        Storage::fake('screenshots');
        config(['sorify.screenshot_retention_days' => 90]);

        $result = $this->makeTestResult();

        Storage::disk('screenshots')->put('old.png', 'old-bytes');
        $old = Screenshot::create([
            'test_result_id' => $result->id,
            'filename' => 'old.png',
            'path' => 'old.png',
            'taken_at_ms' => 100,
            'created_at' => now()->subDays(91),
        ]);

        Storage::disk('screenshots')->put('recent.png', 'recent-bytes');
        $recent = Screenshot::create([
            'test_result_id' => $result->id,
            'filename' => 'recent.png',
            'path' => 'recent.png',
            'taken_at_ms' => 200,
            'created_at' => now()->subDays(1),
        ]);

        $this->artisan('sorify:prune-screenshots')->assertExitCode(0);

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        Storage::disk('screenshots')->assertMissing('old.png');
        Storage::disk('screenshots')->assertExists('recent.png');
    }
}
