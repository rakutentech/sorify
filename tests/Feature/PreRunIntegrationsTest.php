<?php

namespace Tests\Feature;

use App\Events\TestRunCompleted;
use App\Jobs\RunPreRunIntegrationsJob;
use App\Jobs\RunSingleTestJob;
use App\Models\GithubApp;
use App\Models\TestSuite;
use App\Services\Integrations\GithubActionIntegrationService;
use App\Services\Integrations\HttpRequestIntegrationService;
use App\Services\TestRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PreRunIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.github.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sorify.integrations.github_action.poll_interval' => 0,
            'sorify.integrations.github_action.pre_run_timeout' => 60,
            'sorify.run_trigger_rate_limit' => ['max_attempts' => 100, 'decay_seconds' => 60],
        ]);

        GithubApp::create([
            'name' => 'Test App',
            'base_url' => '',
            'client_id' => 'Iv1.test',
            'client_secret' => 'secret',
            'app_id' => '123456',
            'private_key' => $this->generatePrivateKey(),
        ]);
    }

    private function suiteWithPreIntegration(): TestSuite
    {
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $suite->tests()->create(['name' => 'Test', 'playwright_code' => '// noop', 'status' => 'active']);
        $suite->integrations()->create([
            'type' => 'github_action',
            'github_app_id' => GithubApp::first()->id,
            'label' => 'Deploy',
            'config' => ['repository' => 'acme/app', 'workflow' => 'deploy.yml', 'ref' => 'main', 'inputs' => []],
            'enabled' => true,
            'trigger_before' => true,
            'trigger_after' => false,
        ]);

        return $suite;
    }

    private function fakeGithubWorkflow(array $runOverrides = []): void
    {
        Http::fake([
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
        ]);
    }

    public function test_run_with_pre_integration_stays_pending_and_queues_the_job(): void
    {
        Queue::fake();

        $suite = $this->suiteWithPreIntegration();

        $run = app(TestRunService::class)->triggerRun($suite, null, 'manual');

        $run->refresh();
        $this->assertSame('pending', $run->status);
        $this->assertSame('Waiting for pre-run integrations…', $run->status_note);

        Queue::assertPushed(RunPreRunIntegrationsJob::class, fn ($job) => $job->run->id === $run->id);
    }

    public function test_run_without_pre_integrations_starts_immediately(): void
    {
        Queue::fake();

        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $suite->tests()->create(['name' => 'Test', 'playwright_code' => '// noop', 'status' => 'active']);
        // trigger_after only — must NOT block the run.
        $suite->integrations()->create([
            'type' => 'github_action',
            'config' => ['repository' => 'acme/app', 'workflow' => 'deploy.yml'],
            'enabled' => true,
            'trigger_before' => false,
            'trigger_after' => true,
        ]);

        $run = app(TestRunService::class)->triggerRun($suite, null, 'manual');

        $this->assertSame('running', $run->fresh()->status);
        Queue::assertNotPushed(RunPreRunIntegrationsJob::class);
    }

    public function test_disabled_pre_integration_does_not_block(): void
    {
        Queue::fake();

        $suite = $this->suiteWithPreIntegration();
        $suite->integrations()->first()->update(['enabled' => false]);

        $run = app(TestRunService::class)->triggerRun($suite, null, 'manual');

        $this->assertSame('running', $run->fresh()->status);
        Queue::assertNotPushed(RunPreRunIntegrationsJob::class);
    }

    public function test_pre_run_job_starts_the_tests_after_the_workflow_succeeds(): void
    {
        $this->fakeGithubWorkflow();

        // Only fake the per-test job: the pre-run job itself must execute
        // (sync queue) so the full before → startTests flow runs.
        Queue::fake([RunSingleTestJob::class]);

        $suite = $this->suiteWithPreIntegration();

        $run = app(TestRunService::class)->triggerRun($suite, null, 'manual');

        $run->refresh();
        $this->assertSame('running', $run->status);
        $this->assertNull($run->status_note);
        $this->assertSame(1, $run->total_tests);

        Queue::assertPushed(RunSingleTestJob::class, 1);
    }

    public function test_pre_run_job_fails_the_run_when_the_workflow_fails(): void
    {
        $this->fakeGithubWorkflow(['conclusion' => 'failure']);
        Event::fake([TestRunCompleted::class]);
        Queue::fake([RunSingleTestJob::class]);

        $suite = $this->suiteWithPreIntegration();

        $run = app(TestRunService::class)->triggerRun($suite, null, 'manual');

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame(0, $run->total_tests);
        $this->assertStringContainsString("concluded 'failure'", (string) $run->status_note);
        $this->assertNotNull($run->completed_at);

        Queue::assertNotPushed(RunSingleTestJob::class);
        Event::assertDispatched(TestRunCompleted::class, fn (TestRunCompleted $e) => $e->testRun->id === $run->id);
    }

    public function test_pre_run_job_fails_the_run_when_the_workflow_times_out(): void
    {
        config(['sorify.integrations.github_action.pre_run_timeout' => 0]);

        Http::fake([
            self::API.'/repos/acme/app/installation' => Http::response(['id' => 901], 200),
            self::API.'/app/installations/901/access_tokens' => Http::response([
                'token' => 'ghs_installtoken',
                'expires_at' => now()->addHour()->toIso8601String(),
            ], 201),
            self::API.'/repos/acme/app/actions/workflows/deploy.yml/dispatches' => Http::response(status: 204),
            self::API.'/repos/acme/app/actions/workflows/deploy.yml/runs*' => Http::response([
                'total_count' => 0,
                'workflow_runs' => [],
            ], 200),
        ]);

        Event::fake([TestRunCompleted::class]);

        $suite = $this->suiteWithPreIntegration();

        $run = app(TestRunService::class)->triggerRun($suite, null, 'manual');

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('Timed out waiting for the workflow', (string) $run->status_note);
    }

    public function test_pre_run_job_is_a_noop_for_a_cancelled_run(): void
    {
        // Fake everything so the pre-run job doesn't execute inline; we'll
        // run it manually after cancelling the run.
        Queue::fake();
        Event::fake([TestRunCompleted::class]);

        $suite = $this->suiteWithPreIntegration();

        $run = app(TestRunService::class)->triggerRun($suite, null, 'manual');
        $run->update(['status' => 'cancelled', 'completed_at' => now()]);

        // Any HTTP request would explode (500 stub) — the job must bail
        // before contacting GitHub or starting tests.
        Http::fake(['*' => Http::response(status: 500)]);

        (new RunPreRunIntegrationsJob($run->fresh(), null))->handle(
            app(TestRunService::class),
            app(GithubActionIntegrationService::class),
            app(HttpRequestIntegrationService::class),
        );

        $this->assertSame('cancelled', $run->fresh()->status);
        Queue::assertNotPushed(RunSingleTestJob::class);
        Http::assertNothingSent();
        Event::assertNotDispatched(TestRunCompleted::class);
    }

    public function test_ci_webhook_run_with_pre_integration_is_pending(): void
    {
        Queue::fake();

        $suite = $this->suiteWithPreIntegration();

        $this->postJson(route('webhooks.trigger', ['token' => $suite->webhook_token]))
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending');

        // Pending still counts as in-progress for webhook concurrency.
        $this->postJson(route('webhooks.trigger', ['token' => $suite->webhook_token]))
            ->assertStatus(409);
    }

    // ─── HTTP request pre-run integrations ──────────────────────────────────

    private function suiteWithHttpPreIntegration(): TestSuite
    {
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $suite->tests()->create(['name' => 'Test', 'playwright_code' => '// noop', 'status' => 'active']);
        $suite->integrations()->create([
            'type' => 'http_request',
            'label' => 'Deploy hook',
            'config' => [
                'url' => 'https://hooks.example.com/deploy',
                'method' => 'POST',
                'inputs' => ['environment' => 'staging'],
                'headers' => [],
            ],
            'enabled' => true,
            'trigger_before' => true,
            'trigger_after' => false,
        ]);

        return $suite;
    }

    public function test_http_pre_run_integration_lets_the_tests_start_on_success(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response(status: 200)]);
        Queue::fake([RunSingleTestJob::class]);

        $suite = $this->suiteWithHttpPreIntegration();

        $run = app(TestRunService::class)->triggerRun($suite, null, 'manual');

        $run->refresh();
        $this->assertSame('running', $run->status);
        $this->assertNull($run->status_note);
        $this->assertSame(1, $run->total_tests);

        Http::assertSent(function ($request) {
            $body = json_decode((string) $request->body(), true);

            return $request->method() === 'POST'
                && $request->url() === 'https://hooks.example.com/deploy'
                && ($body['environment'] ?? null) === 'staging';
        });

        Queue::assertPushed(RunSingleTestJob::class, 1);
    }

    public function test_http_pre_run_integration_fails_the_run_on_non_2xx(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response(['error' => 'nope'], 503)]);
        Event::fake([TestRunCompleted::class]);
        Queue::fake([RunSingleTestJob::class]);

        $suite = $this->suiteWithHttpPreIntegration();

        $run = app(TestRunService::class)->triggerRun($suite, null, 'manual');

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame(0, $run->total_tests);
        $this->assertStringContainsString("Pre-run integration 'Deploy hook' failed", (string) $run->status_note);
        $this->assertStringContainsString('returned 503', (string) $run->status_note);
        $this->assertNotNull($run->completed_at);

        Queue::assertNotPushed(RunSingleTestJob::class);
        Event::assertDispatched(TestRunCompleted::class, fn (TestRunCompleted $e) => $e->testRun->id === $run->id);
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
