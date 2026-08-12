<?php

namespace App\Services;

use App\Models\Test;
use App\Models\TestCodeVersion;

class TestCodeVersionService
{
    public function updateCode(Test $test, string $newCode, string $source, ?int $userId): Test
    {
        if ($test->playwright_code !== null && $test->playwright_code !== $newCode) {
            $nextVersion = ($test->codeVersions()->max('version_number') ?? 0) + 1;

            $test->codeVersions()->create([
                'version_number'  => $nextVersion,
                'playwright_code' => $test->playwright_code,
                'source'          => $source,
                'created_by'      => $userId,
            ]);

            $this->prune($test);
        }

        $test->update([
            'playwright_code' => $newCode,
            'status'          => 'active',
        ]);

        return $test;
    }

    public function restore(Test $test, TestCodeVersion $version, string $source, ?int $userId): Test
    {
        return $this->updateCode($test, $version->playwright_code, $source, $userId);
    }

    private function prune(Test $test): void
    {
        $keep = (int) config('sorify.test_code_version_retention');

        $test->codeVersions()
            ->orderByDesc('version_number')
            ->skip($keep)
            ->take(PHP_INT_MAX)
            ->pluck('id')
            ->each(fn ($id) => TestCodeVersion::destroy($id));
    }
}
