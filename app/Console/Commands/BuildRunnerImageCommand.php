<?php

namespace App\Console\Commands;

use App\Services\DockerExecutor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process as ProcessFacade;

class BuildRunnerImageCommand extends Command
{
    protected $signature = 'sorify:runner-image';

    protected $description = 'Build the hardened ephemeral runner image on the configured Docker daemon';

    public function handle(DockerExecutor $docker): int
    {
        $this->info('Building runner image '.config('sorify.execution.runner_image').' ...');

        $result = ProcessFacade::timeout(900)->run($docker->buildImage());

        if ($result->successful()) {
            $this->info('Runner image built successfully.');
            cache()->forget('sorify.execution.readiness');

            return self::SUCCESS;
        }

        $this->error('Build failed:');
        $this->line($result->errorOutput() ?: $result->output());

        return self::FAILURE;
    }
}
