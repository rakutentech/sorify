<?php

namespace App\Events;

use App\Models\TestRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * All runs of one trigger's fan-out have finished — fired exactly once per
 * group (the last finalizing run claims it via the group_notified_at
 * marker), carrying every sibling run so Teams/email can send a single
 * combined result notification per trigger instead of one per browser.
 *
 * Every trigger creates a fan-out group (single-engine triggers get a
 * one-run group), so the completion notification path is uniform.
 */
class TestRunGroupCompleted
{
    use Dispatchable, SerializesModels;

    /**
     * @param  Collection<int, TestRun>  $runs
     */
    public function __construct(
        public readonly Collection $runs,
    ) {}
}
