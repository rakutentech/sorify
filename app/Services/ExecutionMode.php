<?php

namespace App\Services;

use App\Models\Setting;

class ExecutionMode
{
    public const LOCAL = 'local';

    public const EPHEMERAL = 'ephemeral';

    public static function current(): string
    {
        $mode = Setting::get('execution_mode')
            ?? config('sorify.execution.default_mode', ExecutionMode::LOCAL);

        return $mode === ExecutionMode::EPHEMERAL ? ExecutionMode::EPHEMERAL : ExecutionMode::LOCAL;
    }
}
