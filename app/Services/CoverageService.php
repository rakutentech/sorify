<?php

namespace App\Services;

use App\Models\TestResult;
use App\Models\TestRun;
use Illuminate\Support\Facades\Storage;

/**
 * Per-test Chromium V8 coverage artifacts (raw coverage.json files written by
 * harness.cjs) and per-run merged reports (HTML + lcov.info written by
 * coverage.cjs), all on the private `coverage` disk.
 *
 * Layout: {suiteId}/{runId}/
 *   {testId}.json       raw V8 entries for one test
 *   report/…            merged HTML report (index.html + assets/)
 *   lcov.info           merged LCOV interchange file
 */
class CoverageService
{
    public function storeFromRunOutput(TestResult $result, string $tmpOutputDir): bool
    {
        $source = rtrim($tmpOutputDir, '/').'/coverage.json';

        if (! file_exists($source)) {
            return false;
        }

        $destPath = $this->resultPath($result->testRun->test_suite_id, $result->test_run_id, $result->test_id);

        Storage::disk('coverage')->put($destPath, file_get_contents($source));

        return true;
    }

    public function resultPath(int $suiteId, int $runId, int $testId): string
    {
        return "{$suiteId}/{$runId}/{$testId}.json";
    }

    public function runDir(int $suiteId, int $runId): string
    {
        return "{$suiteId}/{$runId}";
    }

    /**
     * All raw per-test coverage files recorded for a run.
     *
     * @return array<int, string>
     */
    public function runResultPaths(TestRun $run): array
    {
        $dir = $this->runDir($run->test_suite_id, $run->id);

        return Storage::disk('coverage')->files($dir);
    }

    public function reportPath(TestRun $run): string
    {
        return $this->runDir($run->test_suite_id, $run->id).'/report/index.html';
    }

    public function lcovPath(TestRun $run): string
    {
        return $this->runDir($run->test_suite_id, $run->id).'/lcov.info';
    }

    /**
     * Store a file inside the run's report directory (HTML + assets),
     * normalizing any traversal-ish path segments.
     */
    public function storeReportFile(TestRun $run, string $relativePath, string $contents): void
    {
        $relativePath = ltrim(str_replace(['..', '\\'], ['', '/'], $relativePath), '/');
        if ($relativePath === '' || $relativePath === '/') {
            return;
        }

        Storage::disk('coverage')->put(
            $this->runDir($run->test_suite_id, $run->id).'/report/'.$relativePath,
            $contents
        );
    }

    public function storeLcov(TestRun $run, string $contents): void
    {
        Storage::disk('coverage')->put($this->lcovPath($run), $contents);
    }

    public function deleteResultFiles(TestResult $result): void
    {
        Storage::disk('coverage')->delete(
            $this->resultPath($result->testRun->test_suite_id, $result->test_run_id, $result->test_id)
        );
    }

    public function deleteRunFiles(TestRun $run): void
    {
        Storage::disk('coverage')->deleteDirectory($this->runDir($run->test_suite_id, $run->id));
    }
}
