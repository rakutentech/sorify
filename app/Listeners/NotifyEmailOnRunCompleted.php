<?php

namespace App\Listeners;

use App\Events\TestRunCompleted;
use App\Services\EmailNotificationService;

class NotifyEmailOnRunCompleted
{
    public function __construct(private readonly EmailNotificationService $email) {}

    public function handle(TestRunCompleted $event): void
    {
        $this->email->notifyRunCompleted($event->testRun);
    }
}
