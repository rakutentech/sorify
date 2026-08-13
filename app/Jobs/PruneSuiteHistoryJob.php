<?php

namespace App\Jobs;

use App\Models\TestSuite;
use App\Services\HistoryPruningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PruneSuiteHistoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly TestSuite $suite)
    {
        $this->onQueue('sorify');
    }

    public function handle(HistoryPruningService $pruning): void
    {
        foreach ($this->suite->tests as $test) {
            $pruning->pruneTestHistory($test, $this->suite->history_retention);
        }
    }
}
