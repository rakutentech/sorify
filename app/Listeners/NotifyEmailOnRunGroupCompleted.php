<?php

namespace App\Listeners;

use App\Events\TestRunGroupCompleted;
use App\Services\EmailNotificationService;

class NotifyEmailOnRunGroupCompleted
{
    public function __construct(private readonly EmailNotificationService $email) {}

    public function handle(TestRunGroupCompleted $event): void
    {
        $this->email->notifyRunGroupCompleted($event->runs);
    }
}
