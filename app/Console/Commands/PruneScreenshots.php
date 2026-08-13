<?php

namespace App\Console\Commands;

use App\Services\ScreenshotService;
use Illuminate\Console\Command;

class PruneScreenshots extends Command
{
    protected $signature = 'sorify:prune-screenshots';

    protected $description = 'Delete screenshots older than the configured retention period';

    public function handle(ScreenshotService $screenshots): int
    {
        $days = (int) config('sorify.screenshot_retention_days');

        $deleted = $screenshots->pruneOlderThan($days);

        $this->info("Pruned {$deleted} screenshot(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
