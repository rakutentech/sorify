<?php

namespace Tests\Unit;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_returns_default_when_missing(): void
    {
        $this->assertNull(Setting::get('execution_mode'));
        $this->assertSame('local', Setting::get('execution_mode', 'local'));
    }

    public function test_set_persists_and_get_returns_value(): void
    {
        Setting::set('execution_mode', 'ephemeral');

        $this->assertSame('ephemeral', Setting::get('execution_mode'));
    }

    public function test_set_overwrites_existing_value(): void
    {
        Setting::set('execution_mode', 'ephemeral');
        Setting::set('execution_mode', 'local');

        $this->assertSame('local', Setting::get('execution_mode'));
    }

    public function test_forget_removes_key(): void
    {
        Setting::set('execution_mode', 'ephemeral');
        Setting::forget('execution_mode');

        $this->assertNull(Setting::get('execution_mode'));
    }
}
