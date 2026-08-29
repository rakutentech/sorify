<?php

namespace App\Services;

use App\Models\TestSuite;

class ReportingService
{
    public function suiteStats(TestSuite $suite): array
    {
        $lastRun = $suite->testRuns()->latest()->first();

        $runs = $suite->testRuns()->where('status', 'completed');
        $totalTests  = (clone $runs)->sum('total_tests');
        $totalPassed = (clone $runs)->sum('passed_count');

        $statusCounts = $suite->tests()
            ->selectRaw('last_run_status, COUNT(*) as count')
            ->whereNotNull('last_run_status')
            ->groupBy('last_run_status')
            ->pluck('count', 'last_run_status')
            ->toArray();

        return [
            'run_count'      => $suite->testRuns()->count(),
            'pass_rate'      => $totalTests > 0 ? round(($totalPassed / $totalTests) * 100, 1) : 0.0,
            'avg_duration_ms' => (clone $runs)->avg('duration_ms') ?? 0,
            'last_run_at'    => $lastRun?->completed_at,
            'last_run_status' => $lastRun?->status,
            'status_counts'  => $statusCounts,
        ];
    }
}
