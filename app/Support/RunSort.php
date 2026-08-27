<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class RunSort
{
    /**
     * Sort keys accepted by the runs index and the MCP list_runs tool.
     * Must match the keys in {@see apply()}.
     */
    public const SORT_KEYS = ['suite', 'status', 'passed', 'duration', 'screenshots', 'created_by', 'ran_by', 'run_date'];

    /**
     * Apply sorting to the test-runs index query.
     *
     * @param  string  $sort  The sort field.
     * @param  string  $dir  "asc" or "desc" (default "desc").
     */
    public static function apply(Builder $query, string $sort, string $dir = 'desc'): void
    {
        $dir = strtolower($dir) === 'asc' ? 'asc' : 'desc';

        // Count of screenshots attached to this run's results (via test_results).
        $screenshotCountExpr = '(SELECT COUNT(*) FROM screenshots s JOIN test_results tr ON tr.id = s.test_result_id WHERE tr.test_run_id = test_runs.id)';

        match ($sort) {
            'suite' => $query
                ->leftJoin('test_suites', 'test_runs.test_suite_id', '=', 'test_suites.id')
                ->orderBy('test_suites.name', $dir)
                ->orderBy('test_runs.id', $dir),
            'status' => $query
                ->orderBy('test_runs.status', $dir)
                ->orderBy('test_runs.id', $dir),
            'passed' => $query
                ->orderBy('test_runs.passed_count', $dir)
                ->orderBy('test_runs.id', $dir),
            'duration' => $query
                ->orderByRaw('test_runs.duration_ms IS NULL')
                ->orderBy('test_runs.duration_ms', $dir)
                ->orderBy('test_runs.id', $dir),
            'screenshots' => $query
                ->orderByRaw("{$screenshotCountExpr} ".($dir === 'desc' ? 'DESC' : 'ASC'))
                ->orderBy('test_runs.id', $dir),
            'created_by' => $query
                ->leftJoin('test_suites AS sort_suite', 'test_runs.test_suite_id', '=', 'sort_suite.id')
                ->leftJoin('users AS created_by_user', 'sort_suite.created_by', '=', 'created_by_user.id')
                ->orderByRaw('created_by_user.name IS NULL')
                ->orderBy('created_by_user.name', $dir)
                ->orderBy('test_runs.id', $dir),
            'ran_by' => $query
                ->leftJoin('users AS ran_by_user', 'test_runs.triggered_by_user_id', '=', 'ran_by_user.id')
                ->orderByRaw('ran_by_user.name IS NULL')
                ->orderBy('ran_by_user.name', $dir)
                ->orderBy('test_runs.triggered_by', $dir)
                ->orderBy('test_runs.id', $dir),
            'run_date' => $query
                ->orderBy('test_runs.created_at', $dir)
                ->orderBy('test_runs.id', $dir),
            default => $query
                ->latest('test_runs.created_at')
                ->orderBy('test_runs.id', 'desc'),
        };
    }
}
