<?php

namespace App\Jobs;

use App\Models\Test;
use App\Models\TestRun;
use App\Services\HistoryPruningService;
use App\Services\PlaywrightRunnerService;
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

        $pruning->pruneTestHistory($this->test, $this->testRun->testSuite->history_retention ?? 5);
    }
}
