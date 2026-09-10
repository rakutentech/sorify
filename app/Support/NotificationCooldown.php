<?php

namespace App\Support;

use App\Models\TestRun;
use App\Models\TestSuite;
use Illuminate\Support\Collection;

/**
 * Cooling-off window for suite notifications. After a channel (Teams or
 * email) sends a notification, notifications for that channel are held
 * back until the window expires; the next notification after expiry
 * carries one digest covering every held-back run instead of sending each
 * one individually.
 *
 * The run that opened the window is exempt: its own follow-up messages
 * (e.g. the "run started" notification's matching result notification)
 * always send individually and never re-anchor the window, so runs already
 * held back stay eligible for the next digest.
 */
class NotificationCooldown
{
    /**
     * Allowed cooling-off durations, in minutes. Zero disables the window.
     */
    public const ALLOWED_MINUTES = [0, 1, 3, 5, 15, 30, 60, 240];

    /**
     * True while the cooling-off window is still open. With no cooldown
     * configured, or no notification sent yet, there is no window.
     */
    public static function windowOpen(TestSuite $suite, string $channel): bool
    {
        $minutes = $suite->notificationCooldownMinutes($channel);

        if ($minutes <= 0) {
            return false;
        }

        $last = $suite->lastNotifiedAt($channel);

        return $last !== null && now()->lt($last->copy()->addMinutes($minutes));
    }

    public static function shouldSuppress(TestSuite $suite, string $channel, TestRun $run): bool
    {
        if (! self::windowOpen($suite, $channel)) {
            return false;
        }

        // The run that opened the window always gets its own messages —
        // its result is never folded into a digest.
        return ! self::ownsWindow($suite, $channel, $run);
    }

    public static function ownsWindow(TestSuite $suite, string $channel, TestRun $run): bool
    {
        $ownerRunId = $suite->lastNotifiedRunId($channel);

        return $ownerRunId !== null && $ownerRunId === (int) $run->getKey();
    }

    /**
     * Human-readable duration for a cooling-off window: "1 minute",
     * "15 minutes", "1 hour", "4 hours".
     */
    public static function describeMinutes(int $minutes): string
    {
        if ($minutes >= 60 && $minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours === 1 ? '1 hour' : $hours.' hours';
        }

        return $minutes === 1 ? '1 minute' : $minutes.' minutes';
    }

    /**
     * Subtle notice for individual notifications (run started / completed):
     * tells recipients that further notifications are held back and combined
     * while the window is open. Null when no window is configured.
     */
    public static function holdNotice(TestSuite $suite, string $channel): ?string
    {
        $minutes = $suite->notificationCooldownMinutes($channel);

        if ($minutes <= 0) {
            return null;
        }

        return sprintf(
            'Further notifications for this suite are held back for the next %s and combined into one summary (cooling-off period).',
            self::describeMinutes($minutes),
        );
    }

    /**
     * Explanatory line for digest notifications: why these runs arrived as
     * one summary. Falls back to a period-less sentence when no window is
     * configured (possible only if the cooldown was disabled after runs
     * were already held back).
     */
    public static function digestNotice(TestSuite $suite, string $channel): string
    {
        $minutes = $suite->notificationCooldownMinutes($channel);

        if ($minutes <= 0) {
            return 'Results of runs whose notifications were held back during the cooling-off period are summarized here.';
        }

        return sprintf(
            'Results of runs whose notifications were held back during the cooling-off period (%s) are summarized here.',
            self::describeMinutes($minutes),
        );
    }

    /**
     * Stamp the clock after a notification actually went out. Only writes
     * while a window is configured — with no cooldown there is nothing to
     * track, and this keeps the suite row from churning on every run.
     *
     * A scheduled digest flush passes no run: it clears the owner so the
     * window belongs to no run in particular.
     */
    public static function markNotified(TestSuite $suite, string $channel, ?TestRun $run = null): void
    {
        if ($suite->notificationCooldownMinutes($channel) <= 0) {
            return;
        }

        $suite->forceFill([
            $suite->lastNotifiedAtColumn($channel) => now(),
            $suite->lastNotifiedRunIdColumn($channel) => $run?->getKey(),
        ])->saveQuietly();
    }

    /**
     * Completed runs since the last notification that pass the notify
     * flags — the runs whose individual notifications were held back by
     * the window. The just-completed run and the window-opening run are
     * excluded; callers append the current run themselves.
     *
     * @return Collection<int, TestRun>
     */
    public static function heldBackRuns(TestSuite $suite, string $channel, ?TestRun $exceptRun = null): Collection
    {
        $last = $suite->lastNotifiedAt($channel);

        if ($last === null) {
            return collect();
        }

        $notifySuccess = $channel === 'teams'
            ? (bool) $suite->teams_notify_on_success
            : (bool) $suite->email_notify_on_success;

        $notifyFailure = $channel === 'teams'
            ? (bool) $suite->teams_notify_on_failure
            : (bool) $suite->email_notify_on_failure;

        $ownerRunId = $suite->lastNotifiedRunId($channel);

        return $suite->testRuns()
            ->where('completed_at', '>', $last)
            ->where('status', '!=', 'cancelled')
            ->orderBy('completed_at')
            ->get()
            ->reject(fn (TestRun $run) => $run->getKey() === $exceptRun?->getKey()
                || $run->getKey() === $ownerRunId)
            ->filter(fn (TestRun $run) => $run->isSuccessful() ? $notifySuccess : $notifyFailure)
            ->values();
    }
}
