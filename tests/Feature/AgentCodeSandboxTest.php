<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\CoverageService;
use App\Services\DockerExecutor;
use App\Services\PlaywrightRunnerService;
use App\Services\ScreenshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Environment guard: code written by the chat agent only executes inside
 * the sandboxed (Docker/ephemeral) executor. In local mode the runner is
 * a raw node process on the host, so agent-authored tests are refused
 * there until a human takes ownership of the code (saving it from the
 * dashboard re-tags its source).
 */
class AgentCodeSandboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_written_code_is_refused_in_local_mode(): void
    {
        $service = $this->runnerService();

        $test = $this->makeTest('agent');
        $run = $this->makeRun($test);

        $result = $service->runSingle($test, $run);

        $this->assertSame('error', $result->status);
        $this->assertStringContainsString('only allowed to run in sandboxed', $result->error_message);
    }

    public function test_human_saved_code_runs_in_local_mode(): void
    {
        // A human edited and saved the code from the dashboard — the
        // guard must let it through to normal local execution.
        $service = $this->runnerService(runner: '/nonexistent/runner.cjs');

        $test = $this->makeTest('manual');
        $run = $this->makeRun($test);

        $result = $service->runSingle($test, $run);

        // The bogus runner script fails (no JSON output) — but NOT with
        // the sandbox refusal, so the guard let the code through.
        $this->assertSame('error', $result->status);
        $this->assertStringContainsString('Runner produced no valid JSON output', $result->error_message);
    }

    public function test_agent_written_code_runs_in_ephemeral_mode(): void
    {
        // Sandboxed execution is exactly where agent code belongs — the
        // guard must not block it there.
        config()->set('sorify.execution.docker_binary', '/nonexistent/docker');
        config()->set('sorify.execution.docker_host', 'unix:///nonexistent.sock');
        Setting::set('execution_mode', 'ephemeral');

        $service = $this->runnerService();

        $test = $this->makeTest('agent');
        $run = $this->makeRun($test);

        $result = $service->runSingle($test, $run);

        // It got past the guard and died on the (unreachable) Docker
        // binary instead.
        $this->assertSame('error', $result->status);
        $this->assertStringContainsString('Ephemeral execution mode is enabled but Docker is unavailable', $result->error_message);
    }

    public function test_the_guard_can_be_disabled_by_config(): void
    {
        config(['sorify.agent.require_sandboxed_execution' => false]);

        $service = $this->runnerService(runner: '/nonexistent/runner.cjs');

        $test = $this->makeTest('agent');
        $run = $this->makeRun($test);

        $result = $service->runSingle($test, $run);

        $this->assertStringNotContainsString('sandboxed', (string) $result->error_message);
    }

    public function test_mcp_written_code_is_not_caught_by_the_guard(): void
    {
        // External MCP/REST callers drive their own review flow — the
        // guard targets the chat agent's autonomous writes.
        $service = $this->runnerService(runner: '/nonexistent/runner.cjs');

        $test = $this->makeTest('mcp');
        $run = $this->makeRun($test);

        $result = $service->runSingle($test, $run);

        $this->assertStringNotContainsString('sandboxed', (string) $result->error_message);
    }

    /**
     * The runner service with an isolated tmp dir; a bogus runner script
     * makes local-mode execution fail fast without spawning real tests.
     */
    private function runnerService(string $runner = 'resources/playwright/runner.cjs'): PlaywrightRunnerService
    {
        $tmpDir = storage_path('app/tmp/test-sandbox-'.Str::uuid());
        File::ensureDirectoryExists($tmpDir);
        config()->set('sorify.tmp_dir', $tmpDir);

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($tmpDir));

        config()->set('sorify.runner_script_path', $runner);

        return new PlaywrightRunnerService(
            $this->mock(ScreenshotService::class)->shouldIgnoreMissing(),
            $this->mock(CoverageService::class)->shouldIgnoreMissing(),
            new DockerExecutor,
        );
    }

    private function makeTest(string $codeSource): \App\Models\Test
    {
        $admin = User::factory()->admin()->create();

        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        return $suite->tests()->create([
            'name' => 'T',
            'playwright_code' => 'await page.setContent("<h1>ok</h1>");',
            'status' => 'active',
            'code_source' => $codeSource,
        ]);
    }

    private function makeRun(\App\Models\Test $test): \App\Models\TestRun
    {
        return $test->testSuite->testRuns()->create([
            'triggered_by' => 'manual',
            'triggered_by_user_id' => User::factory()->create()->id,
            'status' => 'running',
            'total_tests' => 1,
            'started_at' => now(),
        ]);
    }
}
