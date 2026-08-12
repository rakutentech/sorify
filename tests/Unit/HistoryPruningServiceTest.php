<?php

namespace Tests\Unit;

use App\Models\Screenshot;
use App\Models\TestResult;
use App\Models\TestSuite;
use App\Services\HistoryPruningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HistoryPruningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_test_history_keeps_only_the_newest_n_results(): void
    {
        Storage::fake('screenshots');

        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com', 'history_retention' => 3]);
        $test = $suite->tests()->create(['name' => 'A', 'playwright_code' => 'code', 'status' => 'active']);

        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $run = $suite->testRuns()->create(['status' => 'completed']);
            $result = TestResult::create([
                'test_run_id' => $run->id,
                'test_id'     => $test->id,
                'status'      => 'passed',
                'created_at'  => now()->addSeconds($i),
            ]);

            $path = "{$suite->id}/{$run->id}/{$test->id}/shot.png";
            Storage::disk('screenshots')->put($path, 'fake-image-bytes');

            Screenshot::create([
                'test_result_id' => $result->id,
                'filename'       => 'shot.png',
                'path'           => $path,
                'taken_at_ms'    => 0,
                'created_at'     => now(),
            ]);

            $results[] = $result;
        }

        $pruned = app(HistoryPruningService::class)->pruneTestHistory($test->fresh(), 3);

        $this->assertSame(2, $pruned);
        $this->assertSame(3, $test->testResults()->count());

        // Two oldest results (and their screenshots) are gone.
        $this->assertDatabaseMissing('test_results', ['id' => $results[0]->id]);
        $this->assertDatabaseMissing('test_results', ['id' => $results[1]->id]);
        $this->assertDatabaseMissing('screenshots', ['test_result_id' => $results[0]->id]);
        $this->assertDatabaseMissing('screenshots', ['test_result_id' => $results[1]->id]);
        Storage::disk('screenshots')->assertMissing("{$suite->id}/{$results[0]->test_run_id}/{$test->id}/shot.png");
        Storage::disk('screenshots')->assertMissing("{$suite->id}/{$results[1]->test_run_id}/{$test->id}/shot.png");

        // Three newest results (and their screenshots) remain.
        foreach ([2, 3, 4] as $i) {
            $this->assertDatabaseHas('test_results', ['id' => $results[$i]->id]);
            $this->assertDatabaseHas('screenshots', ['test_result_id' => $results[$i]->id]);
            Storage::disk('screenshots')->assertExists("{$suite->id}/{$results[$i]->test_run_id}/{$test->id}/shot.png");
        }
    }

    public function test_prune_test_history_is_a_no_op_when_within_the_limit(): void
    {
        Storage::fake('screenshots');

        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com', 'history_retention' => 5]);
        $test = $suite->tests()->create(['name' => 'A', 'playwright_code' => 'code', 'status' => 'active']);

        $run = $suite->testRuns()->create(['status' => 'completed']);
        TestResult::create(['test_run_id' => $run->id, 'test_id' => $test->id, 'status' => 'passed']);

        $pruned = app(HistoryPruningService::class)->pruneTestHistory($test->fresh(), 5);

        $this->assertSame(0, $pruned);
        $this->assertSame(1, $test->testResults()->count());
    }
}
