<?php

namespace App\Console\Commands;

use App\Models\TestRun;
use App\Services\TestRunService;
use Illuminate\Console\Command;

class PruneRuns extends Command
{
    protected $signature = 'sorify:prune-runs';

    protected $description = 'Delete test runs (and their files) older than the configured retention period';

    public function handle(TestRunService $runs): int
    {
        $days = (int) config('sorify.run_retention_days');

        // Pending/running runs are never candidates, no matter their age —
        // a stale-looking created_at on a live run must not kill it.
        $stale = TestRun::query()
            ->where('created_at', '<', now()->subDays($days))
            ->whereNotIn('status', ['pending', 'running'])
            ->get();

        foreach ($stale as $run) {
            $runs->deleteRun($run);
        }

        $this->info("Pruned {$stale->count()} run(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
