<?php

namespace App\Services;

use App\Mail\RunCompletedEmail;
use App\Mail\RunStartedEmail;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
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

        $recipients = $this->recipients($suite);

        if ($recipients->isEmpty()) {
            return;
        }

        $this->send($recipients, new RunStartedEmail($run), $run, 'start');
    }

    public function notifyRunCompleted(TestRun $run): void
    {
        $suite = $run->testSuite;

        if (! $suite || $run->status === 'cancelled') {
            return;
        }

        // A run aborted by a failing pre-run integration also has zero
        // failure counts — the status check keeps those out of "success".
        $isSuccess = $run->status === 'completed'
            && (int) $run->failed_count === 0
            && (int) $run->error_count === 0;

        if ($isSuccess && ! $suite->email_notify_on_success) {
            return;
        }

        if (! $isSuccess && ! $suite->email_notify_on_failure) {
            return;
        }

        $recipients = $this->recipients($suite);

        if ($recipients->isEmpty()) {
            return;
        }

        $this->send($recipients, new RunCompletedEmail($run, $isSuccess), $run, 'completion');
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
    private function send(Collection $recipients, \Illuminate\Mail\Mailable $mailable, TestRun $run, string $kind): void
    {
        try {
            Mail::to($recipients->all())->send($mailable);
        } catch (\Throwable $e) {
            Log::warning('Failed to send email notification for test run', [
                'suite_id' => $run->test_suite_id,
                'run_id' => $run->id,
                'kind' => $kind,
                'recipients' => $recipients->pluck('email')->all(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
