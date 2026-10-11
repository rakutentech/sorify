<?php

namespace Tests\Feature;

use App\Events\TestRunGroupCompleted;
use App\Events\TestRunStarted;
use App\Models\TestSuite;
use App\Services\TestRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Notifications are consolidated per trigger: every run of a multi-browser
 * fan-out shares a fan-out id, and the group gets ONE "started" message
 * plus ONE combined result message (fired by the last sibling to finish)
 * instead of one pair per browser. Runs without a fan-out id (rows created
 * outside the run service) keep the per-run notifications.
 */
class FanOutNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function suite(array $attrs = []): TestSuite
    {
        $suite = TestSuite::create(array_merge([
            'name' => 'Suite',
            'base_url' => 'https://example.com',
            'browser' => 'chromium,firefox,webkit',
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_start' => true,
            'teams_notify_on_success' => true,
        ], $attrs));

        $suite->tests()->create(['name' => 'Test', 'playwright_code' => '// noop', 'status' => 'active']);

        return $suite;
    }

    public function test_runs_of_one_trigger_share_a_fan_out_id(): void
    {
        Queue::fake();

        $suite = $this->suite();
        app(TestRunService::class)->triggerRun($suite, null, 'manual');

        $ids = $suite->testRuns()->pluck('fan_out_id')->unique();

        $this->assertSame(3, $suite->testRuns()->count());
        $this->assertSame(1, $ids->count());
        $this->assertNotNull($ids->first());
    }

    public function test_one_started_message_per_trigger(): void
    {
        Queue::fake();
        Event::fake([TestRunStarted::class]);

        $suite = $this->suite();
        app(TestRunService::class)->triggerRun($suite, null, 'manual');

        Event::assertDispatchedTimes(TestRunStarted::class, 1);
    }

    public function test_group_completed_fires_once_after_the_last_sibling_finalizes(): void
    {
        Queue::fake();
        Event::fake([TestRunGroupCompleted::class]);

        $suite = $this->suite();
        app(TestRunService::class)->triggerRun($suite, null, 'manual');

        $runs = $suite->testRuns()->orderBy('id')->get();
        $service = app(TestRunService::class);

        $service->finalizeRun($runs[0]);
        $service->finalizeRun($runs[1]);
        Event::assertNotDispatched(TestRunGroupCompleted::class);

        $service->finalizeRun($runs[2]);
        Event::assertDispatchedTimes(TestRunGroupCompleted::class, 1);
        Event::assertDispatched(function (TestRunGroupCompleted $event) {
            return $event->runs->count() === 3;
        });
    }

    public function test_single_browser_triggers_notify_as_a_one_run_group(): void
    {
        Queue::fake();
        Event::fake([TestRunGroupCompleted::class]);

        $suite = $this->suite(['browser' => 'chromium']);
        app(TestRunService::class)->triggerRun($suite, null, 'manual');

        $service = app(TestRunService::class);
        $service->finalizeRun($suite->testRuns()->first());

        Event::assertDispatchedTimes(TestRunGroupCompleted::class, 1);
    }

    public function test_legacy_runs_without_a_fan_out_dispatch_no_group_event(): void
    {
        Queue::fake();
        Event::fake([TestRunGroupCompleted::class]);

        $suite = $this->suite(['browser' => 'chromium']);
        $run = $suite->testRuns()->create([
            'triggered_by' => 'manual',
            'status' => 'running',
            'started_at' => now(),
        ]);

        app(TestRunService::class)->finalizeRun($run);

        Event::assertNotDispatched(TestRunGroupCompleted::class);
    }

    public function test_a_groups_reported_runs_are_not_redigested_by_the_next_trigger(): void
    {
        Queue::fake();
        Http::fake(['outlook.webhook.example/*' => Http::response([], 200)]);

        $suite = $this->suite(['teams_notification_cooldown_minutes' => 1]);
        $service = app(TestRunService::class);

        // Trigger A: one started card + one combined completion card.
        $service->triggerRun($suite, null, 'manual');

        foreach ($suite->testRuns()->orderBy('id')->get() as $run) {
            $service->finalizeRun($run);
        }

        Http::assertSentCount(2);

        // Once the cooldown window expires, the next trigger must NOT
        // re-digest the runs A already reported: only B's own started
        // card follows, no stale summary of A's browser runs.
        Carbon::setTestNow(now()->addMinutes(5));

        $service->triggerRun($suite, null, 'manual');

        Http::assertSentCount(3);
    }

    public function test_no_digest_is_posted_when_the_cooldown_is_off_even_with_stale_stamps(): void
    {
        Queue::fake();
        Http::fake(['outlook.webhook.example/*' => Http::response([], 200)]);

        $suite = $this->suite();
        $service = app(TestRunService::class);

        // Stale stamps left in the database by a cooldown that was later
        // disabled (written via a direct update — the model now clears
        // them on save, but legacy rows still carry them).
        TestSuite::whereKey($suite->id)->update([
            'teams_last_notified_at' => now()->subDays(3),
            'teams_last_notified_run_id' => null,
        ]);
        $suite->refresh();

        // A completed run from before the cooldown was disabled.
        $old = $suite->testRuns()->create([
            'triggered_by' => 'manual',
            'status' => 'completed',
            'started_at' => now()->subDays(3),
            'completed_at' => now()->subDays(3)->addMinutes(10),
        ]);

        $service->triggerRun($suite, null, 'manual');

        // Only the started card — no digest of the old run.
        Http::assertSentCount(1);

        foreach ($suite->testRuns()->whereNotNull('fan_out_id')->orderBy('id')->get() as $run) {
            $service->finalizeRun($run);
        }

        // The combined card for this trigger — still no digest of old runs.
        Http::assertSentCount(2);
    }

    public function test_disabling_the_cooldown_clears_stale_stamps(): void
    {
        $suite = $this->suite([
            'teams_notification_cooldown_minutes' => 1,
            'email_notification_cooldown_minutes' => 1,
        ]);

        TestSuite::whereKey($suite->id)->update([
            'teams_last_notified_at' => now()->subDays(3),
            'teams_last_notified_run_id' => null,
            'email_last_notified_at' => now()->subDays(3),
            'email_last_notified_run_id' => null,
        ]);

        $suite->refresh();
        $suite->update(['teams_notification_cooldown_minutes' => 0]);

        $this->assertNull($suite->refresh()->teams_last_notified_at);
        $this->assertNull($suite->refresh()->teams_last_notified_run_id);

        // The email channel keeps its stamps while its cooldown is on.
        $this->assertNotNull($suite->refresh()->email_last_notified_at);

        $suite->update(['email_notification_cooldown_minutes' => 0]);

        $this->assertNull($suite->refresh()->email_last_notified_at);
        $this->assertNull($suite->refresh()->email_last_notified_run_id);
    }

    public function test_runs_older_than_the_window_span_are_not_redigested(): void
    {
        Queue::fake();
        Http::fake(['outlook.webhook.example/*' => Http::response([], 200)]);

        $suite = $this->suite(['teams_notification_cooldown_minutes' => 1]);

        // Stamps from three days ago, plus a run that completed hours
        // after that window had already expired — it had its own
        // notification back then and must never be re-digested.
        TestSuite::whereKey($suite->id)->update([
            'teams_last_notified_at' => now()->subDays(3),
            'teams_last_notified_run_id' => null,
        ]);
        $suite->refresh();

        $suite->testRuns()->create([
            'triggered_by' => 'manual',
            'status' => 'completed',
            'started_at' => now()->subDays(3)->addHours(2),
            'completed_at' => now()->subDays(3)->addHours(3),
        ]);

        app(TestRunService::class)->triggerRun($suite, null, 'manual');

        Http::assertSentCount(1);
    }

    public function test_a_trigger_posts_one_started_and_one_combined_teams_message(): void
    {
        Queue::fake();
        Http::fake(['outlook.webhook.example/*' => Http::response([], 200)]);

        $suite = $this->suite();
        app(TestRunService::class)->triggerRun($suite, null, 'manual');

        // One "started" card for the whole trigger.
        Http::assertSentCount(1);

        $runs = $suite->testRuns()->orderBy('id')->get();
        $service = app(TestRunService::class);

        // Sibling runs finalizing do not post individual result cards.
        $service->finalizeRun($runs[0]);
        $service->finalizeRun($runs[1]);
        Http::assertSentCount(1);

        // The last sibling posts exactly one combined card listing every
        // browser of the fan-out.
        $service->finalizeRun($runs[2]);
        Http::assertSentCount(2);

        Http::assertSent(function ($request) {
            $payload = json_encode($request->data());

            return str_contains($payload, '3 browsers')
                && str_contains($payload, 'Chromium')
                && str_contains($payload, 'Firefox')
                && str_contains($payload, 'WebKit');
        });
    }
}
