<?php

namespace App\Listeners;

use App\Events\TestRunGroupCompleted;
use App\Services\TeamsNotificationService;

class NotifyTeamsOnRunGroupCompleted
{
    public function __construct(private readonly TeamsNotificationService $teams) {}

    public function handle(TestRunGroupCompleted $event): void
    {
        $this->teams->notifyRunGroupCompleted($event->runs);
    }
}
