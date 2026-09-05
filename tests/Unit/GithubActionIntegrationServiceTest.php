<?php

namespace Tests\Unit;

use App\Models\GithubApp;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\TestSuiteIntegration;
use App\Services\Integrations\GithubActionIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GithubActionIntegrationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.github.com';

    private GithubApp $githubApp;

    private TestSuite $suite;

    private TestRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sorify.integrations.github_action.poll_interval' => 0,
            'sorify.integrations.github_action.pre_run_timeout' => 60,
        ]);

        $this->githubApp = GithubApp::create([
            'name' => 'Test App',
            // Empty base URL = public github.com (api.github.com).
            'base_url' => '',
            'client_id' => 'Iv1.test',
            'client_secret' => 'secret',
            'app_id' => '123456',
            'private_key' => $this->generatePrivateKey(),
        ]);

        $this->suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $this->run = $this->suite->testRuns()->create([
            'status' => 'running',
            'triggered_by' => 'manual',
            'total_tests' => 2,
            'started_at' => now(),
        ]);
    }

    /**
     * Fakes the full GitHub App handshake: installation lookup, installation
     * token minting, workflow dispatch, runs list and run status polling.
     */
    private function fakeGithubFlow(array $runOverrides = []): void
    {
        Http::fake(array_merge([
            self::API.'/repos/acme/app/installation' => Http::response(['id' => 901], 200),
            self::API.'/app/installations/901/access_tokens' => Http::response([
                'token' => 'ghs_installtoken',
                'expires_at' => now()->addHour()->toIso8601String(),
            ], 201),
            self::API.'/repos/acme/app/actions/workflows/deploy.yml/dispatches' => Http::response(status: 204),
            self::API.'/repos/acme/app/actions/workflows/deploy.yml/runs*' => Http::response([
                'total_count' => 1,
                'workflow_runs' => [[
                    'id' => 777,
                    'event' => 'workflow_dispatch',
                    'path' => '.github/workflows/deploy.yml',
                    'actor' => ['type' => 'Bot'],
                ]],
            ], 200),
            self::API.'/repos/acme/app/actions/runs/777' => Http::response(array_merge([
                'status' => 'completed',
                'conclusion' => 'success',
                'html_url' => 'https://github.com/acme/app/actions/runs/777',
            ], $runOverrides), 200),
        ]));
    }

    private function integration(array $overrides = []): TestSuiteIntegration
    {
        return $this->suite->integrations()->create(array_merge([
            'type' => 'github_action',
            'github_app_id' => $this->githubApp->id,
            'label' => 'Deploy',
            'config' => [
                'repository' => 'acme/app',
                'workflow' => 'deploy.yml',
                'ref' => 'main',
                'inputs' => ['environment' => 'staging'],
            ],
            'enabled' => true,
            'trigger_before' => true,
            'trigger_after' => false,
        ], $overrides));
    }

    public function test_dispatch_and_wait_returns_run_url_on_success(): void
    {
        $this->fakeGithubFlow();

        $url = app(GithubActionIntegrationService::class)
            ->dispatchAndWait($this->integration(), $this->run);

        $this->assertSame('https://github.com/acme/app/actions/runs/777', $url);

        // The dispatch carried the configured ref + inputs plus Sorify context.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'workflows/deploy.yml/dispatches')) {
                return false;
            }

            $body = $request->data();

            return $body['ref'] === 'main'
                && $body['inputs']['environment'] === 'staging'
                && $body['inputs']['sorify_run_id'] === (string) $this->run->id
                && $body['inputs']['sorify_suite_id'] === (string) $this->suite->id
                && isset($body['inputs']['sorify_run_url']);
        });

        // Repository API calls authenticate with the installation token.
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ghs_installtoken'));
    }

    public function test_dispatch_and_wait_throws_when_workflow_fails(): void
    {
        $this->fakeGithubFlow(['conclusion' => 'failure']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("concluded 'failure'");

        app(GithubActionIntegrationService::class)->dispatchAndWait($this->integration(), $this->run);
    }

    public function test_dispatch_and_wait_throws_when_workflow_times_out(): void
    {
        config(['sorify.integrations.github_action.pre_run_timeout' => 0]);

        Http::fake([
            self::API.'/repos/acme/app/installation' => Http::response(['id' => 901], 200),
            self::API.'/app/installations/901/access_tokens' => Http::response([
                'token' => 'ghs_installtoken',
                'expires_at' => now()->addHour()->toIso8601String(),
            ], 201),
            self::API.'/repos/acme/app/actions/workflows/deploy.yml/dispatches' => Http::response(status: 204),
            // The dispatched run never appears in the runs list.
            self::API.'/repos/acme/app/actions/workflows/deploy.yml/runs*' => Http::response([
                'total_count' => 0,
                'workflow_runs' => [],
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Timed out waiting for the workflow');

        app(GithubActionIntegrationService::class)->dispatchAndWait($this->integration(), $this->run);
    }

    public function test_dispatch_and_wait_aborts_when_the_test_run_is_cancelled(): void
    {
        $this->fakeGithubFlow();
        $this->run->update(['status' => 'cancelled']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cancelled');

        app(GithubActionIntegrationService::class)->dispatchAndWait($this->integration(), $this->run);
    }

    public function test_dispatch_for_run_sends_workflow_dispatch_without_blocking(): void
    {
        $this->fakeGithubFlow();

        app(GithubActionIntegrationService::class)->dispatchForRun($this->integration(), $this->run, 'after');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'workflows/deploy.yml/dispatches')) {
                return false;
            }

            $body = $request->data();

            // Post-run dispatches receive the run outcome as inputs.
            return $body['inputs']['sorify_run_status'] === 'running'
                && $body['inputs']['sorify_passed_count'] === '0'
                && $body['inputs']['sorify_failed_count'] === '0'
                && $body['inputs']['sorify_error_count'] === '0';
        });

        // Fire-and-forget: the runs list is never polled.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'runs?event=workflow_dispatch'));
    }

    public function test_dispatch_for_run_swallows_and_logs_errors(): void
    {
        Http::fake([
            self::API.'/repos/acme/app/installation' => Http::response(['message' => 'Not Found'], 404),
        ]);

        // No exception must escape.
        app(GithubActionIntegrationService::class)->dispatchForRun($this->integration(), $this->run, 'after');

        $this->assertSame('running', $this->run->fresh()->status);
    }

    public function test_missing_configuration_is_swallowed_and_logged(): void
    {
        // An app without App ID / private key cannot dispatch.
        $this->githubApp->update(['app_id' => null, 'private_key' => null]);
        Http::fake();

        // Fire-and-forget dispatches must never throw — the failure is
        // logged and the run carries on.
        app(GithubActionIntegrationService::class)->dispatchForRun($this->integration(), $this->run, 'after');

        Http::assertNothingSent();
        $this->assertSame('running', $this->run->fresh()->status);
    }

    public function test_empty_ref_falls_back_to_the_default_branch(): void
    {
        Http::fake([
            self::API.'/repos/acme/app/installation' => Http::response(['id' => 901], 200),
            self::API.'/app/installations/901/access_tokens' => Http::response([
                'token' => 'ghs_installtoken',
                'expires_at' => now()->addHour()->toIso8601String(),
            ], 201),
            self::API.'/repos/acme/app' => Http::response(['default_branch' => 'trunk'], 200),
            self::API.'/repos/acme/app/actions/workflows/deploy.yml/dispatches' => Http::response(status: 204),
        ]);

        $integration = $this->integration(['config' => [
            'repository' => 'acme/app',
            'workflow' => 'deploy.yml',
            'ref' => '',
            'inputs' => [],
        ]]);

        app(GithubActionIntegrationService::class)->dispatchForRun($integration, $this->run, 'before');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'workflows/deploy.yml/dispatches')
            && $request->data()['ref'] === 'trunk');
    }

    /**
     * Generates a throwaway RSA private key for signing the app JWT.
     */
    private function generatePrivateKey(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($key, $pem);

        return str_replace("\n", '\\n', $pem);
    }
}
