<?php

namespace Tests\Unit\Mcp\Tools;

use App\Jobs\RunSingleTestJob;
use App\Mcp\Servers\SorifyServer;
use App\Mcp\Tools\Runs\DeleteRunTool;
use App\Mcp\Tools\Runs\GetRunStatusTool;
use App\Mcp\Tools\Runs\GetRunTool;
use App\Mcp\Tools\Runs\ListRunsTool;
use App\Mcp\Tools\Runs\TriggerRunTool;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RunsToolsTest extends TestCase
{
    use RefreshDatabase;

    private function suite(): TestSuite
    {
        return TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
    }

    public function test_trigger_run_queues_a_run(): void
    {
        Queue::fake();

        $user = User::factory()->admin()->create();
        $suite = $this->suite();
        $suite->tests()->create(['name' => 'Test 1', 'playwright_code' => '// noop', 'status' => 'active']);

        SorifyServer::actingAs($user)
            ->tool(TriggerRunTool::class, ['suite_id' => $suite->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('status', 'running')->etc());

        $this->assertDatabaseHas('test_runs', ['test_suite_id' => $suite->id, 'triggered_by' => 'mcp', 'status' => 'running']);
        Queue::assertPushed(RunSingleTestJob::class, 1);
    }

    public function test_trigger_run_with_no_tests_completes_immediately(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite();

        SorifyServer::actingAs($user)
            ->tool(TriggerRunTool::class, ['suite_id' => $suite->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('status', 'completed')->etc());

        $this->assertDatabaseHas('test_runs', ['test_suite_id' => $suite->id, 'triggered_by' => 'mcp', 'status' => 'completed']);
    }

    public function test_get_run_status_returns_counts(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite();
        $run = $suite->testRuns()->create(['status' => 'completed', 'triggered_by' => 'mcp', 'passed_count' => 3, 'failed_count' => 1, 'total_tests' => 4]);

        SorifyServer::actingAs($user)
            ->tool(GetRunStatusTool::class, ['run_id' => $run->id])
            ->assertOk()
            ->assertStructuredContent([
                'status' => 'completed',
                'status_note' => null,
                'passed_count' => 3,
                'failed_count' => 1,
                'error_count' => 0,
                'total_tests' => 4,
                'duration_ms' => null,
            ]);
    }

    public function test_get_run_includes_results(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite();
        $run = $suite->testRuns()->create(['status' => 'completed', 'triggered_by' => 'mcp']);

        SorifyServer::actingAs($user)
            ->tool(GetRunTool::class, ['run_id' => $run->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->has('run')->has('results')->etc());
    }

    public function test_delete_run_removes_it(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite();
        $run = $suite->testRuns()->create(['status' => 'completed', 'triggered_by' => 'mcp']);

        SorifyServer::actingAs($user)
            ->tool(DeleteRunTool::class, ['run_id' => $run->id])
            ->assertOk();

        $this->assertDatabaseMissing('test_runs', ['id' => $run->id]);
    }

    public function test_list_runs_returns_runs_with_screenshot_count(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite();
        $run = $suite->testRuns()->create(['status' => 'completed', 'triggered_by' => 'mcp', 'passed_count' => 2, 'total_tests' => 2]);

        SorifyServer::actingAs($user)
            ->tool(ListRunsTool::class, [])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('meta.total', 1)
                ->where('data.0.id', $run->id)
                ->where('data.0.suite_name', 'Suite')
                ->where('data.0.status', 'completed')
                ->where('data.0.screenshot_count', 0)
                ->where('data.0.triggered_by', 'mcp')
                ->etc());
    }

    public function test_list_runs_filters_by_suite_id(): void
    {
        $user = User::factory()->admin()->create();
        $suiteA = TestSuite::create(['name' => 'Suite A', 'base_url' => 'https://a.example.com']);
        $suiteB = TestSuite::create(['name' => 'Suite B', 'base_url' => 'https://b.example.com']);
        $runA = $suiteA->testRuns()->create(['status' => 'completed', 'triggered_by' => 'mcp']);
        $runB = $suiteB->testRuns()->create(['status' => 'completed', 'triggered_by' => 'mcp']);

        SorifyServer::actingAs($user)
            ->tool(ListRunsTool::class, ['suite_id' => $suiteA->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('meta.total', 1)
                ->where('data.0.id', $runA->id)
                ->etc());
    }

    public function test_list_runs_filters_by_triggered_by(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite();
        $ciRun = $suite->testRuns()->create(['status' => 'completed', 'triggered_by' => 'ci']);
        $mcpRun = $suite->testRuns()->create(['status' => 'completed', 'triggered_by' => 'mcp']);

        SorifyServer::actingAs($user)
            ->tool(ListRunsTool::class, ['triggered_by' => 'ci'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('meta.total', 1)
                ->where('data.0.id', $ciRun->id)
                ->etc());
    }

    public function test_list_runs_sorts_by_status_ascending(): void
    {
        $user = User::factory()->admin()->create();
        $suite = $this->suite();
        // 'completed' < 'failed' < 'running' alphabetically
        $failedRun = $suite->testRuns()->create(['status' => 'failed', 'triggered_by' => 'mcp']);
        $completedRun = $suite->testRuns()->create(['status' => 'completed', 'triggered_by' => 'mcp']);
        $runningRun = $suite->testRuns()->create(['status' => 'running', 'triggered_by' => 'mcp']);

        SorifyServer::actingAs($user)
            ->tool(ListRunsTool::class, ['sort' => 'status', 'sort_dir' => 'asc'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('data.0.id', $completedRun->id)
                ->where('data.1.id', $failedRun->id)
                ->where('data.2.id', $runningRun->id)
                ->etc());
    }

    public function test_list_runs_non_admin_only_sees_visible_suite_runs(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $visibleSuite = $this->suite();
        $hiddenSuite = TestSuite::create(['name' => 'Hidden', 'base_url' => 'https://h.example.com']);

        // Grant the user view access to the visible suite only.
        $visibleSuite->members()->attach($user->id, ['can_view' => true]);

        $visibleSuite->testRuns()->create(['status' => 'completed', 'triggered_by' => 'mcp']);
        $hiddenSuite->testRuns()->create(['status' => 'completed', 'triggered_by' => 'mcp']);

        SorifyServer::actingAs($user)
            ->tool(ListRunsTool::class, [])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('meta.total', 1)
                ->etc());
    }
}
