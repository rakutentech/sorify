<?php

namespace Tests\Feature;

use App\Models\TestSuite;
use App\Models\User;
use App\Services\PlaywrightRunnerService;
use App\Services\ScreenshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScreenshotModeTest extends TestCase
{
    use RefreshDatabase;

    // ─── HTTP: mode persistence ─────────────────────────────────────────────

    public function test_screenshot_mode_can_be_set_to_on_failure(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->put("/sorify/suites/{$suite->id}", ['take_screenshot' => 'on_failure'])
            ->assertRedirect();

        $this->assertSame('on_failure', $suite->refresh()->take_screenshot);
    }

    public function test_invalid_screenshot_mode_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->put("/sorify/suites/{$suite->id}", ['take_screenshot' => 'sometimes'])
            ->assertSessionHasErrors('take_screenshot');

        $this->assertSame('enabled', $suite->refresh()->take_screenshot);
    }

    public function test_legacy_boolean_take_screenshot_input_is_normalized(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->put("/sorify/suites/{$suite->id}", ['take_screenshot' => 'false'])
            ->assertRedirect();
        $this->assertSame('disabled', $suite->refresh()->take_screenshot);

        $this->actingAs($admin)
            ->put("/sorify/suites/{$suite->id}", ['take_screenshot' => 'true'])
            ->assertRedirect();
        $this->assertSame('enabled', $suite->refresh()->take_screenshot);

        $this->actingAs($admin)
            ->put("/sorify/suites/{$suite->id}", ['take_screenshot' => '0'])
            ->assertRedirect();
        $this->assertSame('disabled', $suite->refresh()->take_screenshot);
    }

    public function test_omitting_take_screenshot_leaves_it_untouched(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'take_screenshot' => 'on_failure', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->put("/sorify/suites/{$suite->id}", ['name' => 'Renamed'])
            ->assertRedirect();

        $suite->refresh();
        $this->assertSame('on_failure', $suite->take_screenshot);
        $this->assertSame('Renamed', $suite->name);
    }

    // ─── Runner wiring ──────────────────────────────────────────────────────

    public function test_runner_receives_the_suite_screenshot_mode(): void
    {
        if (static::nodeUnavailable()) {
            $this->markTestSkipped('node is not available on PATH; skipping runner wiring test.');
        }

        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Runner', 'take_screenshot' => 'on_failure', 'created_by' => $admin->id]);

        $test = $suite->tests()->create([
            'name' => 'takes screenshots',
            'playwright_code' => 'await page.goto(baseUrl);',
            'status' => 'active',
        ]);

        $run = $suite->testRuns()->create([
            'triggered_by' => 'manual',
            'triggered_by_user_id' => $admin->id,
            'status' => 'running',
            'total_tests' => 1,
            'started_at' => now(),
        ]);

        $tmpDir = storage_path('app/tmp/test-screenshot-mode-'.Str::uuid());
        File::ensureDirectoryExists($tmpDir);

        $stubPath = $tmpDir.'/stub-runner.cjs';
        File::put($stubPath, <<<'JS'
'use strict';
let mode = null;
for (let i = 2; i < process.argv.length; i++) {
    if (process.argv[i] === '--screenshot-mode' && process.argv[i + 1]) {
        mode = process.argv[++i];
    }
}
process.stdout.write('SCREENSHOT_MODE=' + mode + '\n');
process.stdout.write(JSON.stringify({ status: 'passed', duration_ms: 0, error_message: null, error_stack: null, screenshots: [] }));
JS);

        config()->set('sorify.runner_script_path', $stubPath);
        config()->set('sorify.tmp_dir', $tmpDir);

        $screenshot = $this->mock(ScreenshotService::class);
        $screenshot->shouldIgnoreMissing();

        $service = new PlaywrightRunnerService($screenshot);
        $result = $service->runWithRetries($test, $run);

        $this->assertSame('passed', $result->status, 'Stub runner should report passed. stdout: '.$result->stdout);
        $this->assertStringContainsString('SCREENSHOT_MODE=on_failure', $result->stdout);

        File::deleteDirectory($tmpDir);
    }

    private static function nodeUnavailable(): bool
    {
        exec('command -v node 2>/dev/null', $output, $exitCode);

        return $exitCode !== 0;
    }
}
