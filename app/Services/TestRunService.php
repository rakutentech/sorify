<?php

namespace App\Services;

use App\Events\TestRunCompleted;
use App\Jobs\RunSingleTestJob;
use App\Models\Test;
use App\Models\TestRun;
use App\Models\TestSuite;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

class TestRunService
{
    public function triggerRun(TestSuite $suite, ?array $testIds, string $triggeredBy, ?int $triggeredByUserId = null): TestRun
    {
        $run = $suite->testRuns()->create([
            'triggered_by' => $triggeredBy,
            'triggered_by_user_id' => $triggeredByUserId,
            'status' => 'pending',
        ]);

        $query = $suite->activeTests();
        if ($testIds) {
            $query->whereIn('id', $testIds);
        }
        $tests = $query->get();

        $run->update([
            'total_tests' => $tests->count(),
            'started_at'  => now(),
            'status'      => 'running',
        ]);

        if ($tests->isEmpty()) {
            $this->finalizeRun($run);

            return $run;
        }

        Bus::batch($tests->map(fn (Test $test) => new RunSingleTestJob($run, $test))->all())
            ->onQueue('sorify')
            ->finally(fn (Batch $batch) => app(self::class)->finalizeRun($run, $batch))
            ->dispatch();

        return $run;
    }

    public function finalizeRun(TestRun $run, ?Batch $batch = null): void
    {
        $run->refresh();

        $run->update([
            'duration_ms'  => $run->started_at?->diffInMilliseconds(now()),
            'completed_at' => now(),
            'status'       => $run->status === 'cancelled' ? 'cancelled' : ($batch?->hasFailures() ? 'failed' : 'completed'),
        ]);

        TestRunCompleted::dispatch($run);
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
