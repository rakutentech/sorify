<?php

namespace App\Services;

use App\Exceptions\PlaywrightExecutionException;
use App\Models\Test;
use App\Models\TestResult;
use App\Models\TestRun;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class PlaywrightRunnerService
{
    private string $runnerScript;
    private string $tmpDir;
    private int $timeoutMs;

    public function __construct(
        private readonly ScreenshotService $screenshotService,
    ) {
        $this->runnerScript = config('sorify.runner_script_path');
        $this->tmpDir       = config('sorify.tmp_dir');
        $this->timeoutMs    = (int) config('sorify.max_test_timeout_ms', 30000);
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

        @mkdir($this->tmpDir, 0755, true);

        $specPath       = $this->tmpDir . "/test-{$test->id}-{$testRun->id}.spec.js";
        $outputDir      = $this->tmpDir . "/output-{$testRun->id}-{$test->id}";
        $proxyRulesPath = null;

        @mkdir($outputDir, 0755, true);

        file_put_contents($specPath, $test->playwright_code);

        $result = TestResult::create([
            'test_run_id' => $testRun->id,
            'test_id'     => $test->id,
            'status'      => 'running',
            'started_at'  => now(),
        ]);

        try {
            $timeoutMs = $testRun->testSuite->timeout_ms ?? $this->timeoutMs;

            $command = [
                'node',
                $this->runnerScript,
                '--spec',    $specPath,
                '--output',  $outputDir,
                '--timeout', (string) $timeoutMs,
            ];

            $proxy = $testRun->testSuite->playwright_proxy ?: null;
            if ($proxy) {
                $command[] = '--proxy';
                $command[] = $proxy;
            }

            $proxyRules = $testRun->testSuite->proxyRules;
            if ($proxyRules->isNotEmpty()) {
                $proxyRulesPath = $this->tmpDir . "/proxy-rules-{$testRun->id}-{$test->id}.json";
                file_put_contents($proxyRulesPath, $proxyRules->map(fn ($rule) => [
                    'domain' => $rule->domain,
                    'proxy'  => $rule->proxy,
                ])->values()->toJson());
                $command[] = '--proxy-rules';
                $command[] = $proxyRulesPath;
            }

            $browser = $testRun->testSuite->browser ?? 'chromium';
            $command[] = '--browser';
            $command[] = $browser;

            $headless = $testRun->testSuite->headless ?? true;
            $command[] = '--headless';
            $command[] = $headless ? 'true' : 'false';

            $takeScreenshot = $testRun->testSuite->take_screenshot ?? true;
            $command[] = '--take-screenshot';
            $command[] = $takeScreenshot ? 'true' : 'false';

            $process = new Process(
                command: $command,
                timeout: ($timeoutMs / 1000) + 10
            );

            $process->start();

            $cancelled  = false;
            $liveStdout = '';
            $liveStderr = '';
            $lastFlush  = 0.0;

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
                    'status'        => 'cancelled',
                    'error_message' => 'Run was cancelled',
                    'completed_at'  => now(),
                ]);
                return $result->refresh();
            }

            $rawOutput = trim($process->getOutput());
            $stderr    = $process->getErrorOutput();

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
                $result->update([
                    'status'        => 'error',
                    'stderr'        => $stderr ?: 'No JSON output from runner',
                    'error_message' => 'Runner produced no valid JSON output',
                    'completed_at'  => now(),
                ]);
                return $result;
            }

            $result->update([
                'status'        => $payload['status'] ?? 'error',
                'duration_ms'   => $payload['duration_ms'] ?? null,
                'stdout'        => $rawOutput,
                'stderr'        => $stderr,
                'error_message' => $payload['error_message'] ?? null,
                'error_stack'   => $payload['error_stack'] ?? null,
                'completed_at'  => now(),
            ]);

            if (! empty($payload['screenshots'])) {
                $this->screenshotService->storeFromRunOutput(
                    $result,
                    $payload['screenshots'],
                    $outputDir
                );
            }
        } catch (\Throwable $e) {
            Log::error('Playwright runner error', ['test_id' => $test->id, 'error' => $e->getMessage()]);

            $result->update([
                'status'        => 'error',
                'error_message' => $e->getMessage(),
                'error_stack'   => $e->getTraceAsString(),
                'completed_at'  => now(),
            ]);
        } finally {
            @unlink($specPath);
            if ($proxyRulesPath) {
                @unlink($proxyRulesPath);
            }
            $this->screenshotService->cleanTmpDir($outputDir);
            if (! $result->completed_at) {
                $result->update(['completed_at' => now()]);
            }
        }

        return $result->refresh();
    }

    private function createErrorResult(TestRun $testRun, Test $test, string $message): TestResult
    {
        return TestResult::create([
            'test_run_id'   => $testRun->id,
            'test_id'       => $test->id,
            'status'        => 'error',
            'error_message' => $message,
            'started_at'    => now(),
            'completed_at'  => now(),
        ]);
    }
}
