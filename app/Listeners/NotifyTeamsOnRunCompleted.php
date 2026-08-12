<?php

namespace App\Listeners;

use App\Events\TestRunCompleted;
use App\Services\TeamsNotificationService;

class NotifyTeamsOnRunCompleted
{
    public function __construct(private readonly TeamsNotificationService $teams) {}

    public function handle(TestRunCompleted $event): void
    {
        $this->teams->notifyRunCompleted($event->testRun);
    }
}
