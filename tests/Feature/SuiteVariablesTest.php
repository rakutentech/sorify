<?php

namespace Tests\Feature;

use App\Mcp\Servers\SorifyServer;
use App\Mcp\Tools\Suites\CreateSuiteTool;
use App\Mcp\Tools\Suites\GetSuiteTool;
use App\Mcp\Tools\Suites\UpdateSuiteTool;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\PlaywrightRunnerService;
use App\Services\ScreenshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuiteVariablesTest extends TestCase
{
    use RefreshDatabase;

    // ─── HTTP: create / update / read ───────────────────────────────────────

    public function test_admin_can_create_a_suite_with_variables(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/sorify/suites', [
                'name' => 'Vars Suite',
                'variables' => [
                    ['key' => 'USERNAME', 'value' => 'alice'],
                    ['key' => 'TOKEN', 'value' => 's3cr3t'],
                ],
            ])
            ->assertRedirect();

        $suite = TestSuite::where('name', 'Vars Suite')->first();

        $this->assertSame(2, $suite->variables()->count());
        $this->assertSame('s3cr3t', $suite->variables()->where('key', 'TOKEN')->value('value'));
    }

    public function test_admin_can_replace_a_suites_variables_via_update(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $suite->variables()->createMany([
            ['key' => 'OLD', 'value' => 'gone'],
            ['key' => 'KEEP', 'value' => 'kept'],
        ]);

        $this->actingAs($admin)
            ->put("/sorify/suites/{$suite->id}", [
                'name' => 'Suite',
                'variables' => [
                    ['key' => 'KEEP', 'value' => 'kept'],
                    ['key' => 'NEW', 'value' => 'added'],
                ],
            ])
            ->assertRedirect();

        // Replacing the set drops the absent key entirely.
        $this->assertSame(2, $suite->variables()->count());
        $this->assertNull($suite->variables()->where('key', 'OLD')->first());
        $this->assertSame('added', $suite->variables()->where('key', 'NEW')->value('value'));
    }

    public function test_passing_empty_variables_clears_them(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $suite->variables()->createMany([['key' => 'X', 'value' => '1']]);

        $this->actingAs($admin)
            ->put("/sorify/suites/{$suite->id}", ['name' => 'Suite', 'variables' => []])
            ->assertRedirect();

        $this->assertSame(0, $suite->variables()->count());
    }

    public function test_omitting_variables_leaves_existing_ones_untouched(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $suite->variables()->createMany([['key' => 'X', 'value' => '1']]);

        $this->actingAs($admin)
            ->put("/sorify/suites/{$suite->id}", ['name' => 'Suite Renamed'])
            ->assertRedirect();

        $this->assertSame(1, $suite->variables()->count());
    }

    public function test_duplicate_keys_within_one_payload_collapse_to_last_value(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/sorify/suites', [
                'name' => 'Dupes',
                'variables' => [
                    ['key' => 'ENV', 'value' => 'dev'],
                    ['key' => 'ENV', 'value' => 'prod'],
                ],
            ])
            ->assertRedirect();

        $suite = TestSuite::where('name', 'Dupes')->first();
        $this->assertSame(1, $suite->variables()->count());
        $this->assertSame('prod', $suite->variables()->where('key', 'ENV')->value('value'));
    }

    public function test_show_page_exposes_variables_as_inertia_props(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $suite->variables()->createMany([['key' => 'USERNAME', 'value' => 'alice']]);

        $this->actingAs($admin)
            ->get("/sorify/suites/{$suite->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('suite.variables.0.key', 'USERNAME')
                ->where('suite.variables.0.value', 'alice')
                ->etc());
    }

    public function test_test_detail_page_exposes_suite_variables_as_inertia_props(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $suite->variables()->createMany([['key' => 'USERNAME', 'value' => 'alice']]);

        $test = $suite->tests()->create([
            'name' => 'uses variables',
            'playwright_code' => 'await page.goto(variables.USERNAME);',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->get("/sorify/suites/{$suite->id}/tests/{$test->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('suite.variables.0.key', 'USERNAME')
                ->where('suite.variables.0.value', 'alice')
                ->etc());
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    public function test_variable_key_starting_with_a_digit_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/sorify/suites', [
                'name' => 'Bad',
                'variables' => [['key' => '1KEY', 'value' => 'x']],
            ])
            ->assertSessionHasErrors(['variables.0.key']);
    }

    public function test_variable_key_with_a_dash_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/sorify/suites', [
                'name' => 'Bad',
                'variables' => [['key' => 'my-key', 'value' => 'x']],
            ])
            ->assertSessionHasErrors(['variables.0.key']);
    }

    public function test_variable_value_may_be_empty(): void
    {
        $admin = User::factory()->admin()->create();

        // Empty strings are converted to null by Laravel's ConvertEmptyStringsToNull
        // middleware; either way the variable is persisted and available in scope.
        $this->actingAs($admin)
            ->post('/sorify/suites', [
                'name' => 'EmptyVal',
                'variables' => [['key' => 'EMPTY', 'value' => '']],
            ])
            ->assertRedirect();

        $suite = TestSuite::where('name', 'EmptyVal')->first();
        $this->assertSame(1, $suite->variables()->count());
        $this->assertNull($suite->variables()->where('key', 'EMPTY')->value('value'));
    }

    // ─── Authorization ────────────────────────────────────────────────────────

    public function test_member_without_edit_cannot_update_variables(): void
    {
        $owner = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $owner->id]);

        $member = User::factory()->create();
        $suite->members()->attach($member->id, [
            'can_view' => true, 'can_edit' => false, 'can_delete' => false, 'can_run' => false,
        ]);

        $this->actingAs($member)
            ->put("/sorify/suites/{$suite->id}", [
                'name' => 'Suite',
                'variables' => [['key' => 'X', 'value' => '1']],
            ])
            ->assertForbidden();

        $this->assertSame(0, $suite->variables()->count());
    }

    // ─── Duplication ─────────────────────────────────────────────────────────

    public function test_duplicating_a_suite_copies_its_variables(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Original', 'created_by' => $admin->id]);
        $suite->variables()->createMany([
            ['key' => 'USERNAME', 'value' => 'alice'],
            ['key' => 'TOKEN', 'value' => 's3cr3t'],
        ]);

        $this->actingAs($admin)
            ->post("/sorify/suites/{$suite->id}/duplicate")
            ->assertRedirect();

        $clone = TestSuite::where('duplicated_from_suite_id', $suite->id)->first();

        $this->assertSame(2, $clone->variables()->count());
        $this->assertSame('alice', $clone->variables()->where('key', 'USERNAME')->value('value'));
        $this->assertSame('s3cr3t', $clone->variables()->where('key', 'TOKEN')->value('value'));
    }

    // ─── MCP tools ───────────────────────────────────────────────────────────

    public function test_mcp_create_suite_persists_variables(): void
    {
        $admin = User::factory()->admin()->create();

        SorifyServer::actingAs($admin)
            ->tool(CreateSuiteTool::class, [
                'name' => 'MCP Vars',
                'variables' => [
                    ['key' => 'USERNAME', 'value' => 'alice'],
                    ['key' => 'TOKEN', 'value' => 's3cr3t'],
                ],
            ])
            ->assertOk();

        $suite = TestSuite::where('name', 'MCP Vars')->first();
        $this->assertSame('s3cr3t', $suite->variables()->where('key', 'TOKEN')->value('value'));
    }

    public function test_mcp_update_suite_replaces_variables(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $suite->variables()->createMany([['key' => 'OLD', 'value' => 'gone']]);

        SorifyServer::actingAs($admin)
            ->tool(UpdateSuiteTool::class, [
                'suite_id' => $suite->id,
                'name' => 'Suite',
                'variables' => [['key' => 'NEW', 'value' => 'added']],
            ])
            ->assertOk();

        $this->assertSame(1, $suite->variables()->count());
        $this->assertSame('added', $suite->variables()->where('key', 'NEW')->value('value'));
    }

    public function test_mcp_get_suite_returns_variables(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $suite->variables()->createMany([['key' => 'USERNAME', 'value' => 'alice']]);

        SorifyServer::actingAs($admin)
            ->tool(GetSuiteTool::class, ['suite_id' => $suite->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('suite.variables.0.key', 'USERNAME')
                ->where('suite.variables.0.value', 'alice')
                ->etc());
    }

    public function test_mcp_create_suite_rejects_invalid_variable_key(): void
    {
        $admin = User::factory()->admin()->create();

        SorifyServer::actingAs($admin)
            ->tool(CreateSuiteTool::class, [
                'name' => 'Bad',
                'variables' => [['key' => '1KEY', 'value' => 'x']],
            ])
            ->assertHasErrors(['format is invalid']);
    }

    // ─── Runner injection ────────────────────────────────────────────────────

    public function test_runner_receives_suite_variables_as_a_json_file(): void
    {
        if (static::nodeUnavailable()) {
            $this->markTestSkipped('node is not available on PATH; skipping runner wiring test.');
        }

        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Runner', 'created_by' => $admin->id]);
        $suite->variables()->createMany([
            ['key' => 'USERNAME', 'value' => 'alice'],
            ['key' => 'TOKEN', 'value' => 's3cr3t'],
        ]);

        $test = $suite->tests()->create([
            'name' => 'uses variables',
            'playwright_code' => 'await page.goto(variables.USERNAME);',
            'status' => 'active',
        ]);

        $run = $suite->testRuns()->create([
            'triggered_by' => 'manual',
            'triggered_by_user_id' => $admin->id,
            'status' => 'running',
            'total_tests' => 1,
            'started_at' => now(),
        ]);

        $tmpDir = storage_path('app/tmp/test-vars-'.Str::uuid());
        File::ensureDirectoryExists($tmpDir);

        $stubPath = $tmpDir.'/stub-runner.cjs';
        File::put($stubPath, <<<'JS'
'use strict';
const fs = require('fs');
let variablesPath = null;
for (let i = 2; i < process.argv.length; i++) {
    if (process.argv[i] === '--variables' && process.argv[i + 1]) {
        variablesPath = process.argv[++i];
    }
}
let vars = {};
if (variablesPath) {
    try { vars = JSON.parse(fs.readFileSync(variablesPath, 'utf8')); } catch (e) {}
}
process.stdout.write('VARS_PAYLOAD=' + JSON.stringify(vars) + '\n');
process.stdout.write(JSON.stringify({ status: 'passed', duration_ms: 0, error_message: null, error_stack: null, screenshots: [] }));
JS);

        config()->set('sorify.runner_script_path', $stubPath);
        config()->set('sorify.tmp_dir', $tmpDir);

        $screenshot = $this->mock(ScreenshotService::class);
        $screenshot->shouldIgnoreMissing();

        $service = new PlaywrightRunnerService($screenshot);
        $result = $service->runWithRetries($test, $run);

        $this->assertSame('passed', $result->status, 'Stub runner should report passed. stdout: '.$result->stdout);
        $this->assertStringContainsString('USERNAME', $result->stdout);
        $this->assertStringContainsString('s3cr3t', $result->stdout);

        File::deleteDirectory($tmpDir);
    }

    private static function nodeUnavailable(): bool
    {
        exec('command -v node 2>/dev/null', $output, $exitCode);

        return $exitCode !== 0;
    }
}
