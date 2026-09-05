<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ActivityLogger
{
    /**
     * Whitelist of payload keys per activity type. Anything not listed here
     * is dropped BEFORE the row is written, so secret values — suite
     * variables, cookie values, webhook URLs/tokens, CI caller IP or user
     * agent — can never leak into the feed. This is enforced at write time
     * (not read time) so the stored data itself is safe to expose.
     *
     * Only ever add a key here after confirming it carries no secret or
     * personally-sensitive data beyond what suite members can already see.
     */
    public const PAYLOAD_KEYS = [
        'run_triggered'        => ['triggered_by'],
        'run_completed'         => ['status', 'triggered_by', 'total_tests', 'passed_count', 'failed_count', 'error_count', 'duration_ms'],
        'run_cancelled'         => ['triggered_by'],
        'suite_created'         => ['name'],
        'suite_updated'         => ['name'],
        'suite_duplicated'      => ['name', 'source_suite_name'],
        'test_created'          => ['name', 'count'],
        'test_updated'          => ['name'],
        'test_code_updated'     => ['name'],
        'test_deleted'          => ['name', 'count'],
        'test_status_changed'   => ['status', 'count'],
        'suite_members_changed' => ['action', 'member_name'],
        'user_registered'       => [],
        'user_created'          => ['user_name'],
        'schedule_updated'      => ['action', 'cron_expression', 'timezone', 'is_enabled'],
        'variables_updated'     => ['count'],
        'cookies_updated'       => ['count'],
        'integration_updated'   => ['action', 'type'],
    ];

    public static function log(string $type, ?User $actor = null, ?TestSuite $suite = null, ?Model $subject = null, array $payload = []): Activity
    {
        $allowed = self::PAYLOAD_KEYS[$type]
            ?? throw new InvalidArgumentException("Unknown activity type [{$type}].");

        return Activity::create([
            'type'          => $type,
            'actor_id'      => $actor?->id,
            'suite_id'      => $suite?->id,
            'subject_type'  => $subject !== null ? $subject->getMorphClass() : null,
            'subject_id'    => $subject?->getKey(),
            'payload'       => collect($payload)->only($allowed)->all(),
        ]);
    }
}
