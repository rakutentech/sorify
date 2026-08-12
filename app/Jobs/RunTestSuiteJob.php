<?php

namespace App\Jobs;

use App\Events\TestRunCompleted;
use App\Models\TestRun;
use App\Services\PlaywrightRunnerService;
use App\Services\TeamsNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunTestSuiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 1;

    public function __construct(
        public readonly TestRun $testRun,
        public readonly ?array $testIds = null,
    ) {
        $this->onQueue('test-runner');
    }

    public function handle(PlaywrightRunnerService $runner): void
    {
        if ($this->testRun->refresh()->status === 'cancelled') {
            return;
        }

        $runner->run($this->testRun, $this->testIds);
        TestRunCompleted::dispatch($this->testRun);
    }

    public function failed(\Throwable $e): void
    {
        $this->testRun->update([
            'status'       => 'failed',
            'completed_at' => now(),
        ]);

        app(TeamsNotificationService::class)->notifyRunCompleted($this->testRun);
    }
}
