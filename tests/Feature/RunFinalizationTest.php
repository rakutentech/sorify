<?php

namespace Tests\Feature;

use App\Events\TestRunCompleted;
use App\Listeners\NotifyTeamsOnRunCompleted;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Services\TestRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
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

    public function test_finalize_run_fires_http_post_run_integration(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response(status: 200)]);

        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $test = $suite->tests()->create(['name' => 'Test', 'playwright_code' => 'code', 'status' => 'active']);
        $run = $suite->testRuns()->create([
            'status' => 'running',
            'triggered_by' => 'manual',
            'total_tests' => 1,
            'started_at' => now(),
        ]);
        TestResult::create(['test_run_id' => $run->id, 'test_id' => $test->id, 'status' => 'passed']);
        $suite->integrations()->create([
            'type' => 'http_request',
            'config' => [
                'url' => 'https://hooks.example.com/notify?src=sorify',
                'method' => 'POST',
                'inputs' => ['api_key' => 'key-123'],
                'headers' => ['X-Source' => 'sorify'],
            ],
            'enabled' => true,
            'trigger_after' => true,
        ]);

        app(TestRunService::class)->finalizeRun($run->refresh());

        Http::assertSent(function ($request) {
            $body = json_decode((string) $request->body(), true);

            // Query params kept, inputs in the JSON body, headers sent, run
            // outcome injected as context.
            return $request->url() === 'https://hooks.example.com/notify?src=sorify'
                && $request->hasHeader('X-Source', 'sorify')
                && ($body['api_key'] ?? null) === 'key-123'
                && ($body['sorify_run_status'] ?? null) === 'completed';
        });
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
        // the Teams notification. Guard against that regression here —
        // scoped to the Teams listener since other listeners (e.g. post-run
        // integration dispatch) also subscribe to this event.
        $teamsListeners = collect(app('events')->getListeners(TestRunCompleted::class))
            ->filter(function ($listener) {
                if (! $listener instanceof \Closure) {
                    return false;
                }

                $target = (new \ReflectionFunction($listener))->getStaticVariables()['listener'] ?? null;

                return is_string($target) && str_starts_with($target, NotifyTeamsOnRunCompleted::class);
            });

        $this->assertCount(1, $teamsListeners);
    }
}
