<?php

namespace App\Services;

use App\Mail\RunCompletedEmail;
use App\Mail\RunDigestEmail;
use App\Mail\RunStartedEmail;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Support\NotificationCooldown;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailNotificationService
{
    public function notifyRunStarted(TestRun $run): void
    {
        $suite = $run->testSuite;

        if (! $suite || ! $suite->email_notify_on_start) {
            return;
        }

        if (NotificationCooldown::shouldSuppress($suite, 'email', $run)) {
            return;
        }

        $recipients = $this->recipients($suite);

        if ($recipients->isEmpty()) {
            return;
        }

        // Results that piled up while the window was open are flushed here —
        // the fresh stamp below would otherwise hide them from later digests.
        $heldBack = NotificationCooldown::heldBackRuns($suite, 'email', $run);

        if ($heldBack->isNotEmpty()) {
            $this->send($recipients, new RunDigestEmail($suite, $heldBack), $run, 'digest');
        }

        $this->send($recipients, new RunStartedEmail($run), $run, 'start');

        NotificationCooldown::markNotified($suite, 'email', $run);
    }

    public function notifyRunCompleted(TestRun $run): void
    {
        $suite = $run->testSuite;

        if (! $suite || $run->status === 'cancelled') {
            return;
        }

        // A run aborted by a failing pre-run integration also has zero
        // failure counts — the status check keeps those out of "success".
        $isSuccess = $run->isSuccessful();

        if ($isSuccess && ! $suite->email_notify_on_success) {
            return;
        }

        if (! $isSuccess && ! $suite->email_notify_on_failure) {
            return;
        }

        if (NotificationCooldown::shouldSuppress($suite, 'email', $run)) {
            return;
        }

        $recipients = $this->recipients($suite);

        if ($recipients->isEmpty()) {
            return;
        }

        // The run that opened the window always gets its own result message.
        // Its send must not re-anchor the window (that would swallow runs
        // already held back), so no digest merge and no fresh stamp here.
        if (NotificationCooldown::ownsWindow($suite, 'email', $run)) {
            $this->send($recipients, new RunCompletedEmail($run, $isSuccess), $run, 'completion');

            return;
        }

        // The window expired (or this is the first notification): if runs
        // were held back while it was open, replace the individual message
        // with one digest covering all of them.
        $heldBack = NotificationCooldown::heldBackRuns($suite, 'email', $run);

        $mailable = $heldBack->isNotEmpty()
            ? new RunDigestEmail($suite, $heldBack->concat([$run]))
            : new RunCompletedEmail($run, $isSuccess);

        $this->send($recipients, $mailable, $run, $heldBack->isNotEmpty() ? 'digest' : 'completion');

        NotificationCooldown::markNotified($suite, 'email', $run);
    }

    /**
     * Scheduled digest flush: once a cooling-off window has expired, send
     * the runs it held back so results are delivered even when no further
     * run ever happens. Re-anchors the window so the next digest can only
     * come after another full period.
     */
    public function flushPendingDigest(TestSuite $suite): void
    {
        if (NotificationCooldown::windowOpen($suite, 'email')) {
            return;
        }

        $recipients = $this->recipients($suite);

        if ($recipients->isEmpty()) {
            return;
        }

        $heldBack = NotificationCooldown::heldBackRuns($suite, 'email');

        if ($heldBack->isEmpty()) {
            return;
        }

        $this->send($recipients, new RunDigestEmail($suite, $heldBack), null, 'digest');

        NotificationCooldown::markNotified($suite, 'email');
    }

    /**
     * Suite members flagged as email recipients, resolved at send time so
     * membership changes (or user deletions) are always honored.
     *
     * @return Collection<int, User>
     */
    private function recipients(TestSuite $suite): Collection
    {
        return $suite->emailRecipients()->orderBy('users.name')->get();
    }

    /**
     * Failures are logged, never thrown — a notification problem must not
     * fail a test run.
     *
     * @param  Collection<int, User>  $recipients
     */
    private function send(Collection $recipients, Mailable $mailable, ?TestRun $run, string $kind): void
    {
        try {
            Mail::to($recipients->all())->send($mailable);
        } catch (\Throwable $e) {
            Log::warning('Failed to send email notification for test run', [
                'suite_id' => $run?->test_suite_id,
                'run_id' => $run?->id,
                'kind' => $kind,
                'recipients' => $recipients->pluck('email')->all(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
