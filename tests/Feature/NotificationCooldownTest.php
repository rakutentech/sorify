<?php

namespace Tests\Feature;

use App\Events\TestRunCompleted;
use App\Events\TestRunStarted;
use App\Mail\RunCompletedEmail;
use App\Mail\RunDigestEmail;
use App\Mail\RunStartedEmail;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\TestRunService;
use App\Services\TestSuiteDuplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationCooldownTest extends TestCase
{
    use RefreshDatabase;

    private function suite(array $attrs = []): TestSuite
    {
        $suite = TestSuite::create(array_merge([
            'name' => 'Suite',
            'base_url' => 'https://example.com',
            'browser' => 'chromium',
            'headless' => true,
            'teams_notify_on_start' => false,
            'teams_notify_on_success' => false,
            'teams_notify_on_failure' => false,
            'email_notify_on_start' => false,
            'email_notify_on_success' => false,
            'email_notify_on_failure' => false,
        ], $attrs));

        // last_notified_at columns are internal (not mass-assignable).
        $stamps = [];
        foreach (['teams_last_notified_at', 'email_last_notified_at'] as $column) {
            if (array_key_exists($column, $attrs)) {
                $stamps[$column] = $attrs[$column];
            }
        }
        if ($stamps) {
            $suite->forceFill($stamps)->save();
        }

        return $suite;
    }

    private function completedRun(TestSuite $suite, array $attrs = []): TestRun
    {
        return $suite->testRuns()->create(array_merge([
            'triggered_by' => 'schedule',
            'status' => 'completed',
            'total_tests' => 1,
            'passed_count' => 1,
            'failed_count' => 0,
            'error_count' => 0,
            'duration_ms' => 1000,
            'started_at' => now()->subMinutes(2),
            'completed_at' => now(),
        ], $attrs));
    }

    //
    // MS Teams
    //

    public function test_teams_notification_within_cooldown_is_suppressed(): void
    {
        Queue::fake();
        Http::fake();

        $suite = $this->suite([
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_failure' => true,
            'teams_notification_cooldown_minutes' => 30,
            'teams_last_notified_at' => now()->subMinutes(5),
        ]);

        TestRunCompleted::dispatch($this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0]));

        Http::assertNothingSent();
        $this->assertEquals(now()->subMinutes(5)->timestamp, $suite->refresh()->teams_last_notified_at->timestamp);
    }

    public function test_teams_first_notification_sends_and_stamps_the_clock(): void
    {
        Queue::fake();
        Http::fake(['outlook.webhook.example/*' => Http::response([], 200)]);

        $suite = $this->suite([
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_failure' => true,
            'teams_notification_cooldown_minutes' => 30,
        ]);

        TestRunCompleted::dispatch($this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0]));

        Http::assertSent(function ($request) {
            return str_contains(json_encode($request->data()), 'Failure')
                && ! str_contains(json_encode($request->data()), 'Summary of');
        });
        $this->assertNotNull($suite->refresh()->teams_last_notified_at);
    }

    public function test_teams_run_start_is_suppressed_within_cooldown(): void
    {
        Queue::fake();
        Http::fake();

        $suite = $this->suite([
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_start' => true,
            'teams_notification_cooldown_minutes' => 30,
            'teams_last_notified_at' => now()->subMinutes(5),
        ]);
        $suite->tests()->create(['name' => 'Test', 'playwright_code' => '// noop', 'status' => 'active']);

        app(TestRunService::class)->triggerRun($suite, null, 'manual');

        Http::assertNothingSent();
    }

    public function test_teams_notification_after_expiry_sends_digest_of_held_back_runs(): void
    {
        Queue::fake();
        Http::fake(['outlook.webhook.example/*' => Http::response([], 200)]);

        $suite = $this->suite([
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_failure' => true,
            'teams_notification_cooldown_minutes' => 30,
            'teams_last_notified_at' => now()->subMinutes(35),
        ]);

        // Held back: completed inside the (now expired) window.
        $this->completedRun($suite, [
            'failed_count' => 1,
            'passed_count' => 0,
            'completed_at' => now()->subMinutes(10),
        ]);

        // New failure after expiry → one digest covering both runs.
        $run = $this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0]);

        TestRunCompleted::dispatch($run);

        Http::assertSent(function ($request) {
            $payload = json_encode($request->data());

            return str_contains($payload, 'Summary of 2 runs')
                && str_contains($payload, '0 succeeded')
                && str_contains($payload, '2 failed');
        });
        Http::assertSentCount(1);
    }

    public function test_teams_digest_excludes_runs_that_do_not_match_notify_flags(): void
    {
        Queue::fake();
        Http::fake(['outlook.webhook.example/*' => Http::response([], 200)]);

        $suite = $this->suite([
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_success' => false,
            'teams_notify_on_failure' => true,
            'teams_notification_cooldown_minutes' => 30,
            'teams_last_notified_at' => now()->subMinutes(35),
        ]);

        // Successful run inside the window — notify_on_success is off, so it
        // was never eligible and must not turn the next message into a digest.
        $this->completedRun($suite, ['completed_at' => now()->subMinutes(10)]);

        $run = $this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0]);

        TestRunCompleted::dispatch($run);

        Http::assertSent(function ($request) {
            $payload = json_encode($request->data());

            return str_contains($payload, 'Failure') && ! str_contains($payload, 'Summary of');
        });
    }

    public function test_teams_cooldown_disabled_sends_every_notification(): void
    {
        Queue::fake();
        Http::fake(['outlook.webhook.example/*' => Http::response([], 200)]);

        $suite = $this->suite([
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_failure' => true,
            'teams_notification_cooldown_minutes' => 0,
            'teams_last_notified_at' => now()->subMinutes(1),
        ]);

        TestRunCompleted::dispatch($this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0]));
        TestRunCompleted::dispatch($this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0]));

        Http::assertSentCount(2);
    }

    //
    // Email
    //

    private function suiteWithRecipient(array $attrs = []): array
    {
        $user = User::factory()->admin()->create();

        $suite = $this->suite(array_merge([
            'email_notify_on_failure' => true,
        ], $attrs));

        $recipient = User::factory()->create();
        $suite->members()->attach($recipient->id, [
            'can_view' => true, 'can_edit' => false, 'can_delete' => false, 'can_run' => false,
        ]);
        $suite->emailRecipients()->attach($recipient->id);

        return [$suite, $recipient];
    }

    public function test_email_notification_within_cooldown_is_suppressed(): void
    {
        Queue::fake();
        Mail::fake();

        [$suite] = $this->suiteWithRecipient([
            'email_notification_cooldown_minutes' => 30,
            'email_last_notified_at' => now()->subMinutes(5),
        ]);

        TestRunCompleted::dispatch($this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0]));

        Mail::assertNothingSent();
    }

    public function test_email_run_start_is_suppressed_within_cooldown(): void
    {
        Queue::fake();
        Mail::fake();

        [$suite] = $this->suiteWithRecipient([
            'email_notify_on_start' => true,
            'email_notify_on_failure' => false,
            'email_notification_cooldown_minutes' => 30,
            'email_last_notified_at' => now()->subMinutes(5),
        ]);
        $suite->tests()->create(['name' => 'Test', 'playwright_code' => '// noop', 'status' => 'active']);

        app(TestRunService::class)->triggerRun($suite, null, 'manual');

        Mail::assertNothingSent();
    }

    public function test_email_after_expiry_sends_digest_of_held_back_runs(): void
    {
        Queue::fake();
        Mail::fake();

        [$suite, $recipient] = $this->suiteWithRecipient([
            'email_notification_cooldown_minutes' => 30,
            'email_last_notified_at' => now()->subMinutes(35),
        ]);

        $heldRun = $this->completedRun($suite, [
            'failed_count' => 1,
            'passed_count' => 0,
            'completed_at' => now()->subMinutes(10),
        ]);
        $run = $this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0]);

        TestRunCompleted::dispatch($run);

        Mail::assertSent(RunDigestEmail::class, function (RunDigestEmail $mail) use ($recipient, $heldRun, $run) {
            $html = $mail->render();

            return $mail->hasTo($recipient->email)
                && str_contains($html, 'Summary of 2 runs')
                && str_contains($html, "Run #{$heldRun->id}")
                && str_contains($html, "Run #{$run->id}");
        });
        Mail::assertNotSent(RunCompletedEmail::class);
    }

    //
    // The run that opened the window
    //

    public function test_teams_run_that_opened_window_still_gets_its_result_message(): void
    {
        Queue::fake();
        Http::fake(['outlook.webhook.example/*' => Http::response([], 200)]);

        $suite = $this->suite([
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_success' => true,
            'teams_notification_cooldown_minutes' => 5,
        ]);

        $run = $this->completedRun($suite);
        $suite->forceFill([
            'teams_last_notified_at' => now()->subMinutes(2),
            'teams_last_notified_run_id' => $run->id,
        ])->save();

        TestRunCompleted::dispatch($run);

        Http::assertSent(function ($request) {
            $payload = json_encode($request->data());

            return str_contains($payload, 'Success') && ! str_contains($payload, 'Summary of');
        });
        Http::assertSentCount(1);

        // The exempt send must not re-anchor the window.
        $this->assertEquals(now()->subMinutes(2)->timestamp, $suite->refresh()->teams_last_notified_at->timestamp);
    }

    public function test_teams_exempt_result_message_does_not_swallow_held_back_runs(): void
    {
        Queue::fake();
        Http::fake(['outlook.webhook.example/*' => Http::response([], 200)]);

        $suite = $this->suite([
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_failure' => true,
            'teams_notification_cooldown_minutes' => 5,
        ]);

        $owner = $this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0, 'completed_at' => now()->subMinutes(8)]);
        $held = $this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0, 'completed_at' => now()->subMinutes(2)]);
        $suite->forceFill([
            'teams_last_notified_at' => now()->subMinutes(4),
            'teams_last_notified_run_id' => $owner->id,
        ])->save();

        // Owner's own result: individual message, no re-anchor.
        TestRunCompleted::dispatch($owner);
        Http::assertSentCount(1);

        // Window (5 min from the original anchor) expires.
        $this->travelTo(now()->addMinutes(2));

        $next = $this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0]);
        TestRunCompleted::dispatch($next);

        // Digest covers the held run + the new one — but NOT the owner's.
        Http::assertSent(function ($request) use ($held) {
            $payload = json_encode($request->data());

            return str_contains($payload, 'Summary of 2 runs')
                && str_contains($payload, "Run #{$held->id}")
                && ! str_contains($payload, 'Summary of 3 runs');
        });
    }

    public function test_teams_start_after_expiry_flushes_held_back_results_first(): void
    {
        Queue::fake();
        Http::fake(['outlook.webhook.example/*' => Http::response([], 200)]);

        $suite = $this->suite([
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_start' => true,
            'teams_notify_on_failure' => true,
            'teams_notification_cooldown_minutes' => 30,
            'teams_last_notified_at' => now()->subMinutes(35),
        ]);

        $this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0, 'completed_at' => now()->subMinutes(10)]);

        $starting = $suite->testRuns()->create([
            'triggered_by' => 'manual',
            'status' => 'running',
            'total_tests' => 1,
            'started_at' => now(),
        ]);

        TestRunStarted::dispatch($starting);

        // Digest of the held-back result first, then the start message.
        Http::assertSentCount(2);
        [$digest, $start] = Http::recorded()->map(fn ($pair) => $pair[0])->all();
        $this->assertTrue(str_contains(json_encode($digest->data()), 'Summary of 1 runs'));
        $this->assertTrue(str_contains(json_encode($start->data()), 'Run started'));
    }

    public function test_email_run_that_opened_window_still_gets_its_result_message(): void
    {
        Queue::fake();
        Mail::fake();

        [$suite, $recipient] = $this->suiteWithRecipient([
            'email_notify_on_success' => true,
            'email_notify_on_failure' => false,
            'email_notification_cooldown_minutes' => 5,
        ]);

        $run = $this->completedRun($suite);
        $suite->forceFill([
            'email_last_notified_at' => now()->subMinutes(2),
            'email_last_notified_run_id' => $run->id,
        ])->save();

        TestRunCompleted::dispatch($run);

        Mail::assertSent(RunCompletedEmail::class, fn (RunCompletedEmail $mail) => $mail->hasTo($recipient->email));
        Mail::assertNotSent(RunDigestEmail::class);
        $this->assertEquals(now()->subMinutes(2)->timestamp, $suite->refresh()->email_last_notified_at->timestamp);
    }

    //
    // Scheduled digest flush
    //

    public function test_teams_digest_is_flushed_after_window_expires_without_new_runs(): void
    {
        Queue::fake();
        Http::fake(['outlook.webhook.example/*' => Http::response([], 200)]);

        $suite = $this->suite([
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_failure' => true,
            'teams_notification_cooldown_minutes' => 5,
            'teams_last_notified_at' => now()->subMinutes(10),
        ]);

        $held = $this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0, 'completed_at' => now()->subMinutes(8)]);

        $this->artisan('sorify:flush-notification-digests');

        Http::assertSent(function ($request) use ($held) {
            $payload = json_encode($request->data());

            return str_contains($payload, 'Summary of 1 runs')
                && str_contains($payload, "Run #{$held->id}");
        });
        Http::assertSentCount(1);

        // The flush re-anchors the window and clears the owning run.
        $suite = $suite->refresh();
        $this->assertEqualsWithDelta(now()->timestamp, $suite->teams_last_notified_at->timestamp, 2);
        $this->assertNull($suite->teams_last_notified_run_id);
    }

    public function test_teams_flush_skips_suites_with_open_window(): void
    {
        Queue::fake();
        Http::fake();

        $suite = $this->suite([
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_failure' => true,
            'teams_notification_cooldown_minutes' => 30,
            'teams_last_notified_at' => now()->subMinutes(5),
        ]);

        $this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0, 'completed_at' => now()->subMinutes(3)]);

        $this->artisan('sorify:flush-notification-digests');

        Http::assertNothingSent();
    }

    public function test_teams_flush_skips_suites_without_pending_runs(): void
    {
        Queue::fake();
        Http::fake();

        $suite = $this->suite([
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_failure' => true,
            'teams_notification_cooldown_minutes' => 5,
            'teams_last_notified_at' => now()->subMinutes(10),
        ]);

        $this->artisan('sorify:flush-notification-digests');

        Http::assertNothingSent();
    }

    public function test_email_digest_is_flushed_after_window_expires_without_new_runs(): void
    {
        Queue::fake();
        Mail::fake();

        [$suite, $recipient] = $this->suiteWithRecipient([
            'email_notification_cooldown_minutes' => 5,
            'email_last_notified_at' => now()->subMinutes(10),
        ]);

        $held = $this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0, 'completed_at' => now()->subMinutes(8)]);

        $this->artisan('sorify:flush-notification-digests');

        Mail::assertSent(RunDigestEmail::class, function (RunDigestEmail $mail) use ($recipient, $held) {
            return $mail->hasTo($recipient->email)
                && str_contains($mail->render(), 'Summary of 1 runs')
                && str_contains($mail->render(), "Run #{$held->id}");
        });
        Mail::assertSent(RunDigestEmail::class, 1);

        $suite = $suite->refresh();
        $this->assertEqualsWithDelta(now()->timestamp, $suite->email_last_notified_at->timestamp, 2);
        $this->assertNull($suite->email_last_notified_run_id);
    }

    //
    // Channel independence
    //

    public function test_teams_cooldown_does_not_suppress_email_and_vice_versa(): void
    {
        Queue::fake();
        Http::fake(['outlook.webhook.example/*' => Http::response([], 200)]);
        Mail::fake();

        [$suite] = $this->suiteWithRecipient([
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_failure' => true,
            'teams_notification_cooldown_minutes' => 30,
            'teams_last_notified_at' => now()->subMinutes(5),
            'email_notification_cooldown_minutes' => 0,
            'email_last_notified_at' => now()->subMinutes(5),
        ]);

        TestRunCompleted::dispatch($this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0]));

        // Email has no cooldown → still notified; Teams stays quiet.
        Http::assertNothingSent();
        Mail::assertSent(RunCompletedEmail::class);

        // Now the other way around: only Teams may send.
        Mail::fake();

        [$suite2] = $this->suiteWithRecipient([
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_failure' => true,
            'teams_notification_cooldown_minutes' => 0,
            'email_notification_cooldown_minutes' => 30,
            'email_last_notified_at' => now()->subMinutes(5),
        ]);

        TestRunCompleted::dispatch($this->completedRun($suite2, ['failed_count' => 1, 'passed_count' => 0]));

        Http::assertSent(function ($request) {
            return str_contains(json_encode($request->data()), 'Failure');
        });
        Mail::assertNothingSent();
    }

    //
    // Settings flow
    //

    public function test_cooldown_settings_can_be_saved_through_settings_update(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite();

        $this->actingAs($user)
            ->put("/sorify/suites/{$suite->id}", [
                'teams_notification_cooldown_minutes' => 30,
                'email_notification_cooldown_minutes' => 15,
            ])
            ->assertRedirect();

        $suite->refresh();
        $this->assertSame(30, $suite->teams_notification_cooldown_minutes);
        $this->assertSame(15, $suite->email_notification_cooldown_minutes);
    }

    public function test_cooldown_settings_reject_invalid_values(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite();

        $this->actingAs($user)
            ->put("/sorify/suites/{$suite->id}", [
                'teams_notification_cooldown_minutes' => 7,
            ])
            ->assertInvalid('teams_notification_cooldown_minutes');
    }

    public function test_duplication_copies_cooldown_settings_but_not_timestamps(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite([
            'description' => 'x',
            'playwright_proxy' => 'http://proxy.example.com:8080',
            'history_retention' => 5,
            'timeout_ms' => 30000,
            'max_retries' => 0,
            'take_screenshot' => 'enabled',
            'teams_notification_cooldown_minutes' => 30,
            'email_notification_cooldown_minutes' => 60,
            'teams_last_notified_at' => now()->subMinutes(5),
            'email_last_notified_at' => now()->subMinutes(5),
        ]);

        $clone = app(TestSuiteDuplicationService::class)->duplicate($suite, $user);

        $this->assertSame(30, $clone->teams_notification_cooldown_minutes);
        $this->assertSame(60, $clone->email_notification_cooldown_minutes);
        $this->assertNull($clone->teams_last_notified_at);
        $this->assertNull($clone->email_last_notified_at);
    }

    //
    // Cooling-off period info in the notifications
    //

    public function test_teams_completion_card_mentions_the_cooling_off_period(): void
    {
        Queue::fake();
        Http::fake(['outlook.webhook.example/*' => Http::response([], 200)]);

        $suite = $this->suite([
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_failure' => true,
            'teams_notification_cooldown_minutes' => 30,
        ]);

        TestRunCompleted::dispatch($this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0]));

        Http::assertSent(function ($request) {
            $payload = json_encode($request->data());

            return str_contains($payload, 'Failure')
                && str_contains($payload, 'held back for the next 30 minutes and combined into one summary (cooling-off period)');
        });
    }

    public function test_teams_start_card_mentions_the_cooling_off_period(): void
    {
        Queue::fake();
        Http::fake(['outlook.webhook.example/*' => Http::response([], 200)]);

        $suite = $this->suite([
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_start' => true,
            'teams_notification_cooldown_minutes' => 60,
        ]);
        $suite->tests()->create(['name' => 'Test', 'playwright_code' => '// noop', 'status' => 'active']);

        app(TestRunService::class)->triggerRun($suite, null, 'manual');

        Http::assertSent(function ($request) {
            $payload = json_encode($request->data());

            return str_contains($payload, 'Run started')
                && str_contains($payload, 'held back for the next 1 hour and combined into one summary (cooling-off period)');
        });
    }

    public function test_teams_card_omits_cooling_off_info_when_disabled(): void
    {
        Queue::fake();
        Http::fake(['outlook.webhook.example/*' => Http::response([], 200)]);

        $suite = $this->suite([
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_failure' => true,
            'teams_notification_cooldown_minutes' => 0,
        ]);

        TestRunCompleted::dispatch($this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0]));

        Http::assertSent(function ($request) {
            return ! str_contains(json_encode($request->data()), 'cooling-off period');
        });
    }

    public function test_teams_digest_mentions_the_cooling_off_period(): void
    {
        Queue::fake();
        Http::fake(['outlook.webhook.example/*' => Http::response([], 200)]);

        $suite = $this->suite([
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_failure' => true,
            'teams_notification_cooldown_minutes' => 30,
            'teams_last_notified_at' => now()->subMinutes(35),
        ]);

        $this->completedRun($suite, [
            'failed_count' => 1,
            'passed_count' => 0,
            'completed_at' => now()->subMinutes(10),
        ]);

        TestRunCompleted::dispatch($this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0]));

        Http::assertSent(function ($request) {
            $payload = json_encode($request->data());

            return str_contains($payload, 'Summary of 2 runs')
                && str_contains($payload, 'held back during the cooling-off period (30 minutes)');
        });
    }

    public function test_completion_email_mentions_the_cooling_off_period(): void
    {
        Queue::fake();
        Mail::fake();

        [$suite, $recipient] = $this->suiteWithRecipient([
            'email_notification_cooldown_minutes' => 240,
        ]);

        TestRunCompleted::dispatch($this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0]));

        Mail::assertSent(RunCompletedEmail::class, function (RunCompletedEmail $mail) use ($recipient) {
            return $mail->hasTo($recipient->email)
                && str_contains($mail->render(), 'held back for the next 4 hours and combined into one summary (cooling-off period)');
        });
    }

    public function test_start_email_mentions_the_cooling_off_period(): void
    {
        Queue::fake();
        Mail::fake();

        [$suite] = $this->suiteWithRecipient([
            'email_notify_on_start' => true,
            'email_notify_on_failure' => false,
            'email_notification_cooldown_minutes' => 15,
        ]);
        $suite->tests()->create(['name' => 'Test', 'playwright_code' => '// noop', 'status' => 'active']);

        app(TestRunService::class)->triggerRun($suite, null, 'manual');

        Mail::assertSent(RunStartedEmail::class, function (RunStartedEmail $mail) {
            return str_contains($mail->render(), 'held back for the next 15 minutes and combined into one summary (cooling-off period)');
        });
    }

    public function test_completion_email_omits_cooling_off_info_when_disabled(): void
    {
        Queue::fake();
        Mail::fake();

        [$suite] = $this->suiteWithRecipient([
            'email_notification_cooldown_minutes' => 0,
        ]);

        TestRunCompleted::dispatch($this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0]));

        Mail::assertSent(RunCompletedEmail::class, function (RunCompletedEmail $mail) {
            return ! str_contains($mail->render(), 'cooling-off period');
        });
    }

    public function test_digest_email_mentions_the_cooling_off_period(): void
    {
        Queue::fake();
        Mail::fake();

        [$suite] = $this->suiteWithRecipient([
            'email_notification_cooldown_minutes' => 30,
            'email_last_notified_at' => now()->subMinutes(35),
        ]);

        $this->completedRun($suite, [
            'failed_count' => 1,
            'passed_count' => 0,
            'completed_at' => now()->subMinutes(10),
        ]);

        TestRunCompleted::dispatch($this->completedRun($suite, ['failed_count' => 1, 'passed_count' => 0]));

        Mail::assertSent(RunDigestEmail::class, function (RunDigestEmail $mail) {
            return str_contains($mail->render(), 'Summary of 2 runs')
                && str_contains($mail->render(), 'held back during the cooling-off period (30 minutes)');
        });
    }
}
