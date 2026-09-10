<?php

namespace App\Console\Commands;

use App\Models\TestSuite;
use App\Services\EmailNotificationService;
use App\Services\TeamsNotificationService;
use App\Support\NotificationCooldown;
use Illuminate\Console\Command;

/**
 * Sends the pending notification digests whose cooling-off window has
 * expired, so held-back results are delivered even when no further run
 * ever happens. Runs every minute; each flush re-anchors its window, so
 * a suite can receive at most one digest per cooling-off period.
 */
class FlushNotificationDigests extends Command
{
    protected $signature = 'sorify:flush-notification-digests';

    protected $description = 'Send pending notification digests whose cooling-off window has expired';

    public function handle(TeamsNotificationService $teams, EmailNotificationService $email): int
    {
        TestSuite::query()
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('teams_notification_cooldown_minutes', '>', 0)
                        ->whereNotNull('teams_last_notified_at');
                })->orWhere(function ($q) {
                    $q->where('email_notification_cooldown_minutes', '>', 0)
                        ->whereNotNull('email_last_notified_at');
                });
            })
            ->orderBy('id')
            ->chunkById(100, function ($suites) use ($teams, $email) {
                foreach ($suites as $suite) {
                    // Re-read inside the loop so a concurrent notification
                    // that just stamped the clock is respected, not raced.
                    $suite = $suite->fresh() ?? $suite;

                    if ($this->windowExpired($suite, 'teams')) {
                        $teams->flushPendingDigest($suite);
                    }

                    if ($this->windowExpired($suite, 'email')) {
                        $email->flushPendingDigest($suite);
                    }
                }
            });

        return self::SUCCESS;
    }

    /**
     * A window is due for a flush when a cooldown is configured, a
     * notification was sent (the clock exists), and the window has
     * elapsed. Suites with no pending runs are still visited — the
     * service-level check decides whether anything actually goes out.
     */
    private function windowExpired(TestSuite $suite, string $channel): bool
    {
        return $suite->notificationCooldownMinutes($channel) > 0
            && $suite->lastNotifiedAt($channel) !== null
            && ! NotificationCooldown::windowOpen($suite, $channel);
    }
}
