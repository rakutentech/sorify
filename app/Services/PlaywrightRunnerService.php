<?php

namespace App\Services;

use App\Models\Test;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Support\ScreenshotMode;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class PlaywrightRunnerService
{
    private string $runnerScript;

    private string $tmpDir;

    private int $timeoutMs;

    public function __construct(
        private readonly ScreenshotService $screenshotService,
        private readonly DockerExecutor $docker,
    ) {
        $this->runnerScript = config('sorify.runner_script_path');
        $this->tmpDir = config('sorify.tmp_dir');
        $this->timeoutMs = (int) config('sorify.max_test_timeout_ms', 30000);
    }

    public function runWithRetries(Test $test, TestRun $testRun): TestResult
    {
        $maxAttempts = 1 + max(0, (int) ($testRun->testSuite->max_retries ?? 0));

        $result = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($result) {
                $this->screenshotService->deleteResultFiles($result);
                $result->delete();
            }

            $result = $this->runSingle($test, $testRun);

            if (in_array($result->status, ['passed', 'cancelled'], true)) {
                break;
            }
        }

        return $result;
    }

    public function runSingle(Test $test, TestRun $testRun): TestResult
    {
        if (empty($test->playwright_code)) {
            return $this->createErrorResult($testRun, $test, 'Test has no Playwright code. Please upload code via the API before running.');
        }

        $mode = ExecutionMode::current();

        $runDir = $this->tmpDir."/run-{$testRun->id}-{$test->id}";
        $workDir = $runDir.'/work';
        $outDir = $runDir.'/out';
        File::ensureDirectoryExists($workDir, 0755);
        File::ensureDirectoryExists($outDir, 0755);

        file_put_contents($workDir.'/spec.js', $test->playwright_code);

        $result = TestResult::create([
            'test_run_id' => $testRun->id,
            'test_id' => $test->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $containerName = null;

        try {
            $timeoutMs = $testRun->testSuite->timeout_ms ?? $this->timeoutMs;

            // Path placeholders (SPEC, OUTPUT, ...) are substituted per mode below
            // so both execution modes share one arg list.
            $runnerArgs = ['--spec', 'SPEC', '--output', 'OUTPUT', '--timeout', (string) $timeoutMs];

            $proxy = $testRun->testSuite->playwright_proxy ?: null;
            if ($proxy) {
                $runnerArgs[] = '--proxy';
                $runnerArgs[] = $proxy;
            }

            $proxyRules = $testRun->testSuite->proxyRules;
            if ($proxyRules->isNotEmpty()) {
                file_put_contents($workDir.'/proxy-rules.json', $proxyRules->map(fn ($rule) => [
                    'domain' => $rule->domain,
                    'proxy' => $rule->proxy,
                ])->values()->toJson());
                $runnerArgs[] = '--proxy-rules';
                $runnerArgs[] = 'PROXY_RULES';
            }

            $variables = $testRun->testSuite->variables;
            if ($variables->isNotEmpty()) {
                file_put_contents($workDir.'/variables.json', json_encode(
                    $variables->mapWithKeys(fn ($v) => [$v->key => $v->value])->all(),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ));
                $runnerArgs[] = '--variables';
                $runnerArgs[] = 'VARIABLES';
            }

            $cookies = $testRun->testSuite->cookies;
            if ($cookies->isNotEmpty()) {
                file_put_contents($workDir.'/cookies.json', $cookies->map(fn ($c) => [
                    'name' => $c->name,
                    'value' => $c->value ?? '',
                    'domain' => $c->domain,
                    'path' => $c->path,
                    'url' => $c->url,
                    'expires' => $c->expires,
                    'httpOnly' => (bool) $c->http_only,
                    'secure' => (bool) $c->secure,
                    'sameSite' => $c->same_site,
                ])->values()->toJson(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $runnerArgs[] = '--cookies';
                $runnerArgs[] = 'COOKIES';
            }

            $screenshotMode = $testRun->testSuite->take_screenshot ?? 'enabled';
            if (! in_array($screenshotMode, ScreenshotMode::ALL, true)) {
                $screenshotMode = 'enabled';
            }

            $runnerArgs[] = '--browser';
            $runnerArgs[] = $testRun->testSuite->browser ?? 'chromium';
            $runnerArgs[] = '--headless';
            $runnerArgs[] = ($testRun->testSuite->headless ?? true) ? 'true' : 'false';
            $runnerArgs[] = '--screenshot-mode';
            $runnerArgs[] = $screenshotMode;

            if ($mode === ExecutionMode::EPHEMERAL) {
                $paths = [
                    'SPEC' => '/work/spec.js',
                    'OUTPUT' => '/out',
                    'PROXY_RULES' => '/work/proxy-rules.json',
                    'VARIABLES' => '/work/variables.json',
                    'COOKIES' => '/work/cookies.json',
                ];
                $runnerArgs = array_map(fn ($arg) => $paths[$arg] ?? $arg, $runnerArgs);

                $command = $this->docker->buildRunnerCommand($workDir, $outDir, $runnerArgs, $testRun->id, $test->id);
                $containerName = $this->docker->containerName($testRun->id, $test->id);
                $env = $this->docker->cliEnv();
            } else {
                $paths = [
                    'SPEC' => $workDir.'/spec.js',
                    'OUTPUT' => $outDir,
                    'PROXY_RULES' => $workDir.'/proxy-rules.json',
                    'VARIABLES' => $workDir.'/variables.json',
                    'COOKIES' => $workDir.'/cookies.json',
                ];
                $runnerArgs = array_map(fn ($arg) => $paths[$arg] ?? $arg, $runnerArgs);

                // Safety net: drop the node child to the unprivileged test user
                // (setpriv + prlimit caps) when configured — see DockerExecutor::localRunnerPrefix.
                $prefix = $this->docker->localRunnerPrefix();
                if ($prefix) {
                    @chmod($workDir, 0777);
                    @chmod($outDir, 0777);
                }

                $command = array_merge($prefix, ['node', $this->runnerScript], $runnerArgs);
                $env = $this->localEnv();
            }

            $process = new Process(
                command: $command,
                env: $env,
                timeout: ($timeoutMs / 1000) + 10
            );

            $process->start();

            $cancelled = false;
            $liveStdout = '';
            $liveStderr = '';
            $lastFlush = 0.0;

            while ($process->isRunning()) {
                $process->checkTimeout();

                $liveStdout .= $process->getIncrementalOutput();
                $liveStderr .= $process->getIncrementalErrorOutput();

                // Throttle DB writes to ~1/sec instead of every 300ms poll tick.
                if (microtime(true) - $lastFlush >= 1) {
                    $result->update(['stdout' => $liveStdout, 'stderr' => $liveStderr]);
                    $lastFlush = microtime(true);
                }

                if ($testRun->refresh()->status === 'cancelled') {
                    $cancelled = true;
                    $process->stop(3);
                    break;
                }

                usleep(300_000);
            }

            if ($cancelled) {
                $result->update([
                    'status' => 'cancelled',
                    'error_message' => 'Run was cancelled',
                    'completed_at' => now(),
                ]);

                return $result->refresh();
            }

            $rawOutput = trim($process->getOutput());
            $stderr = $process->getErrorOutput();

            // Test code may emit console.log lines before the JSON result.
            // Extract the last line that starts with '{' — that's the runner payload.
            $jsonLine = null;
            foreach (array_reverse(explode("\n", $rawOutput)) as $line) {
                $line = trim($line);
                if (str_starts_with($line, '{')) {
                    $jsonLine = $line;
                    break;
                }
            }
            $payload = $jsonLine ? json_decode($jsonLine, true) : null;

            if (! is_array($payload)) {
                $errorMessage = 'Runner produced no valid JSON output';
                if ($mode === ExecutionMode::EPHEMERAL) {
                    $firstStderrLine = strtok(trim($stderr) ?: $rawOutput, "\n") ?: 'unknown error';
                    $errorMessage = 'Ephemeral execution mode is enabled but Docker is unavailable: '.$firstStderrLine;
                }

                $result->update([
                    'status' => 'error',
                    'stderr' => $stderr ?: 'No JSON output from runner',
                    'error_message' => $errorMessage,
                    'completed_at' => now(),
                ]);

                return $result;
            }

            $result->update([
                'status' => $payload['status'] ?? 'error',
                'duration_ms' => $payload['duration_ms'] ?? null,
                'stdout' => $rawOutput,
                'stderr' => $stderr,
                'error_message' => $payload['error_message'] ?? null,
                'error_stack' => $payload['error_stack'] ?? null,
                'completed_at' => now(),
            ]);

            if (! empty($payload['screenshots'])) {
                $this->screenshotService->storeFromRunOutput(
                    $result,
                    $payload['screenshots'],
                    $outDir
                );
            }
        } catch (\Throwable $e) {
            Log::error('Playwright runner error', ['test_id' => $test->id, 'error' => $e->getMessage()]);

            $errorMessage = $e->getMessage();
            if ($mode === ExecutionMode::EPHEMERAL) {
                $errorMessage = 'Ephemeral execution mode is enabled but Docker is unavailable: '.$errorMessage;
            }

            $result->update([
                'status' => 'error',
                'error_message' => $errorMessage,
                'error_stack' => $e->getTraceAsString(),
                'completed_at' => now(),
            ]);
        } finally {
            if ($containerName) {
                $this->docker->removeContainer($containerName);
            }
            File::deleteDirectory($runDir);
            if (! $result->completed_at) {
                $result->update(['completed_at' => now()]);
            }
        }

        return $result->refresh();
    }

    /**
     * Browser resolution: honor an ambient PLAYWRIGHT_BROWSERS_PATH (set by
     * the Docker image) or the SORIFY_BROWSERS_PATH config override. With
     * neither set, Playwright falls back to $HOME/.cache/ms-playwright — so
     * the real HOME must be passed through instead of /tmp, and the env var
     * must stay out of the child env entirely (an empty value would resolve
     * to the filesystem root, not the HOME-based default).
     *
     * @return array<string, string>
     */
    private function localEnv(): array
    {
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'NODE_ENV' => 'production',
        ];

        $browsersPath = getenv('PLAYWRIGHT_BROWSERS_PATH')
            ?: (string) (config('sorify.execution.browsers_path') ?: '');

        if ($browsersPath !== '') {
            $env['PLAYWRIGHT_BROWSERS_PATH'] = $browsersPath;
            $env['HOME'] = '/tmp';
        } else {
            $env['HOME'] = getenv('HOME') ?: '/tmp';
        }

        return $this->docker->scrubbedEnv($env);
    }

    private function createErrorResult(TestRun $testRun, Test $test, string $message): TestResult
    {
        return TestResult::create([
            'test_run_id' => $testRun->id,
            'test_id' => $test->id,
            'status' => 'error',
            'error_message' => $message,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
