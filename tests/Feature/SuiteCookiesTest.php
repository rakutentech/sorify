<?php

namespace Tests\Feature;

use App\Mcp\Servers\SorifyServer;
use App\Mcp\Tools\Suites\CreateSuiteTool;
use App\Mcp\Tools\Suites\GetSuiteTool;
use App\Mcp\Tools\Suites\UpdateSuiteTool;
use App\Mcp\Tools\Suites\UploadSuiteCookiesTool;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\PlaywrightRunnerService;
use App\Services\ScreenshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuiteCookiesTest extends TestCase
{
    use RefreshDatabase;

    // ─── HTTP: create / update / read ───────────────────────────────────────

    public function test_admin_can_create_a_suite_with_cookies(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/sorify/suites', [
                'name' => 'Cookie Suite',
                'cookies' => [
                    ['name' => 'session', 'value' => 'abc123', 'domain' => 'example.com', 'path' => '/'],
                    ['name' => 'token', 'value' => 'xyz', 'domain' => 'example.com', 'path' => '/'],
                ],
            ])
            ->assertRedirect();

        $suite = TestSuite::where('name', 'Cookie Suite')->first();

        $this->assertSame(2, $suite->cookies()->count());
        $this->assertSame('abc123', $suite->cookies()->where('name', 'session')->value('value'));
    }

    public function test_admin_can_replace_a_suites_cookies_via_update(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $suite->cookies()->createMany([
            ['name' => 'old', 'value' => 'gone', 'domain' => 'example.com', 'path' => '/'],
        ]);

        $this->actingAs($admin)
            ->put("/sorify/suites/{$suite->id}", [
                'name' => 'Suite',
                'cookies' => [
                    ['name' => 'new', 'value' => 'kept', 'domain' => 'example.com', 'path' => '/'],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(1, $suite->cookies()->count());
        $this->assertNull($suite->cookies()->where('name', 'old')->first());
        $this->assertSame('kept', $suite->cookies()->where('name', 'new')->value('value'));
    }

    public function test_passing_empty_cookies_clears_them(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $suite->cookies()->createMany([['name' => 'x', 'value' => '1', 'domain' => 'example.com', 'path' => '/']]);

        $this->actingAs($admin)
            ->put("/sorify/suites/{$suite->id}", ['name' => 'Suite', 'cookies' => []])
            ->assertRedirect();

        $this->assertSame(0, $suite->cookies()->count());
    }

    public function test_omitting_cookies_leaves_existing_ones_untouched(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $suite->cookies()->createMany([['name' => 'x', 'value' => '1', 'domain' => 'example.com', 'path' => '/']]);

        $this->actingAs($admin)
            ->put("/sorify/suites/{$suite->id}", ['name' => 'Suite Renamed'])
            ->assertRedirect();

        $this->assertSame(1, $suite->cookies()->count());
    }

    public function test_duplicate_name_domain_path_collapse_to_last_value(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/sorify/suites', [
                'name' => 'Dupes',
                'cookies' => [
                    ['name' => 'sid', 'value' => 'v1', 'domain' => 'example.com', 'path' => '/'],
                    ['name' => 'sid', 'value' => 'v2', 'domain' => 'example.com', 'path' => '/'],
                ],
            ])
            ->assertRedirect();

        $suite = TestSuite::where('name', 'Dupes')->first();
        $this->assertSame(1, $suite->cookies()->count());
        $this->assertSame('v2', $suite->cookies()->where('name', 'sid')->value('value'));
    }

    public function test_same_name_different_domains_are_both_kept(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/sorify/suites', [
                'name' => 'MultiDomain',
                'cookies' => [
                    ['name' => 'sid', 'value' => 'a', 'domain' => 'example.com', 'path' => '/'],
                    ['name' => 'sid', 'value' => 'b', 'domain' => 'other.com', 'path' => '/'],
                ],
            ])
            ->assertRedirect();

        $suite = TestSuite::where('name', 'MultiDomain')->first();
        $this->assertSame(2, $suite->cookies()->count());
    }

    public function test_show_page_exposes_cookies_as_inertia_props(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $suite->cookies()->create([
            'name' => 'session', 'value' => 'abc', 'domain' => 'example.com', 'path' => '/',
        ]);

        $this->actingAs($admin)
            ->get("/sorify/suites/{$suite->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('suite.cookies.0.name', 'session')
                ->where('suite.cookies.0.value', 'abc')
                ->where('suite.cookies.0.domain', 'example.com')
                ->etc());
    }

    public function test_test_detail_page_exposes_suite_cookies_as_inertia_props(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $suite->cookies()->create([
            'name' => 'session', 'value' => 'abc', 'domain' => 'example.com', 'path' => '/',
        ]);

        $test = $suite->tests()->create([
            'name' => 'uses cookies',
            'playwright_code' => 'await page.goto(baseUrl);',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->get("/sorify/suites/{$suite->id}/tests/{$test->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('suite.cookies.0.name', 'session')
                ->where('suite.cookies.0.value', 'abc')
                ->etc());
    }

    // ─── Validation ──────────────────────────────────────────────────────────

    public function test_cookie_without_domain_or_url_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/sorify/suites', [
                'name' => 'Bad',
                'cookies' => [['name' => 'x', 'value' => 'y']],
            ])
            ->assertSessionHasErrors(['cookies.0']);
    }

    public function test_cookie_with_url_but_no_domain_is_accepted(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/sorify/suites', [
                'name' => 'UrlCookie',
                'cookies' => [['name' => 'x', 'value' => 'y', 'url' => 'https://example.com']],
            ])
            ->assertRedirect();

        $suite = TestSuite::where('name', 'UrlCookie')->first();
        $this->assertSame(1, $suite->cookies()->count());
    }

    public function test_cookie_with_invalid_same_site_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/sorify/suites', [
                'name' => 'BadSS',
                'cookies' => [['name' => 'x', 'value' => 'y', 'domain' => 'example.com', 'same_site' => 'Bogus']],
            ])
            ->assertSessionHasErrors(['cookies.0.same_site']);
    }

    public function test_cookie_without_name_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/sorify/suites', [
                'name' => 'NoName',
                'cookies' => [['value' => 'y', 'domain' => 'example.com']],
            ])
            ->assertSessionHasErrors(['cookies.0.name']);
    }

    // ─── Authorization ────────────────────────────────────────────────────────

    public function test_member_without_edit_cannot_update_cookies(): void
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
                'cookies' => [['name' => 'x', 'value' => '1', 'domain' => 'example.com']],
            ])
            ->assertForbidden();

        $this->assertSame(0, $suite->cookies()->count());
    }

    // ─── Duplication ─────────────────────────────────────────────────────────

    public function test_duplicating_a_suite_copies_its_cookies(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Original', 'created_by' => $admin->id]);
        $suite->cookies()->createMany([
            ['name' => 'session', 'value' => 'abc', 'domain' => 'example.com', 'path' => '/'],
            ['name' => 'token', 'value' => 'xyz', 'domain' => 'example.com', 'path' => '/'],
        ]);

        $this->actingAs($admin)
            ->post("/sorify/suites/{$suite->id}/duplicate")
            ->assertRedirect();

        $clone = TestSuite::where('duplicated_from_suite_id', $suite->id)->first();

        $this->assertSame(2, $clone->cookies()->count());
        $this->assertSame('abc', $clone->cookies()->where('name', 'session')->value('value'));
        $this->assertSame('xyz', $clone->cookies()->where('name', 'token')->value('value'));
    }

    // ─── MCP tools ───────────────────────────────────────────────────────────

    public function test_mcp_create_suite_persists_cookies(): void
    {
        $admin = User::factory()->admin()->create();

        SorifyServer::actingAs($admin)
            ->tool(CreateSuiteTool::class, [
                'name' => 'MCP Cookies',
                'cookies' => [
                    ['name' => 'session', 'value' => 'abc', 'domain' => 'example.com', 'path' => '/'],
                    ['name' => 'token', 'value' => 'xyz', 'domain' => 'example.com', 'path' => '/'],
                ],
            ])
            ->assertOk();

        $suite = TestSuite::where('name', 'MCP Cookies')->first();
        $this->assertSame(2, $suite->cookies()->count());
        $this->assertSame('xyz', $suite->cookies()->where('name', 'token')->value('value'));
    }

    public function test_mcp_update_suite_replaces_cookies(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $suite->cookies()->createMany([['name' => 'old', 'value' => 'gone', 'domain' => 'example.com', 'path' => '/']]);

        SorifyServer::actingAs($admin)
            ->tool(UpdateSuiteTool::class, [
                'suite_id' => $suite->id,
                'name' => 'Suite',
                'cookies' => [['name' => 'new', 'value' => 'added', 'domain' => 'example.com', 'path' => '/']],
            ])
            ->assertOk();

        $this->assertSame(1, $suite->cookies()->count());
        $this->assertSame('added', $suite->cookies()->where('name', 'new')->value('value'));
    }

    public function test_mcp_get_suite_returns_cookies(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $suite->cookies()->create([
            'name' => 'session', 'value' => 'abc', 'domain' => 'example.com', 'path' => '/',
        ]);

        SorifyServer::actingAs($admin)
            ->tool(GetSuiteTool::class, ['suite_id' => $suite->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('suite.cookies.0.name', 'session')
                ->where('suite.cookies.0.value', 'abc')
                ->etc());
    }

    public function test_mcp_upload_suite_cookies_replaces_cookies(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        SorifyServer::actingAs($admin)
            ->tool(UploadSuiteCookiesTool::class, [
                'suite_id' => $suite->id,
                'cookies' => [
                    ['name' => 'session', 'value' => 'abc', 'domain' => 'example.com', 'path' => '/'],
                ],
            ])
            ->assertOk();

        $this->assertSame(1, $suite->cookies()->count());
        $this->assertSame('abc', $suite->cookies()->where('name', 'session')->value('value'));
    }

    public function test_mcp_upload_suite_cookies_accepts_storage_state(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        $storageState = json_encode([
            'cookies' => [
                ['name' => 'sid', 'value' => 'v1', 'domain' => 'example.com', 'path' => '/'],
                ['name' => 'tid', 'value' => 'v2', 'domain' => 'example.com', 'path' => '/'],
            ],
            'origins' => [],
        ]);

        SorifyServer::actingAs($admin)
            ->tool(UploadSuiteCookiesTool::class, [
                'suite_id' => $suite->id,
                'storage_state' => $storageState,
            ])
            ->assertOk();

        $this->assertSame(2, $suite->cookies()->count());
    }

    public function test_mcp_upload_suite_cookies_with_empty_array_clears(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $suite->cookies()->createMany([['name' => 'x', 'value' => '1', 'domain' => 'example.com', 'path' => '/']]);

        SorifyServer::actingAs($admin)
            ->tool(UploadSuiteCookiesTool::class, [
                'suite_id' => $suite->id,
                'cookies' => [],
            ])
            ->assertOk();

        $this->assertSame(0, $suite->cookies()->count());
    }

    // ─── Runner injection ────────────────────────────────────────────────────

    public function test_runner_receives_suite_cookies_as_a_json_file(): void
    {
        if (static::nodeUnavailable()) {
            $this->markTestSkipped('node is not available on PATH; skipping runner wiring test.');
        }

        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Runner', 'created_by' => $admin->id]);
        $suite->cookies()->createMany([
            ['name' => 'session', 'value' => 'abc123', 'domain' => 'example.com', 'path' => '/'],
            ['name' => 'token', 'value' => 'xyz', 'domain' => 'example.com', 'path' => '/'],
        ]);

        $test = $suite->tests()->create([
            'name' => 'uses cookies',
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

        $tmpDir = storage_path('app/tmp/test-cookies-'.Str::uuid());
        File::ensureDirectoryExists($tmpDir);

        $stubPath = $tmpDir.'/stub-runner.cjs';
        File::put($stubPath, <<<'JS'
'use strict';
const fs = require('fs');
let cookiesPath = null;
for (let i = 2; i < process.argv.length; i++) {
    if (process.argv[i] === '--cookies' && process.argv[i + 1]) {
        cookiesPath = process.argv[++i];
    }
}
let cookies = [];
if (cookiesPath) {
    try { cookies = JSON.parse(fs.readFileSync(cookiesPath, 'utf8')); } catch (e) {}
}
process.stdout.write('COOKIES_PAYLOAD=' + JSON.stringify(cookies) + '\n');
process.stdout.write(JSON.stringify({ status: 'passed', duration_ms: 0, error_message: null, error_stack: null, screenshots: [] }));
JS);

        config()->set('sorify.runner_script_path', $stubPath);
        config()->set('sorify.tmp_dir', $tmpDir);

        $screenshot = $this->mock(ScreenshotService::class);
        $screenshot->shouldIgnoreMissing();

        $service = new PlaywrightRunnerService($screenshot);
        $result = $service->runWithRetries($test, $run);

        $this->assertSame('passed', $result->status, 'Stub runner should report passed. stdout: '.$result->stdout);
        $this->assertStringContainsString('session', $result->stdout);
        $this->assertStringContainsString('abc123', $result->stdout);
        $this->assertStringContainsString('token', $result->stdout);

        File::deleteDirectory($tmpDir);
    }

    private static function nodeUnavailable(): bool
    {
        exec('command -v node 2>/dev/null', $output, $exitCode);

        return $exitCode !== 0;
    }
}
