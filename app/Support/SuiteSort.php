<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class SuiteSort
{
    /**
     * Sort keys accepted by the suites index and the MCP list_suites /
     * list_bookmarked_suites tools. Must match the keys in {@see apply()}.
     */
    public const SORT_KEYS = ['name', 'users', 'tests', 'runs', 'pass_rate', 'last_run', 'created'];

    /**
     * Apply sorting to the test-suites index query (also used for bookmarks).
     *
     * @param  string  $sort  The sort field.
     * @param  string  $dir  "asc" or "desc" (default "desc").
     */
    public static function apply(Builder|Relation $query, string $sort, string $dir = 'desc'): void
    {
        $dir = strtolower($dir) === 'asc' ? 'asc' : 'desc';

        // Count of members attached to this suite (via test_suite_user).
        $membersCountExpr = '(SELECT COUNT(*) FROM test_suite_user tsu WHERE tsu.test_suite_id = test_suites.id)';

        // Last run completed_at for this suite (null when no runs).
        $lastRunExpr = '(SELECT MAX(completed_at) FROM test_runs WHERE test_runs.test_suite_id = test_suites.id)';

        // Pass-rate over completed runs: SUM(passed)/SUM(total)*100 (0 when no completed tests).
        $passRateExpr = '(SELECT CASE WHEN COALESCE(SUM(total_tests), 0) > 0 '
            .'THEN ROUND(SUM(passed_count) * 100.0 / SUM(total_tests), 1) ELSE 0 END '
            .'FROM test_runs WHERE test_runs.test_suite_id = test_suites.id AND test_runs.status = \'completed\')';

        match ($sort) {
            'name' => $query
                ->orderBy('test_suites.name', $dir)
                ->orderBy('test_suites.id', $dir),
            'users' => $query
                ->orderByRaw("{$membersCountExpr} ".($dir === 'desc' ? 'DESC' : 'ASC'))
                ->orderBy('test_suites.id', $dir),
            'tests' => $query
                ->orderBy('tests_count', $dir)
                ->orderBy('test_suites.id', $dir),
            'runs' => $query
                ->orderBy('test_runs_count', $dir)
                ->orderBy('test_suites.id', $dir),
            'pass_rate' => $query
                ->orderByRaw("{$passRateExpr} ".($dir === 'desc' ? 'DESC' : 'ASC'))
                ->orderBy('test_suites.id', $dir),
            'last_run' => $query
                ->orderByRaw("{$lastRunExpr} IS NULL")
                ->orderByRaw("{$lastRunExpr} ".($dir === 'desc' ? 'DESC' : 'ASC'))
                ->orderBy('test_suites.id', $dir),
            'created' => $query
                ->orderBy('test_suites.created_at', $dir)
                ->orderBy('test_suites.id', $dir),
            default => $query
                ->latest('test_suites.created_at')
                ->orderBy('test_suites.id', 'desc'),
        };
    }
}
