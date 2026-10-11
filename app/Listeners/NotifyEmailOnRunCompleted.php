<?php

namespace App\Listeners;

use App\Events\TestRunCompleted;
use App\Services\EmailNotificationService;

class NotifyEmailOnRunCompleted
{
    public function __construct(private readonly EmailNotificationService $email) {}

    public function handle(TestRunCompleted $event): void
    {
        // Runs of a fan-out group never notify individually — the group's
        // combined message is sent by NotifyEmailOnRunGroupCompleted once
        // the last sibling finalizes. Legacy runs without a group keep the
        // per-run notification.
        if ($event->testRun->fan_out_id !== null) {
            return;
        }

        $this->email->notifyRunCompleted($event->testRun);
    }
}
