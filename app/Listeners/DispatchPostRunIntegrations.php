<?php

namespace App\Listeners;

use App\Events\TestRunCompleted;
use App\Models\TestSuiteIntegration;
use App\Services\Integrations\GithubActionIntegrationService;
use App\Services\Integrations\HttpRequestIntegrationService;

/**
 * Fires each enabled trigger-after integration when a run completes.
 * Cancelled runs are skipped (mirrors the Teams notification behaviour).
 */
class DispatchPostRunIntegrations
{
    public function __construct(
        private readonly GithubActionIntegrationService $github,
        private readonly HttpRequestIntegrationService $http,
    ) {}

    public function handle(TestRunCompleted $event): void
    {
        $run = $event->testRun;

        if ($run->status === 'cancelled') {
            return;
        }

        $run->testSuite?->integrations()
            ->where('enabled', true)
            ->where('trigger_after', true)
            ->with('githubApp')
            ->get()
            ->each(function (TestSuiteIntegration $integration) use ($run) {
                if ($integration->type === 'github_action') {
                    $this->github->dispatchForRun($integration, $run, 'after');
                } elseif ($integration->type === 'http_request') {
                    $this->http->dispatchForRun($integration, $run, 'after');
                }
            });
    }
}
