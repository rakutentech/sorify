<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\ExecutionMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExecutionModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_to_local(): void
    {
        $this->assertSame('local', ExecutionMode::current());
    }

    public function test_reads_setting_from_db(): void
    {
        Setting::set('execution_mode', 'ephemeral');

        $this->assertSame('ephemeral', ExecutionMode::current());
    }

    public function test_unknown_values_coerce_to_local(): void
    {
        Setting::set('execution_mode', 'weird');

        $this->assertSame('local', ExecutionMode::current());
    }

    public function test_config_default_used_when_no_db_value(): void
    {
        config()->set('sorify.execution.default_mode', 'ephemeral');

        $this->assertSame('ephemeral', ExecutionMode::current());
    }
}
