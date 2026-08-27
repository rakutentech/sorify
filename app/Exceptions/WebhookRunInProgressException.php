<?php

namespace App\Exceptions;

use App\Models\TestRun;

class WebhookRunInProgressException extends \RuntimeException
{
    public function __construct(public readonly TestRun $run)
    {
        parent::__construct('A run triggered via this webhook is already in progress.');
    }
}
