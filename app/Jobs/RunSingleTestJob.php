<?php

namespace App\Jobs;

use App\Models\Test;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Services\HistoryPruningService;
use App\Services\PlaywrightRunnerService;
use Illuminate\Support\Facades\Log;
use Throwable;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunSingleTestJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 1;

    public function __construct(
        public readonly TestRun $testRun,
        public readonly Test $test,
    ) {
        $this->onQueue('sorify');
    }

    /**
     * Scale the queue worker timeout with the suite's per-action timeout and
     * retry count so long-running tests (up to 10 min/action) aren't killed
     * mid-execution. Uses the Symfony process timeout (timeout_ms/1000 + 10)
     * multiplied by max attempts, plus 30s of overhead.
     */
    public function timeout(): int
    {
        $suite = $this->testRun->testSuite;

        $perAttempt = (($suite->timeout_ms ?? 30000) / 1000) + 10;
        $attempts   = 1 + max(0, (int) ($suite->max_retries ?? 0));

        return (int) ceil($perAttempt * $attempts) + 30;
    }

    public function handle(PlaywrightRunnerService $runner, HistoryPruningService $pruning): void
    {
        if ($this->batch()?->cancelled() || $this->testRun->refresh()->status === 'cancelled') {
            return;
        }

        $result = $runner->runWithRetries($this->test, $this->testRun);

        match ($result->status) {
            'passed'            => $this->testRun->increment('passed_count'),
            'failed', 'timeout' => $this->testRun->increment('failed_count'),
            'cancelled'         => null,
            default             => $this->testRun->increment('error_count'),
        };

        $this->test->update([
            'last_run_at'     => now(),
            'last_run_status' => in_array($result->status, ['passed', 'failed', 'error', 'timeout', 'cancelled'])
                ? $result->status
                : 'error',
        ]);

        try {
            $pruning->pruneTestHistory($this->test, $this->testRun->testSuite->history_retention ?? 5);
        } catch (Throwable $exception) {
            Log::warning('History pruning failed after test run', [
                'test_id'     => $this->test->id,
                'test_run_id' => $this->testRun->id,
                'error'       => $exception->getMessage(),
            ]);
        }
    }

    /**
     * A job timeout kills the worker process mid Playwright run, before
     * PlaywrightRunnerService's finally-block can close out the TestResult
     * row — leaving it stuck at status "running" forever even though the
     * batch (and the run) already finalized around it. Close it out here.
     */
    public function failed(Throwable $exception): void
    {
        $result = TestResult::where('test_run_id', $this->testRun->id)
            ->where('test_id', $this->test->id)
            ->whereNull('completed_at')
            ->latest('id')
            ->first();

        if ($result) {
            $result->update([
                'status'        => 'timeout',
                'error_message' => $exception->getMessage(),
                'completed_at'  => now(),
            ]);
            $this->testRun->increment('error_count');
        }

        $this->test->update([
            'last_run_at'     => now(),
            'last_run_status' => 'error',
        ]);
    }
}
