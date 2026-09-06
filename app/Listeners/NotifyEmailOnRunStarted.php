<?php

namespace App\Listeners;

use App\Events\TestRunStarted;
use App\Services\EmailNotificationService;

class NotifyEmailOnRunStarted
{
    public function __construct(private readonly EmailNotificationService $email) {}

    public function handle(TestRunStarted $event): void
    {
        $this->email->notifyRunStarted($event->testRun);
    }
}
