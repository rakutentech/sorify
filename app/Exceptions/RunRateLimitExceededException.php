<?php

namespace App\Exceptions;

class RunRateLimitExceededException extends \RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct("Too many runs triggered for this suite. Try again in {$retryAfterSeconds} second(s).");
    }
}
