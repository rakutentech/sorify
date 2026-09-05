<?php

namespace App\Jobs;

use App\Models\TestRun;
use App\Services\Integrations\GithubActionIntegrationService;
use App\Services\Integrations\HttpRequestIntegrationService;
use App\Services\TestRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs a suite's blocking pre-run integrations (GitHub Actions workflows,
 * HTTP requests) before the run's tests are dispatched. Each integration
 * must complete successfully for the run to proceed; the first failure
 * fails the whole run without executing any test.
 */
class RunPreRunIntegrationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // The job mutates run state; a retry could double-dispatch workflows.
    public $tries = 1;

    public function __construct(
        public TestRun $run,
        public ?array $testIds,
    ) {}

    public function handle(TestRunService $runs, GithubActionIntegrationService $github, HttpRequestIntegrationService $http): void
    {
        $run = $this->run->fresh() ?? $this->run;

        // Cancelled (or otherwise moved on) while the job sat in the queue.
        if ($run->status !== 'pending') {
            return;
        }

        $run->update(['status_note' => 'Waiting for pre-run integrations…']);

        $integrations = $run->testSuite?->preRunIntegrations()->with('githubApp')->get() ?? collect();

        foreach ($integrations as $integration) {
            $label = $integration->label ?: $integration->config('url') ?: $integration->config('repository');

            try {
                if ($integration->type === 'github_action') {
                    $github->dispatchAndWait($integration, $run);
                } elseif ($integration->type === 'http_request') {
                    $http->executeAndWait($integration, $run);
                }
            } catch (Throwable $e) {
                $runs->failRun($run, "Pre-run integration '{$label}' failed: {$e->getMessage()}");

                return;
            }
        }

        $run->update(['status_note' => null]);

        $runs->startTests($run, $this->testIds);
    }
}
