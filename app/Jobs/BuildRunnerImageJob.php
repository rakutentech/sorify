<?php

namespace App\Jobs;

use App\Services\DockerExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process as ProcessFacade;

class BuildRunnerImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onQueue('sorify');
    }

    public function handle(DockerExecutor $docker): void
    {
        $result = ProcessFacade::timeout(900)->run($docker->buildImage());

        cache()->forget('sorify.execution.readiness');
        Cache::put('sorify.execution.runner_image_last_build', [
            'ok' => $result->successful(),
            'at' => now()->toIso8601String(),
            'error' => $result->successful() ? null : mb_substr($result->errorOutput() ?: $result->output(), 0, 500),
        ], now()->addDay());
    }
}
