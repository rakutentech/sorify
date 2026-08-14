<?php

namespace Tests\Unit\Mcp\Tools;

use App\Mcp\Servers\SorifyServer;
use App\Mcp\Tools\Tests\BulkCreateTestsTool;
use App\Mcp\Tools\Tests\BulkDeleteTestsTool;
use App\Mcp\Tools\Tests\CreateTestTool;
use App\Mcp\Tools\Tests\DeleteTestTool;
use App\Mcp\Tools\Tests\GetTestTool;
use App\Mcp\Tools\Tests\ListTestsTool;
use App\Mcp\Tools\Tests\ToggleTestStatusTool;
use App\Mcp\Tools\Tests\UpdateTestCodeTool;
use App\Mcp\Tools\Tests\UpdateTestTool;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestsToolsTest extends TestCase
{
    use RefreshDatabase;

    private function suite(): TestSuite
    {
        return TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
    }

    public function test_create_test_creates_a_test_with_code(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite();

        SorifyServer::actingAs($user)
            ->tool(CreateTestTool::class, [
                'suite_id' => $suite->id,
                'name' => 'Homepage loads',
                'playwright_code' => 'await page.goto("/");',
            ])
            ->assertOk();

        $this->assertDatabaseHas('tests', ['test_suite_id' => $suite->id, 'name' => 'Homepage loads']);
    }

    public function test_create_test_rejects_banned_code_patterns(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite();

        SorifyServer::actingAs($user)
            ->tool(CreateTestTool::class, [
                'suite_id' => $suite->id,
                'name' => 'Bad',
                'playwright_code' => 'require("fs").readFileSync("x")',
            ])
            ->assertHasErrors(['disallowed patterns']);

        $this->assertDatabaseMissing('tests', ['test_suite_id' => $suite->id, 'name' => 'Bad']);
    }

    public function test_bulk_create_tests_creates_multiple_tests(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite();

        SorifyServer::actingAs($user)
            ->tool(BulkCreateTestsTool::class, [
                'suite_id' => $suite->id,
                'tests' => [
                    ['name' => 'Test A', 'playwright_code' => 'await page.goto("/a");'],
                    ['name' => 'Test B', 'playwright_code' => 'await page.goto("/b");'],
                ],
            ])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('created', 2)->etc());

        $this->assertSame(2, $suite->tests()->count());
    }

    public function test_list_tests_returns_tests_in_suite(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite();
        $suite->tests()->create(['name' => 'A', 'playwright_code' => 'x', 'status' => 'active']);

        SorifyServer::actingAs($user)
            ->tool(ListTestsTool::class, ['suite_id' => $suite->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->has('data', 1)->etc());
    }

    public function test_get_test_includes_code_and_history(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite();
        $test = $suite->tests()->create(['name' => 'A', 'playwright_code' => 'code-here', 'status' => 'active']);

        SorifyServer::actingAs($user)
            ->tool(GetTestTool::class, ['suite_id' => $suite->id, 'test_id' => $test->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('test.playwright_code', 'code-here')
                ->has('history')
                ->etc());
    }

    public function test_get_test_history_includes_error_and_screenshot_detail_and_is_capped_at_ten(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite();
        $test = $suite->tests()->create(['name' => 'A', 'playwright_code' => 'code-here', 'status' => 'active']);

        for ($i = 0; $i < 12; $i++) {
            $run = $suite->testRuns()->create(['status' => 'completed']);
            \App\Models\TestResult::create([
                'test_run_id'   => $run->id,
                'test_id'       => $test->id,
                'status'        => 'failed',
                'error_message' => "boom {$i}",
                'created_at'    => now()->addSeconds($i),
            ]);
        }

        SorifyServer::actingAs($user)
            ->tool(GetTestTool::class, ['suite_id' => $suite->id, 'test_id' => $test->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->has('history', 10)
                ->where('history.0.error_message', 'boom 11')
                ->has('history.0.screenshots')
                ->etc());
    }

    public function test_update_test_updates_metadata_only(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite();
        $test = $suite->tests()->create(['name' => 'Old', 'description' => 'old description here', 'playwright_code' => 'code', 'status' => 'active']);

        SorifyServer::actingAs($user)
            ->tool(UpdateTestTool::class, ['suite_id' => $suite->id, 'test_id' => $test->id, 'name' => 'New', 'description' => 'new description here'])
            ->assertOk();

        $this->assertDatabaseHas('tests', ['id' => $test->id, 'name' => 'New', 'playwright_code' => 'code']);
    }

    public function test_update_test_code_reactivates_test(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite();
        $test = $suite->tests()->create(['name' => 'A', 'playwright_code' => 'old', 'status' => 'disabled']);

        SorifyServer::actingAs($user)
            ->tool(UpdateTestCodeTool::class, ['suite_id' => $suite->id, 'test_id' => $test->id, 'playwright_code' => 'await page.goto("/new");'])
            ->assertOk();

        $this->assertDatabaseHas('tests', ['id' => $test->id, 'playwright_code' => 'await page.goto("/new");', 'status' => 'active']);
    }

    public function test_toggle_test_status_flips_status(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite();
        $test = $suite->tests()->create(['name' => 'A', 'playwright_code' => 'code', 'status' => 'active']);

        SorifyServer::actingAs($user)
            ->tool(ToggleTestStatusTool::class, ['suite_id' => $suite->id, 'test_id' => $test->id])
            ->assertOk();

        $this->assertDatabaseHas('tests', ['id' => $test->id, 'status' => 'disabled']);
    }

    public function test_delete_test_removes_it(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite();
        $test = $suite->tests()->create(['name' => 'A', 'playwright_code' => 'code', 'status' => 'active']);

        SorifyServer::actingAs($user)
            ->tool(DeleteTestTool::class, ['suite_id' => $suite->id, 'test_id' => $test->id])
            ->assertOk();

        $this->assertDatabaseMissing('tests', ['id' => $test->id]);
    }

    public function test_bulk_delete_tests_removes_multiple(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite();
        $a = $suite->tests()->create(['name' => 'A', 'playwright_code' => 'code', 'status' => 'active']);
        $b = $suite->tests()->create(['name' => 'B', 'playwright_code' => 'code', 'status' => 'active']);

        SorifyServer::actingAs($user)
            ->tool(BulkDeleteTestsTool::class, ['suite_id' => $suite->id, 'test_ids' => [$a->id, $b->id]])
            ->assertOk()
            ->assertStructuredContent(['deleted_count' => 2]);

        $this->assertSame(0, $suite->tests()->count());
    }
}
