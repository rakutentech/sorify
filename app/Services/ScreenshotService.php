<?php

namespace App\Services;

use App\Models\Screenshot;
use App\Models\TestResult;
use Illuminate\Support\Facades\Storage;

class ScreenshotService
{
    public function storeFromRunOutput(TestResult $result, array $screenshotPayloads, string $tmpOutputDir): void
    {
        $destDir = $this->storagePath(
            $result->testRun->test_suite_id,
            $result->test_run_id,
            $result->test_id
        );

        foreach ($screenshotPayloads as $payload) {
            $filename = $payload['filename'] ?? null;
            if (! $filename) {
                continue;
            }

            $sourcePath = rtrim($tmpOutputDir, '/') . '/' . $filename;
            $destPath   = $destDir . '/' . $filename;

            if (file_exists($sourcePath)) {
                Storage::disk('screenshots')->put(
                    $destPath,
                    file_get_contents($sourcePath)
                );

                Screenshot::create([
                    'test_result_id' => $result->id,
                    'filename'       => $filename,
                    'path'           => $destPath,
                    'label'          => $payload['label'] ?? null,
                    'taken_at_ms'    => $payload['taken_at_ms'] ?? 0,
                    'created_at'     => now(),
                ]);
            }
        }
    }

    public function storagePath(int $suiteId, int $runId, int $testId): string
    {
        return "{$suiteId}/{$runId}/{$testId}";
    }

    public function deleteResultFiles(TestResult $result): void
    {
        $dir = $this->storagePath(
            $result->testRun->test_suite_id,
            $result->test_run_id,
            $result->test_id,
        );

        Storage::disk('screenshots')->deleteDirectory($dir);
    }

    public function cleanTmpDir(string $dir): void
    {
        if (is_dir($dir)) {
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }
    }

    public function pruneOlderThan(int $days): int
    {
        $stale = Screenshot::where('created_at', '<', now()->subDays($days))->get();

        foreach ($stale as $screenshot) {
            Storage::disk('screenshots')->delete($screenshot->path);
        }

        Screenshot::whereIn('id', $stale->pluck('id'))->delete();

        return $stale->count();
    }
}
