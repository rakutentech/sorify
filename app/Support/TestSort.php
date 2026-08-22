<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Relations\HasMany;

class TestSort
{
    public const RUN_STATUSES = ['passed', 'failed', 'error', 'timeout', 'running', 'pending', 'cancelled', 'skipped'];

    private static function latestStatusExpr(): string
    {
        return 'COALESCE((SELECT status FROM test_results WHERE test_results.test_id = tests.id ORDER BY created_at DESC LIMIT 1), tests.last_run_status)';
    }

    /**
     * Boolean expression (0/1): whether the test's latest run has screenshots.
     */
    private static function hasScreenshotsExpr(): string
    {
        return '(SELECT COUNT(*) FROM screenshots s JOIN test_results tr ON tr.id = s.test_result_id WHERE tr.test_id = tests.id AND tr.id = (SELECT id FROM test_results WHERE test_results.test_id = tests.id ORDER BY created_at DESC LIMIT 1)) > 0';
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

    /**
     * Apply sorting to the test query.
     *
     * @param  string  $sort  The sort field. Legacy values (e.g. "duration_long",
     *                        "created_newest") are mapped for backward compatibility.
     * @param  string  $dir  "asc" or "desc" (default "desc").
     */
    public static function apply(HasMany $query, string $sort, string $dir = 'desc'): void
    {
        $dir = strtolower($dir) === 'asc' ? 'asc' : 'desc';
        $desc = $dir === 'desc';

        // Map legacy sort values to field + direction.
        [$sort, $dir] = self::resolveLegacy($sort, $dir);
        $desc = $dir === 'desc';

        $latestStatus = self::latestStatusExpr();
        $latestDuration = '(SELECT duration_ms FROM test_results WHERE test_results.test_id = tests.id ORDER BY created_at DESC LIMIT 1)';

        match (true) {
            $sort === 'errors' => $query
                ->orderByRaw("CASE WHEN {$latestStatus} IN ('failed', 'error', 'timeout') THEN ".($desc ? 0 : 1).' ELSE '.($desc ? 1 : 0).' END')
                ->orderBy('last_run_at', $dir),
            in_array($sort, self::RUN_STATUSES, true) => $query
                ->orderByRaw("CASE WHEN {$latestStatus} = ? THEN ".($desc ? 0 : 1).' ELSE '.($desc ? 1 : 0).' END', [$sort])
                ->orderBy('last_run_at', $dir),
            $sort === 'status' => $query
                ->orderByRaw("CASE WHEN status = 'active' THEN ".($desc ? 1 : 0).' ELSE '.($desc ? 0 : 1).' END')
                ->orderBy('name'),
            $sort === 'duration' => $query
                ->orderByRaw("({$latestDuration}) IS NULL")
                ->orderByRaw("{$latestDuration} ".($desc ? 'DESC' : 'ASC')),
            $sort === 'has_screenshots' => $query
                ->orderByRaw(self::hasScreenshotsExpr().($desc ? ' DESC' : ' ASC'))
                ->orderByRaw('last_run_at IS NULL')
                ->orderBy('last_run_at', $dir),
            $sort === 'created' => $query
                ->orderBy('created_at', $dir),
            $sort === 'updated' => $query
                ->orderBy('updated_at', $dir),
            $sort === 'run_date' => $query
                ->orderByRaw('last_run_at IS NULL')
                ->orderBy('last_run_at', $dir),
            default => $query
                ->orderByRaw('last_run_at IS NULL')
                ->orderByDesc('last_run_at'),
        };
    }

    /**
     * Map legacy sort values (which encoded direction in the value itself) to
     * the new field + direction model. New values pass through unchanged.
     *
     * @return array{0: string, 1: string} [sort, dir]
     */
    private static function resolveLegacy(string $sort, string $dir): array
    {
        return match ($sort) {
            'duration_long' => ['duration', 'desc'],
            'duration_short' => ['duration', 'asc'],
            'oldest' => ['run_date', 'asc'],
            'created_newest' => ['created', 'desc'],
            'created_oldest' => ['created', 'asc'],
            'updated_newest' => ['updated', 'desc'],
            'updated_oldest' => ['updated', 'asc'],
            'status_active' => ['status', 'asc'],
            'status_disabled' => ['status', 'desc'],
            '' => ['run_date', 'desc'],
            default => [$sort, $dir],
        };
    }
}
