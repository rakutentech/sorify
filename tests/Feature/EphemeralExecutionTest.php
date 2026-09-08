<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\DockerExecutor;
use App\Services\PlaywrightRunnerService;
use App\Services\ScreenshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class EphemeralExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_mode_is_default_when_no_setting_exists(): void
    {
        $this->assertNull(Setting::get('execution_mode'));
    }

    public function test_ephemeral_unreachable_docker_fails_run_with_clear_error(): void
    {
        config()->set('sorify.execution.docker_binary', '/nonexistent/docker');
        config()->set('sorify.execution.docker_host', 'unix:///nonexistent.sock');
        Setting::set('execution_mode', 'ephemeral');

        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $test = $suite->tests()->create([
            'name' => 'T',
            'playwright_code' => 'await page.setContent("<h1>ok</h1>");',
            'status' => 'active',
        ]);
        $run = $suite->testRuns()->create([
            'triggered_by' => 'manual',
            'triggered_by_user_id' => $admin->id,
            'status' => 'running',
            'total_tests' => 1,
            'started_at' => now(),
        ]);

        $tmpDir = storage_path('app/tmp/test-ephemeral-'.Str::uuid());
        File::ensureDirectoryExists($tmpDir);
        config()->set('sorify.tmp_dir', $tmpDir);

        $screenshot = $this->mock(ScreenshotService::class);
        $screenshot->shouldIgnoreMissing();

        $service = new PlaywrightRunnerService($screenshot, new DockerExecutor);
        $result = $service->runSingle($test, $run);

        $this->assertSame('error', $result->status);
        $this->assertStringContainsString(
            'Ephemeral execution mode is enabled but Docker is unavailable',
            $result->error_message
        );

        File::deleteDirectory($tmpDir);
    }

    public function test_local_mode_child_process_env_is_scrubbed(): void
    {
        if (static::nodeUnavailable()) {
            $this->markTestSkipped('node is not available on PATH; skipping env scrub test.');
        }

        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $test = $suite->tests()->create([
            'name' => 'Env probe',
            'playwright_code' => 'await page.setContent("<h1>ok</h1>");',
            'status' => 'active',
        ]);
        $run = $suite->testRuns()->create([
            'triggered_by' => 'manual',
            'triggered_by_user_id' => $admin->id,
            'status' => 'running',
            'total_tests' => 1,
            'started_at' => now(),
        ]);

        $tmpDir = storage_path('app/tmp/test-envscrub-'.Str::uuid());
        File::ensureDirectoryExists($tmpDir);

        $stubPath = $tmpDir.'/stub-runner.cjs';
        File::put($stubPath, <<<'JS'
'use strict';
process.stdout.write('DB_PASSWORD=' + (process.env.DB_PASSWORD || 'none') + '\n');
process.stdout.write('APP_KEY=' + (process.env.APP_KEY || 'none') + '\n');
process.stdout.write('PLAYWRIGHT_BROWSERS_PATH=' + (process.env.PLAYWRIGHT_BROWSERS_PATH || 'none') + '\n');
process.stdout.write('HOME=' + (process.env.HOME || 'none') + '\n');
process.stdout.write(JSON.stringify({ status: 'passed', duration_ms: 0, error_message: null, error_stack: null, screenshots: [] }));
JS);

        config()->set('sorify.runner_script_path', $stubPath);
        config()->set('sorify.tmp_dir', $tmpDir);

        putenv('DB_PASSWORD=leak-me');
        $_ENV['DB_PASSWORD'] = 'leak-me';
        $_SERVER['DB_PASSWORD'] = 'leak-me';
        putenv('APP_KEY=leak-me-too');
        $_ENV['APP_KEY'] = 'leak-me-too';

        // Force the "no explicit browsers path anywhere" case (prod VMs have
        // no PLAYWRIGHT_BROWSERS_PATH; Docker sets it via image ENV).
        $ambientBrowsersPath = getenv('PLAYWRIGHT_BROWSERS_PATH');
        $ambientHome = getenv('HOME');
        putenv('PLAYWRIGHT_BROWSERS_PATH');
        unset($_ENV['PLAYWRIGHT_BROWSERS_PATH'], $_SERVER['PLAYWRIGHT_BROWSERS_PATH']);
        config()->set('sorify.execution.browsers_path', null);

        $screenshot = $this->mock(ScreenshotService::class);
        $screenshot->shouldIgnoreMissing();

        $service = new PlaywrightRunnerService($screenshot, new DockerExecutor);

        try {
            $result = $service->runSingle($test, $run);
        } finally {
            putenv('DB_PASSWORD');
            putenv('APP_KEY');
            unset($_ENV['DB_PASSWORD'], $_SERVER['DB_PASSWORD'], $_ENV['APP_KEY']);
            if ($ambientBrowsersPath !== false) {
                putenv('PLAYWRIGHT_BROWSERS_PATH='.$ambientBrowsersPath);
            }
            if ($ambientHome !== false) {
                putenv('HOME='.$ambientHome);
            }
        }

        $this->assertSame('passed', $result->status, 'Stub runner should report passed. stdout: '.$result->stdout);
        $this->assertStringContainsString('DB_PASSWORD=none', $result->stdout);
        $this->assertStringContainsString('APP_KEY=none', $result->stdout);
        // Unset everywhere => child env must NOT force a browsers path, so
        // Playwright resolves its $HOME/.cache/ms-playwright default (this is
        // the prod-VM case the Docker-path hardcode broke).
        $this->assertStringContainsString('PLAYWRIGHT_BROWSERS_PATH=none', $result->stdout);
        $this->assertStringContainsString('HOME='.($ambientHome ?: 'none'), $result->stdout);
    }

    public function test_local_mode_browsers_path_config_is_passed_to_child(): void
    {
        if (static::nodeUnavailable()) {
            $this->markTestSkipped('node is not available on PATH; skipping browsers path test.');
        }

        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $test = $suite->tests()->create([
            'name' => 'Env probe',
            'playwright_code' => 'await page.setContent("<h1>ok</h1>");',
            'status' => 'active',
        ]);
        $run = $suite->testRuns()->create([
            'triggered_by' => 'manual',
            'triggered_by_user_id' => $admin->id,
            'status' => 'running',
            'total_tests' => 1,
            'started_at' => now(),
        ]);

        $tmpDir = storage_path('app/tmp/test-browserspath-'.Str::uuid());
        File::ensureDirectoryExists($tmpDir);

        $stubPath = $tmpDir.'/stub-runner.cjs';
        File::put($stubPath, <<<'JS'
'use strict';
process.stdout.write('PLAYWRIGHT_BROWSERS_PATH=' + (process.env.PLAYWRIGHT_BROWSERS_PATH || 'none') + '\n');
process.stdout.write(JSON.stringify({ status: 'passed', duration_ms: 0, error_message: null, error_stack: null, screenshots: [] }));
JS);

        config()->set('sorify.runner_script_path', $stubPath);
        config()->set('sorify.tmp_dir', $tmpDir);

        $ambientBrowsersPath = getenv('PLAYWRIGHT_BROWSERS_PATH');
        putenv('PLAYWRIGHT_BROWSERS_PATH');
        unset($_ENV['PLAYWRIGHT_BROWSERS_PATH'], $_SERVER['PLAYWRIGHT_BROWSERS_PATH']);
        config()->set('sorify.execution.browsers_path', '/opt/ms-playwright');

        $screenshot = $this->mock(ScreenshotService::class);
        $screenshot->shouldIgnoreMissing();

        $service = new PlaywrightRunnerService($screenshot, new DockerExecutor);

        try {
            $result = $service->runSingle($test, $run);
        } finally {
            if ($ambientBrowsersPath !== false) {
                putenv('PLAYWRIGHT_BROWSERS_PATH='.$ambientBrowsersPath);
            }
        }

        $this->assertSame('passed', $result->status, 'Stub runner should report passed. stdout: '.$result->stdout);
        $this->assertStringContainsString('PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright', $result->stdout);
    }

    public function test_local_mode_passes_runner_args_with_staging_paths(): void
    {
        if (static::nodeUnavailable()) {
            $this->markTestSkipped('node is not available on PATH; skipping runner wiring test.');
        }

        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create([
            'name' => 'Runner',
            'browser' => 'firefox',
            'take_screenshot' => 'on_failure',
            'created_by' => $admin->id,
        ]);
        $test = $suite->tests()->create([
            'name' => 'args probe',
            'playwright_code' => 'await page.setContent("<h1>ok</h1>");',
            'status' => 'active',
        ]);
        $run = $suite->testRuns()->create([
            'triggered_by' => 'manual',
            'triggered_by_user_id' => $admin->id,
            'status' => 'running',
            'total_tests' => 1,
            'started_at' => now(),
        ]);

        $tmpDir = storage_path('app/tmp/test-args-'.Str::uuid());
        File::ensureDirectoryExists($tmpDir);

        $stubPath = $tmpDir.'/stub-runner.cjs';
        File::put($stubPath, <<<'JS'
'use strict';
process.stdout.write('ARGV=' + process.argv.slice(2).join(' ') + '\n');
process.stdout.write(JSON.stringify({ status: 'passed', duration_ms: 0, error_message: null, error_stack: null, screenshots: [] }));
JS);

        config()->set('sorify.runner_script_path', $stubPath);
        config()->set('sorify.tmp_dir', $tmpDir);

        $screenshot = $this->mock(ScreenshotService::class);
        $screenshot->shouldIgnoreMissing();

        $service = new PlaywrightRunnerService($screenshot, new DockerExecutor);
        $result = $service->runSingle($test, $run);

        $this->assertSame('passed', $result->status, 'Stub runner should report passed. stdout: '.$result->stdout);

        $argvLine = collect(explode("\n", $result->stdout))->first(fn ($l) => str_starts_with($l, 'ARGV='));
        $this->assertNotNull($argvLine);
        $argv = substr($argvLine, 5);

        $this->assertStringContainsString('--spec '.$tmpDir.'/run-'.$run->id.'-'.$test->id.'/work/spec.js', $argv);
        $this->assertStringContainsString('--output '.$tmpDir.'/run-'.$run->id.'-'.$test->id.'/out', $argv);
        $this->assertStringContainsString('--browser firefox', $argv);
        $this->assertStringContainsString('--screenshot-mode on_failure', $argv);
        $this->assertStringContainsString('--headless true', $argv);

        File::deleteDirectory($tmpDir);
    }

    private static function nodeUnavailable(): bool
    {
        exec('command -v node 2>/dev/null', $output, $exitCode);

        return $exitCode !== 0;
    }
}
