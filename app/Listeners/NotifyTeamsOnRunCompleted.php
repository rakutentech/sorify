<?php

namespace App\Listeners;

use App\Events\TestRunCompleted;
use App\Services\TeamsNotificationService;

class NotifyTeamsOnRunCompleted
{
    public function __construct(private readonly TeamsNotificationService $teams) {}

    public function handle(TestRunCompleted $event): void
    {
        // Runs of a fan-out group never notify individually — the group's
        // combined message is sent by NotifyTeamsOnRunGroupCompleted once
        // the last sibling finalizes. Legacy runs without a group keep the
        // per-run notification.
        if ($event->testRun->fan_out_id !== null) {
            return;
        }

        $this->teams->notifyRunCompleted($event->testRun);
    }
}
