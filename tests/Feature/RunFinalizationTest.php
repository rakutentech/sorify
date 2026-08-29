<?php

namespace Tests\Feature;

use App\Events\TestRunCompleted;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Services\TestRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RunFinalizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(): TestRun
    {
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);

        $test = $suite->tests()->create(['name' => 'Test', 'playwright_code' => 'code', 'status' => 'active']);

        $run = $suite->testRuns()->create([
            'status' => 'running',
            'triggered_by' => 'manual',
            'total_tests' => 1,
            'started_at' => now(),
        ]);

        TestResult::create(['test_run_id' => $run->id, 'test_id' => $test->id, 'status' => 'passed']);

        return $run->refresh();
    }

    public function test_finalize_run_dispatches_completion_event_once(): void
    {
        Event::fake([TestRunCompleted::class]);

        $run = $this->makeRun();

        app(TestRunService::class)->finalizeRun($run);

        Event::assertDispatched(TestRunCompleted::class, fn (TestRunCompleted $e) => $e->testRun->is($run));
    }

    public function test_finalize_run_does_not_dispatch_twice_when_called_concurrently(): void
    {
        // Simulates two workers racing into finalizeRun with a stale (null
        // completed_at) view of the same run. The atomic conditional update
        // must ensure the completion event fires exactly once.
        Event::fake([TestRunCompleted::class]);

        $run = $this->makeRun();

        $service = app(TestRunService::class);

        // Both calls start from the same un-finalized state (neither sees the
        // other's write ahead of time), mimicking the batch finally callback
        // racing the failed-job handler.
        $service->finalizeRun($run);
        $service->finalizeRun($run);

        Event::assertDispatchedTimes(TestRunCompleted::class, 1);
    }

    public function test_finalize_run_skips_already_completed_run(): void
    {
        Event::fake([TestRunCompleted::class]);

        $run = $this->makeRun();
        $run->update(['completed_at' => now(), 'status' => 'completed']);

        app(TestRunService::class)->finalizeRun($run);

        Event::assertNotDispatched(TestRunCompleted::class);
    }

    public function test_completion_event_listener_is_registered_only_once(): void
    {
        // Laravel auto-discovers NotifyTeamsOnRunCompleted from app/Listeners.
        // Registering it again manually (e.g. via Event::listen) double-fires
        // the Teams notification. Guard against that regression here.
        $listeners = app('events')->getListeners(\App\Events\TestRunCompleted::class);

        $this->assertCount(1, $listeners);
    }
}
