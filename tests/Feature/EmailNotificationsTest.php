<?php

namespace Tests\Feature;

use App\Events\TestRunCompleted;
use App\Mail\RunCompletedEmail;
use App\Mail\RunStartedEmail;
use App\Models\Screenshot;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\TestRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function suiteWithMemberRecipient(array $suiteAttrs = [], array $recipientAttrs = []): array
    {
        $user = User::factory()->admin()->create();

        $suite = TestSuite::create(array_merge([
            'name' => 'Suite',
            'base_url' => 'https://example.com',
        ], $suiteAttrs));

        $recipient = User::factory()->create($recipientAttrs);
        $suite->members()->attach($recipient->id, [
            'can_view' => true, 'can_edit' => false, 'can_delete' => false, 'can_run' => false,
        ]);
        $suite->emailRecipients()->attach($recipient->id);

        return [$user, $suite, $recipient];
    }

    public function test_update_syncs_recipients_but_drops_non_members(): void
    {
        [$user, $suite, $recipient] = $this->suiteWithMemberRecipient();
        $outsider = User::factory()->create();

        $this->actingAs($user)
            ->put("/sorify/suites/{$suite->id}", [
                'email_notify_on_start' => true,
                'email_notify_on_success' => true,
                'email_notify_on_failure' => true,
                'email_recipient_ids' => [$recipient->id, $outsider->id],
            ])
            ->assertRedirect();

        $suite->refresh();
        $this->assertTrue($suite->email_notify_on_start);
        $this->assertTrue($suite->email_notify_on_success);
        $this->assertTrue($suite->email_notify_on_failure);
        $this->assertEquals([$recipient->id], $suite->emailRecipients()->allRelatedIds()->all());
    }

    public function test_removing_a_member_also_removes_them_as_a_recipient(): void
    {
        [$user, $suite, $recipient] = $this->suiteWithMemberRecipient();

        $this->actingAs($user)
            ->delete("/sorify/suites/{$suite->id}/users/{$recipient->id}")
            ->assertRedirect();

        $this->assertSame(0, $suite->emailRecipients()->count());
    }

    public function test_store_accepts_email_settings_and_narrows_recipients_to_members(): void
    {
        $user = User::factory()->admin()->create();
        $outsider = User::factory()->create();

        $this->actingAs($user)
            ->post('/sorify/suites', [
                'name' => 'Suite',
                'base_url' => 'https://example.com',
                'email_notify_on_start' => true,
                'email_notify_on_success' => true,
                'email_notify_on_failure' => true,
                'email_recipient_ids' => [$user->id, $outsider->id],
            ])
            ->assertRedirect();

        $suite = TestSuite::where('name', 'Suite')->first();
        $this->assertTrue($suite->email_notify_on_start);
        $this->assertTrue($suite->email_notify_on_success);
        $this->assertTrue($suite->email_notify_on_failure);
        // The creator is the only member at creation — the outsider is dropped.
        $this->assertEquals([$user->id], $suite->emailRecipients()->allRelatedIds()->all());
    }

    public function test_run_start_emails_recipients_when_enabled(): void
    {
        Queue::fake();
        Mail::fake();

        [, $suite, $recipient] = $this->suiteWithMemberRecipient(['email_notify_on_start' => true]);
        $suite->tests()->create(['name' => 'Test', 'playwright_code' => '// noop', 'status' => 'active']);

        app(TestRunService::class)->triggerRun($suite, null, 'manual');

        Mail::assertSent(RunStartedEmail::class, fn (RunStartedEmail $mail) => $mail->hasTo($recipient->email));
    }

    public function test_run_start_does_not_email_without_recipients(): void
    {
        Queue::fake();
        Mail::fake();

        $user = User::factory()->admin()->create();
        $suite = TestSuite::create([
            'name' => 'Suite',
            'base_url' => 'https://example.com',
            'email_notify_on_start' => true,
        ]);
        $suite->tests()->create(['name' => 'Test', 'playwright_code' => '// noop', 'status' => 'active']);

        app(TestRunService::class)->triggerRun($suite, null, 'manual');

        Mail::assertNothingSent();
    }

    public function test_run_start_does_not_email_when_flag_disabled(): void
    {
        Queue::fake();
        Mail::fake();

        [, $suite] = $this->suiteWithMemberRecipient(['email_notify_on_start' => false]);
        $suite->tests()->create(['name' => 'Test', 'playwright_code' => '// noop', 'status' => 'active']);

        app(TestRunService::class)->triggerRun($suite, null, 'manual');

        Mail::assertNothingSent();
    }

    public function test_completed_run_emails_recipients_with_inline_screenshots(): void
    {
        Queue::fake();
        Mail::fake();
        Storage::fake('screenshots');

        [, $suite, $recipient] = $this->suiteWithMemberRecipient(['email_notify_on_success' => true]);
        $test = $suite->tests()->create(['name' => 'Failing test', 'playwright_code' => '// noop', 'status' => 'active']);

        $run = $suite->testRuns()->create([
            'triggered_by' => 'schedule',
            'status' => 'completed',
            'total_tests' => 1,
            'passed_count' => 0,
            'failed_count' => 1,
            'error_count' => 0,
            'duration_ms' => 1234,
        ]);

        $result = TestResult::create([
            'test_run_id' => $run->id,
            'test_id' => $test->id,
            'status' => 'failed',
            'duration_ms' => 1234,
        ]);

        $path = "{$suite->id}/{$run->id}/{$test->id}/screenshot.png";
        Storage::disk('screenshots')->put($path, 'fake-image-bytes');

        Screenshot::create([
            'test_result_id' => $result->id,
            'filename' => 'screenshot.png',
            'path' => $path,
            'label' => 'after-click',
            'taken_at_ms' => 100,
        ]);

        TestRunCompleted::dispatch($run);

        // Failed run + notify_on_success → falls through to the failure flag,
        // which is off here, so nothing goes out.
        Mail::assertNothingSent();

        $suite->update(['email_notify_on_failure' => true]);

        // fresh() avoids the stale cached testSuite relation carrying the old flag.
        TestRunCompleted::dispatch($run->fresh());

        // The screenshots are embedded inline: render() swaps the cid: parts
        // for data URIs, which is what proves the images ride along in the email.
        Mail::assertSent(RunCompletedEmail::class, function (RunCompletedEmail $mail) use ($recipient) {
            $html = $mail->render();

            return $mail->hasTo($recipient->email)
                && str_contains($html, '<img')
                && str_contains($html, 'data:image/png;base64,')
                && str_contains($html, 'Failing test');
        });
    }

    public function test_completed_run_does_not_email_without_any_notify_flag(): void
    {
        Queue::fake();
        Mail::fake();

        [, $suite] = $this->suiteWithMemberRecipient([
            'email_notify_on_success' => false,
            'email_notify_on_failure' => false,
        ]);
        $test = $suite->tests()->create(['name' => 'Test', 'playwright_code' => '// noop', 'status' => 'active']);

        $run = $suite->testRuns()->create([
            'triggered_by' => 'schedule',
            'status' => 'completed',
            'total_tests' => 1,
            'passed_count' => 1,
            'failed_count' => 0,
            'error_count' => 0,
        ]);
        TestResult::create([
            'test_run_id' => $run->id,
            'test_id' => $test->id,
            'status' => 'passed',
        ]);

        TestRunCompleted::dispatch($run);

        Mail::assertNothingSent();
    }

    public function test_cancelled_run_is_never_emailed(): void
    {
        Queue::fake();
        Mail::fake();

        [, $suite] = $this->suiteWithMemberRecipient(['email_notify_on_failure' => true]);

        $run = $suite->testRuns()->create([
            'triggered_by' => 'manual',
            'status' => 'cancelled',
            'total_tests' => 0,
            'passed_count' => 0,
            'failed_count' => 0,
            'error_count' => 0,
        ]);

        TestRunCompleted::dispatch($run);

        Mail::assertNothingSent();
    }
}
