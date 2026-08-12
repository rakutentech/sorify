<?php

namespace App\Services;

use App\Models\TestRun;
use App\Models\TestSuite;

class ReportingService
{
    public function dashboardStats(): array
    {
        $runs = TestRun::where('status', 'completed')
            ->where('created_at', '>=', now()->subDays(30));

        $totalPassed = (clone $runs)->sum('passed_count');
        $totalTests  = (clone $runs)->sum('total_tests');

        return [
            'total_suites' => TestSuite::count(),
            'total_tests'  => \App\Models\Test::count(),
            'total_runs'   => TestRun::count(),
            'pass_rate_30d' => $totalTests > 0
                ? round(($totalPassed / $totalTests) * 100, 1)
                : 0.0,
        ];
    }

    public function suiteStats(TestSuite $suite): array
    {
        $lastRun = $suite->testRuns()->latest()->first();

        $runs = $suite->testRuns()->where('status', 'completed');
        $totalTests  = (clone $runs)->sum('total_tests');
        $totalPassed = (clone $runs)->sum('passed_count');

        return [
            'run_count'      => $suite->testRuns()->count(),
            'pass_rate'      => $totalTests > 0 ? round(($totalPassed / $totalTests) * 100, 1) : 0.0,
            'avg_duration_ms' => (clone $runs)->avg('duration_ms') ?? 0,
            'last_run_at'    => $lastRun?->completed_at,
            'last_run_status' => $lastRun?->status,
        ];
    }
}
