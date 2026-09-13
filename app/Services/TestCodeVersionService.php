<?php

namespace App\Services;

use App\Models\Test;
use App\Models\TestCodeVersion;

class TestCodeVersionService
{
    /**
     * Replace a test's Playwright code.
     *
     * The archived version row keeps the attribution of the code being
     * replaced (its ai_model = the model that WROTE that code, captured
     * from the test row before it is overwritten), while the test row
     * receives the attribution of the incoming code.
     */
    public function updateCode(Test $test, string $newCode, string $source, ?int $userId, ?string $aiModel = null): Test
    {
        if ($test->playwright_code !== null && $test->playwright_code !== $newCode) {
            $nextVersion = ($test->codeVersions()->max('version_number') ?? 0) + 1;

            $test->codeVersions()->create([
                'version_number' => $nextVersion,
                'playwright_code' => $test->playwright_code,
                'ai_model' => $test->code_ai_model,
                'source' => $source,
                'created_by' => $userId,
            ]);

            $this->prune($test);
        }

        $test->update([
            'playwright_code' => $newCode,
            'code_source' => $source,
            'code_ai_model' => $aiModel,
            'status' => 'active',
        ]);

        return $test;
    }

    public function restore(Test $test, TestCodeVersion $version, string $source, ?int $userId): Test
    {
        // Restoring brings back the code AND the attribution of whoever
        // originally wrote it.
        return $this->updateCode($test, $version->playwright_code, $source, $userId, $version->ai_model);
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
