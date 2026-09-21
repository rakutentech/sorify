<?php

namespace App\Services;

use App\Models\Test;

class HistoryPruningService
{
    public function __construct(
        private readonly ScreenshotService $screenshotService,
        private readonly CoverageService $coverageService,
    ) {}

    public function pruneTestHistory(Test $test, int $keep): int
    {
        $stale = $test->testResults()
            ->with('testRun:id,test_suite_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->skip($keep)
            ->take(PHP_INT_MAX)
            ->get();

        foreach ($stale as $result) {
            $this->screenshotService->deleteResultFiles($result);
            $this->coverageService->deleteResultFiles($result);
            $result->delete();
        }

        return $stale->count();
    }
}
