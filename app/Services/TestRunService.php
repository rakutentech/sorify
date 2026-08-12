<?php

namespace App\Services;

use App\Jobs\RunTestSuiteJob;
use App\Models\TestRun;
use App\Models\TestSuite;

class TestRunService
{
    public function triggerRun(TestSuite $suite, ?array $testIds, string $triggeredBy, ?int $triggeredByUserId = null): TestRun
    {
        $run = $suite->testRuns()->create([
            'triggered_by' => $triggeredBy,
            'triggered_by_user_id' => $triggeredByUserId,
            'status' => 'pending',
        ]);

        RunTestSuiteJob::dispatch($run, $testIds ?: null);

        return $run;
    }

    public function cancel(TestRun $run): TestRun
    {
        if (in_array($run->status, ['pending', 'running'], true)) {
            $run->update(['status' => 'cancelled', 'completed_at' => now()]);
        }

        return $run->refresh();
    }

    public function statusPayload(TestRun $run): array
    {
        return [
            'status' => $run->status,
            'passed_count' => $run->passed_count,
            'failed_count' => $run->failed_count,
            'error_count' => $run->error_count,
            'total_tests' => $run->total_tests,
            'duration_ms' => $run->duration_ms,
        ];
    }
}
