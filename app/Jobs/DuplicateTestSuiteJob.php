<?php

namespace App\Jobs;

use App\Models\TestSuite;
use App\Services\TestSuiteDuplicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class DuplicateTestSuiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Suites can have hundreds of tests; give the worker plenty of runway. */
    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public readonly TestSuite $source,
        public readonly TestSuite $target,
    ) {
        $this->onQueue('sorify');
    }

    public function handle(TestSuiteDuplicationService $service): void
    {
        // The target may have been deleted between dispatch and pickup.
        if (! TestSuite::whereKey($this->target->id)->exists()) {
            return;
        }

        // Source may have been deleted too — nothing to copy, just finalise.
        if (! TestSuite::whereKey($this->source->id)->exists()) {
            $this->target->update(['duplication_status' => 'complete']);

            return;
        }

        try {
            $service->copyTests($this->source->fresh(), $this->target);
            $this->target->update(['duplication_status' => 'complete']);
        } catch (Throwable $e) {
            $this->target->update(['duplication_status' => 'failed']);

            Log::warning('Test suite duplication failed', [
                'source_suite_id' => $this->source->id,
                'target_suite_id' => $this->target->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
