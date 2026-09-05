<?php

namespace App\Listeners;

use App\Events\TestRunStarted;
use App\Services\TeamsNotificationService;

class NotifyTeamsOnRunStarted
{
    public function __construct(private readonly TeamsNotificationService $teams) {}

    public function handle(TestRunStarted $event): void
    {
        $this->teams->notifyRunStarted($event->testRun);
    }
}
