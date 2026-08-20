<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Relations\HasMany;

class TestSort
{
    public const RUN_STATUSES = ['passed', 'failed', 'error', 'timeout', 'running', 'pending', 'cancelled', 'skipped'];

    private static function latestStatusExpr(): string
    {
        return "COALESCE((SELECT status FROM test_results WHERE test_results.test_id = tests.id ORDER BY created_at DESC LIMIT 1), tests.last_run_status)";
    }

    /**
     * Restrict the query to tests whose latest run status is in the given list.
     */
    public static function filter(HasMany $query, array $statuses): void
    {
        $statuses = array_values(array_intersect($statuses, self::RUN_STATUSES));

        if (empty($statuses)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $query->whereRaw(self::latestStatusExpr()." IN ({$placeholders})", $statuses);
    }

    public static function apply(HasMany $query, string $sort): void
    {
        $latestStatus = self::latestStatusExpr();
        $latestDuration = '(SELECT duration_ms FROM test_results WHERE test_results.test_id = tests.id ORDER BY created_at DESC LIMIT 1)';

        match (true) {
            $sort === 'errors' => $query
                ->orderByRaw("CASE WHEN {$latestStatus} IN ('failed', 'error', 'timeout') THEN 0 ELSE 1 END")
                ->orderByDesc('last_run_at'),
            in_array($sort, self::RUN_STATUSES, true) => $query
                ->orderByRaw("CASE WHEN {$latestStatus} = ? THEN 0 ELSE 1 END", [$sort])
                ->orderByDesc('last_run_at'),
            $sort === 'status_active' => $query
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderBy('name'),
            $sort === 'status_disabled' => $query
                ->orderByRaw("CASE WHEN status = 'disabled' THEN 0 ELSE 1 END")
                ->orderBy('name'),
            $sort === 'duration_long' => $query
                ->orderByRaw("({$latestDuration}) IS NULL")
                ->orderByRaw("{$latestDuration} DESC"),
            $sort === 'duration_short' => $query
                ->orderByRaw("({$latestDuration}) IS NULL")
                ->orderByRaw("{$latestDuration} ASC"),
            $sort === 'oldest' => $query
                ->orderByRaw('last_run_at IS NULL')
                ->orderBy('last_run_at'),
            $sort === 'created_newest' => $query
                ->orderByDesc('created_at'),
            $sort === 'created_oldest' => $query
                ->orderBy('created_at'),
            $sort === 'updated_newest' => $query
                ->orderByDesc('updated_at'),
            $sort === 'updated_oldest' => $query
                ->orderBy('updated_at'),
            default => $query
                ->orderByRaw('last_run_at IS NULL')
                ->orderByDesc('last_run_at'),
        };
    }
}
