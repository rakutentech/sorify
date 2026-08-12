<?php

namespace App\Events;

use App\Models\TestRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TestRunCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly TestRun $testRun) {}
}
