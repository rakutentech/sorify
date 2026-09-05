<?php

namespace App\Events;

use App\Models\TestRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TestRunStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly TestRun $testRun) {}
}
