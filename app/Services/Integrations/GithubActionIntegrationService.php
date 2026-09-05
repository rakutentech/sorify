<?php

namespace App\Services\Integrations;

use App\Models\GithubApp;
use App\Models\TestRun;
use App\Models\TestSuiteIntegration;
use App\Support\AppUrl;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Triggers GitHub Actions workflows ("workflow_dispatch") on behalf of suite
 * integrations, authenticating as the Sorify GitHub App (installation token).
 *
 * Two modes:
 *  - dispatchForRun()  — fire-and-forget, used for post-run triggers.
 *  - dispatchAndWait()  — blocking, used for pre-run triggers: the workflow
 *    is dispatched and polled until it completes; success lets the test run
 *    proceed, anything else aborts it.
 *
 * Workflow inputs prefixed with "sorify_" are reserved (overwritten) for
 * context Sorify injects: sorify_run_id, sorify_suite_id, sorify_run_url,
 * and — for post-run triggers — the run's outcome counts.
 */
class GithubActionIntegrationService
{
    public function __construct() {}

    /**
     * Fire-and-forget dispatch. Failures are logged, never thrown.
     */
    public function dispatchForRun(TestSuiteIntegration $integration, TestRun $run, string $phase): void
    {
        try {
            $this->sendDispatch($this->resolveApp($integration), $integration, $run, $phase);
        } catch (Throwable $e) {
            Log::warning('Failed to dispatch GitHub Actions integration', [
                'suite_id' => $integration->test_suite_id,
                'integration_id' => $integration->id,
                'run_id' => $run->id,
                'phase' => $phase,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Which GitHub App this integration dispatches as: the integration's own
     * app, or the first dispatch-capable app as fallback.
     */
    private function resolveApp(TestSuiteIntegration $integration): GithubApp
    {
        return GithubAppAuthenticator::resolveApp($integration)
            ?? throw new RuntimeException(
                "Integration '{$integration->label}' has no GitHub App to dispatch as. Configure one under Admin → GitHub Apps."
            );
    }

    /**
     * Blocking dispatch: waits for the workflow to conclude. Returns the
     * workflow run's HTML URL on success; throws on failure or timeout.
     *
     * The Sorify run is checked between polls so a cancellation aborts the
     * wait even though GitHub itself is not contacted for it.
     */
    public function dispatchAndWait(TestSuiteIntegration $integration, TestRun $run): string
    {
        $app = $this->resolveApp($integration);
        [$owner, $repo] = $this->repository($integration);
        $workflow = (string) $integration->config('workflow');
        $interval = max(0, (int) config('sorify.integrations.github_action.poll_interval', 5));
        // Wall-clock deadline (not Carbon) so frozen test times can't stall
        // the polling loops.
        $deadline = microtime(true) + max(0, (int) config('sorify.integrations.github_action.pre_run_timeout', 900));

        $dispatchedAt = $this->sendDispatch($app, $integration, $run, 'before');

        // The dispatched run appears in the runs list asynchronously —
        // look it up by correlation until the deadline.
        $githubRun = null;
        while ($githubRun === null && microtime(true) < $deadline) {
            $this->abortIfRunWasCancelled($run);
            $githubRun = $this->findDispatchedRun($app, $owner, $repo, $workflow, $integration, $dispatchedAt);
            if ($githubRun === null && $interval > 0) {
                sleep($interval);
            }
        }

        if ($githubRun === null) {
            throw new RuntimeException(
                "Timed out waiting for the workflow '{$workflow}' to appear on {$owner}/{$repo}."
            );
        }

        while (microtime(true) < $deadline) {
            $this->abortIfRunWasCancelled($run);

            $response = $this->request($app, $owner, $repo, $this->proxy($app))->get("{$app->apiBase()}/repos/{$owner}/{$repo}/actions/runs/{$githubRun['id']}");

            if ($response->failed()) {
                throw new RuntimeException(
                    "Failed to poll the workflow run for '{$workflow}' on {$owner}/{$repo} (HTTP {$response->status()})."
                );
            }

            if ($response->json('status') === 'completed') {
                $conclusion = (string) $response->json('conclusion');
                $url = (string) $response->json('html_url');

                if ($conclusion === 'success') {
                    return $url;
                }

                throw new RuntimeException(
                    "The workflow '{$workflow}' on {$owner}/{$repo} concluded '{$conclusion}' — {$url}"
                );
            }

            if ($interval > 0) {
                sleep($interval);
            }
        }

        throw new RuntimeException(
            "Timed out waiting for the workflow '{$workflow}' on {$owner}/{$repo} to complete."
        );
    }

    /**
     * POST the workflow_dispatch event. Returns the dispatch timestamp used
     * later to correlate the resulting workflow run.
     */
    private function sendDispatch(GithubApp $app, TestSuiteIntegration $integration, TestRun $run, string $phase): CarbonInterface
    {
        [$owner, $repo] = $this->repository($integration);
        $workflow = (string) $integration->config('workflow');

        $response = $this->request($app, $owner, $repo, $this->proxy($app))
            ->post("{$app->apiBase()}/repos/{$owner}/{$repo}/actions/workflows/{$workflow}/dispatches", [
                'ref' => $this->ref($app, $integration),
                'inputs' => $this->buildInputs($integration, $run, $phase),
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "GitHub rejected the workflow dispatch for '{$workflow}' on {$owner}/{$repo} (HTTP {$response->status()}): ".Str::limit($response->body(), 300)
            );
        }

        return now();
    }

    /**
     * Find the workflow run created by our dispatch. Correlation is
     * approximate: match the workflow path, workflow_dispatch event, runs
     * created in the dispatch window, preferring bot-actor runs (GitHub App
     * dispatches act as the app bot).
     */
    private function findDispatchedRun(GithubApp $app, string $owner, string $repo, string $workflow, TestSuiteIntegration $integration, CarbonInterface $dispatchedAt): ?array
    {
        $response = $this->request($app, $owner, $repo, $this->proxy($app))->get(
            "{$app->apiBase()}/repos/{$owner}/{$repo}/actions/workflows/{$workflow}/runs",
            [
                'event' => 'workflow_dispatch',
                'per_page' => 20,
                'created' => '>='.$dispatchedAt->clone()->subSeconds(10)->format('Y-m-d\TH:i:s\Z'),
            ],
        );

        if ($response->failed()) {
            return null;
        }

        $path = $this->normalizeWorkflowPath($workflow);

        $candidates = collect((array) $response->json('workflow_runs'))
            ->filter(fn (array $r) => ($r['event'] ?? null) === 'workflow_dispatch')
            ->filter(fn (array $r) => $this->normalizeWorkflowPath((string) ($r['path'] ?? '')) === $path)
            ->filter(fn (array $r) => isset($r['id']))
            ->values();

        if ($candidates->isEmpty()) {
            return null;
        }

        // Prefer bot-triggered runs (ours), then the earliest created.
        return $candidates
            ->sortByDesc(fn (array $r) => (($r['actor']['type'] ?? '') === 'Bot') ? 1 : 0)
            ->sortBy(fn (array $r) => (int) $r['id'])
            ->first();
    }

    /**
     * @return array{0: string, 1: string} [owner, repo]
     */
    private function repository(TestSuiteIntegration $integration): array
    {
        $repository = trim((string) $integration->config('repository'));
        [$owner, $repo] = array_pad(explode('/', $repository, 2), 2, null);

        if (! $owner || ! $repo) {
            throw new RuntimeException("Integration '{$integration->label}' has no repository configured.");
        }

        return [$owner, $repo];
    }

    /**
     * Branch or tag to dispatch on. Falls back to the repository's default
     * branch when unset.
     */
    private function ref(GithubApp $app, TestSuiteIntegration $integration): string
    {
        $ref = trim((string) $integration->config('ref'));

        if ($ref !== '') {
            return $ref;
        }

        [$owner, $repo] = $this->repository($integration);

        $response = $this->request($app, $owner, $repo, $this->proxy($app))->get("{$app->apiBase()}/repos/{$owner}/{$repo}");

        if ($response->failed()) {
            throw new RuntimeException("Failed to look up the default branch of {$owner}/{$repo} (HTTP {$response->status()}).");
        }

        return (string) ($response->json('default_branch') ?? 'main');
    }

    /**
     * User-configured inputs plus Sorify-injected context. "sorify_"-prefixed
     * keys are reserved and always overwritten.
     *
     * @return array<string, string>
     */
    private function buildInputs(TestSuiteIntegration $integration, TestRun $run, string $phase): array
    {
        $inputs = [];

        foreach ((array) $integration->config('inputs', []) as $key => $value) {
            if (is_string($key) && ! str_starts_with($key, 'sorify_')) {
                $inputs[$key] = (string) $value;
            }
        }

        $inputs['sorify_run_id'] = (string) $run->id;
        $inputs['sorify_suite_id'] = (string) $run->test_suite_id;
        $inputs['sorify_run_url'] = AppUrl::absolute(route('runs.show', $run, absolute: false));

        if ($phase === 'after') {
            $inputs['sorify_run_status'] = (string) $run->status;
            $inputs['sorify_passed_count'] = (string) (int) $run->passed_count;
            $inputs['sorify_failed_count'] = (string) (int) $run->failed_count;
            $inputs['sorify_error_count'] = (string) (int) $run->error_count;
        }

        return $inputs;
    }

    private function abortIfRunWasCancelled(TestRun $run): void
    {
        if ($run->fresh()->status === 'cancelled') {
            throw new RuntimeException('The test run was cancelled while waiting for the pre-run workflow.');
        }
    }

    private function normalizeWorkflowPath(string $workflow): string
    {
        $workflow = ltrim(trim($workflow), '/');

        return str_starts_with($workflow, '.github/') ? mb_strtolower($workflow) : '.github/workflows/'.mb_strtolower($workflow);
    }

    /**
     * Optional per-integration proxy for all GitHub API traffic (both the
     * app-authentication calls and the repository API calls).
     */
    /**
     * Effective proxy for all GitHub API traffic of this integration: its
     * GitHub App's proxy (Admin → GitHub Apps).
     */
    private function proxy(GithubApp $app): ?string
    {
        return $app->proxy;
    }

    private function request(GithubApp $app, string $owner, string $repo, ?string $proxy = null): PendingRequest
    {
        $request = Http::timeout(15)
            ->withToken(app(GithubAppAuthenticator::class)->accessToken($app, $owner, $repo, $proxy))
            ->withHeaders($app->apiHeaders());

        if ($proxy !== null) {
            $request->withOptions(['proxy' => $proxy]);
        }

        return $request;
    }
}
