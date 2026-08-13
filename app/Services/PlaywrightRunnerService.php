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
        private readonly HistoryPruningService $pruningService,
    ) {
        $this->runnerScript = config('sorify.runner_script_path');
        $this->tmpDir       = config('sorify.tmp_dir');
        $this->timeoutMs    = (int) config('sorify.max_test_timeout_ms', 30000);
    }

    public function run(TestRun $testRun, ?array $testIds = null): void
    {
        $query = $testRun->testSuite->activeTests();
        if ($testIds !== null) {
            $query->whereIn('id', $testIds);
        }
        $tests = $query->get();

        $testRun->update([
            'total_tests' => $tests->count(),
            'started_at'  => now(),
            'status'      => 'running',
        ]);

        $passed = $failed = $errors = 0;
        $start  = now();

        foreach ($tests as $test) {
            if ($testRun->refresh()->status === 'cancelled') {
                break;
            }

            $result = $this->runSingle($test, $testRun);

            match ($result->status) {
                'passed'            => $passed++,
                'failed', 'timeout' => $failed++,
                'cancelled'         => null,
                default             => $errors++,
            };

            $test->update([
                'last_run_at'     => now(),
                'last_run_status' => in_array($result->status, ['passed', 'failed', 'error', 'timeout', 'cancelled'])
                    ? $result->status
                    : 'error',
            ]);

            $this->pruningService->pruneTestHistory($test, $testRun->testSuite->history_retention ?? 5);

            if ($testRun->refresh()->status === 'cancelled') {
                break;
            }
        }

        $testRun->refresh();

        $testRun->update([
            'passed_count'  => $passed,
            'failed_count'  => $failed,
            'error_count'   => $errors,
            'duration_ms'   => $start->diffInMilliseconds(now()),
            'completed_at'  => now(),
            'status'        => $testRun->status === 'cancelled' ? 'cancelled' : 'completed',
        ]);
    }

    public function runSingle(Test $test, TestRun $testRun): TestResult
    {
        if (empty($test->playwright_code)) {
            return $this->createErrorResult($testRun, $test, 'Test has no Playwright code. Please upload code via the API before running.');
        }

        @mkdir($this->tmpDir, 0755, true);

        $specPath     = $this->tmpDir . "/test-{$test->id}-{$testRun->id}.spec.js";
        $outputDir    = $this->tmpDir . "/output-{$testRun->id}-{$test->id}";
        $pacPath      = null;

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

            // A configured PAC script takes priority over a plain HTTP proxy URL.
            $proxyPac = $testRun->testSuite->playwright_proxy_pac ?? null;
            $proxy    = $testRun->testSuite->playwright_proxy ?? null;
            if ($proxyPac) {
                $pacPath = $this->tmpDir . "/proxy-{$testRun->id}-{$test->id}.pac";
                file_put_contents($pacPath, $proxyPac);
                $command[] = '--proxy-pac';
                $command[] = $pacPath;
            } elseif ($proxy) {
                $command[] = '--proxy';
                $command[] = $proxy;
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

            $cancelled = false;
            while ($process->isRunning()) {
                $process->checkTimeout();

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
            if ($pacPath) {
                @unlink($pacPath);
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
