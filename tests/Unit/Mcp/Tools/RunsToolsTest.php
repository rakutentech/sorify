<?php

namespace Tests\Unit\Mcp\Tools;

use App\Mcp\Servers\SorifyServer;
use App\Mcp\Tools\Runs\DeleteRunTool;
use App\Mcp\Tools\Runs\GetRunStatusTool;
use App\Mcp\Tools\Runs\GetRunTool;
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

        $user = User::factory()->create();
        $suite = $this->suite();

        SorifyServer::actingAs($user)
            ->tool(TriggerRunTool::class, ['suite_id' => $suite->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('status', 'pending')->etc());

        $this->assertDatabaseHas('test_runs', ['test_suite_id' => $suite->id, 'triggered_by' => 'mcp', 'status' => 'pending']);
    }

    public function test_get_run_status_returns_counts(): void
    {
        $user = User::factory()->create();
        $suite = $this->suite();
        $run = $suite->testRuns()->create(['status' => 'completed', 'triggered_by' => 'mcp', 'passed_count' => 3, 'failed_count' => 1, 'total_tests' => 4]);

        SorifyServer::actingAs($user)
            ->tool(GetRunStatusTool::class, ['run_id' => $run->id])
            ->assertOk()
            ->assertStructuredContent([
                'status' => 'completed',
                'passed_count' => 3,
                'failed_count' => 1,
                'error_count' => 0,
                'total_tests' => 4,
                'duration_ms' => null,
            ]);
    }

    public function test_get_run_includes_results(): void
    {
        $user = User::factory()->create();
        $suite = $this->suite();
        $run = $suite->testRuns()->create(['status' => 'completed', 'triggered_by' => 'mcp']);

        SorifyServer::actingAs($user)
            ->tool(GetRunTool::class, ['run_id' => $run->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->has('run')->has('results')->etc());
    }

    public function test_delete_run_removes_it(): void
    {
        $user = User::factory()->create();
        $suite = $this->suite();
        $run = $suite->testRuns()->create(['status' => 'completed', 'triggered_by' => 'mcp']);

        SorifyServer::actingAs($user)
            ->tool(DeleteRunTool::class, ['run_id' => $run->id])
            ->assertOk();

        $this->assertDatabaseMissing('test_runs', ['id' => $run->id]);
    }
}
