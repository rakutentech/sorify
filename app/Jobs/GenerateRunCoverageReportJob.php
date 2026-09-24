<?php

namespace App\Jobs;

use App\Models\TestRun;
use App\Services\CoverageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class GenerateRunCoverageReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 12;

    public int $timeout = 600;

    public function __construct(public readonly TestRun $testRun)
    {
        $this->onQueue('sorify');
    }

    public function handle(CoverageService $coverage): void
    {
        $run = $this->testRun->refresh();

        // Finalization safeguard: only aggregate once every test result of the
        // run is complete. The batch's finally-callback dispatches this job
        // after the run was claimed, but a timed-out worker can leave a
        // result row behind that is closed out only a moment later — wait for
        // it instead of producing a report that misses that test's coverage.
        if (! in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
            $this->release(10);

            return;
        }

        if ($run->testResults()->where('status', 'running')->exists()) {
            $this->release(10);

            return;
        }

        $files = $coverage->runResultPaths($run);

        if (empty($files)) {
            // Nothing was collected (e.g. the target served no scripts, or
            // the filter matched none) — record it so the run page shows why
            // there is no coverage card instead of failing silently.
            $run->forceFill(['coverage_summary' => ['status' => 'empty']])->save();

            return;
        }

        $tmpDir = config('sorify.tmp_dir')."/coverage-{$run->id}";
        File::ensureDirectoryExists($tmpDir, 0755);

        try {
            // coverage.cjs reads plain filesystem paths, so disk-relative
            // storage keys are resolved to their absolute local paths first.
            $disk = Storage::disk('coverage');
            $absoluteFiles = array_map(fn (string $relative) => $disk->path($relative), $files);

            $filesList = $tmpDir.'/files.json';
            file_put_contents($filesList, json_encode($absoluteFiles));

            $outDir = $tmpDir.'/out';
            File::ensureDirectoryExists($outDir, 0755);

            $command = [
                'node',
                config('sorify.coverage_script_path'),
                '--files', $filesList,
                '--output', $outDir,
            ];

            if ($filter = $run->testSuite->coverage_url_filter) {
                $command[] = '--filter';
                $command[] = $filter;
            }

            $process = new Process(
                command: $command,
                env: [
                    'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
                    'NODE_ENV' => 'production',
                ],
                timeout: 540,
            );
            $process->run();

            // The script writes its JSON payload on the last stdout line.
            $payload = null;
            foreach (array_reverse(explode("\n", trim($process->getOutput()))) as $line) {
                $line = trim($line);
                if (str_starts_with($line, '{')) {
                    $payload = json_decode($line, true);
                    break;
                }
            }

            if (($payload['status'] ?? null) !== 'ok') {
                Log::warning('Run coverage report generation failed', [
                    'run_id' => $run->id,
                    'stderr' => mb_substr($process->getErrorOutput(), 0, 2000),
                    'payload' => $payload,
                ]);

                // Surface the failure on the run page (coverage card shows
                // an error notice) instead of the summary silently never
                // appearing.
                $run->forceFill(['coverage_summary' => [
                    'status' => 'failed',
                    'error' => mb_substr((string) ($payload['error'] ?? 'Unknown coverage report error'), 0, 500),
                ]])->save();

                return;
            }

            $this->persistArtifacts($coverage, $run, $outDir);

            $run->forceFill(['coverage_summary' => [
                'status' => 'ok',
                'lines' => $payload['summary']['lines'] ?? null,
                'functions' => $payload['summary']['functions'] ?? null,
                'branches' => $payload['summary']['branches'] ?? null,
                'statements' => $payload['summary']['statements'] ?? null,
                'tests' => count($files),
                'entries' => $payload['entries'] ?? null,
            ]])->save();
        } finally {
            File::deleteDirectory($tmpDir);
        }
    }

    /**
     * Move the generated report tree and lcov.info from the temp directory
     * onto the coverage disk under {suiteId}/{runId}/.
     */
    private function persistArtifacts(CoverageService $coverage, TestRun $run, string $outDir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($outDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getFilename() === 'lcov.info') {
                continue;
            }

            $relative = ltrim(substr($file->getPathname(), strlen($outDir)), '/');
            $coverage->storeReportFile($run, $relative, file_get_contents($file->getPathname()));
        }

        if (is_file($outDir.'/lcov.info')) {
            $coverage->storeLcov($run, file_get_contents($outDir.'/lcov.info'));
        }
    }
}
