<?php

namespace Tests\Unit\Mcp\Tools;

use App\Mcp\Servers\SorifyServer;
use App\Mcp\Tools\Suites\CreateSuiteTool;
use App\Mcp\Tools\Suites\DeleteSuiteTool;
use App\Mcp\Tools\Suites\GetSuiteTool;
use App\Mcp\Tools\Suites\ListSuitesTool;
use App\Mcp\Tools\Suites\UpdateSuiteTool;
use App\Jobs\PruneSuiteHistoryJob;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SuitesToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_suite_creates_a_suite(): void
    {
        $user = User::factory()->admin()->create();

        SorifyServer::actingAs($user)
            ->tool(CreateSuiteTool::class, ['name' => 'Smoke tests', 'base_url' => 'https://example.com'])
            ->assertOk();

        $this->assertDatabaseHas('test_suites', ['name' => 'Smoke tests', 'base_url' => 'https://example.com']);
    }

    public function test_create_suite_grants_creator_owner_privileges(): void
    {
        $user = User::factory()->admin()->create();

        SorifyServer::actingAs($user)
            ->tool(CreateSuiteTool::class, ['name' => 'Smoke tests', 'base_url' => 'https://example.com'])
            ->assertOk();

        $suite = TestSuite::where('name', 'Smoke tests')->first();

        $this->assertDatabaseHas('test_suite_user', [
            'test_suite_id' => $suite->id,
            'user_id' => $user->id,
            'can_view' => true,
            'can_edit' => true,
            'can_delete' => true,
            'can_run' => true,
        ]);
    }

    public function test_create_suite_requires_a_name(): void
    {
        $user = User::factory()->admin()->create();

        SorifyServer::actingAs($user)
            ->tool(CreateSuiteTool::class, [])
            ->assertHasErrors(['name field is required']);
    }

    public function test_list_suites_returns_created_suites(): void
    {
        $user = User::factory()->admin()->create();
        TestSuite::create(['name' => 'Alpha', 'base_url' => 'https://a.example.com']);
        TestSuite::create(['name' => 'Beta', 'base_url' => 'https://b.example.com']);

        SorifyServer::actingAs($user)
            ->tool(ListSuitesTool::class, [])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('meta.total', 2)
                ->etc());
    }

    public function test_get_suite_returns_a_single_suite(): void
    {
        $user = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Alpha', 'base_url' => 'https://a.example.com']);

        SorifyServer::actingAs($user)
            ->tool(GetSuiteTool::class, ['suite_id' => $suite->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('suite.id', $suite->id)
                ->etc());
    }

    public function test_update_suite_updates_fields(): void
    {
        $user = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Alpha', 'base_url' => 'https://a.example.com']);

        SorifyServer::actingAs($user)
            ->tool(UpdateSuiteTool::class, ['suite_id' => $suite->id, 'name' => 'Alpha Renamed'])
            ->assertOk();

        $this->assertDatabaseHas('test_suites', ['id' => $suite->id, 'name' => 'Alpha Renamed']);
    }

    public function test_create_suite_defaults_history_retention_to_five(): void
    {
        $user = User::factory()->admin()->create();

        SorifyServer::actingAs($user)
            ->tool(CreateSuiteTool::class, ['name' => 'Smoke tests', 'base_url' => 'https://example.com'])
            ->assertOk();

        $this->assertDatabaseHas('test_suites', ['name' => 'Smoke tests', 'history_retention' => 5]);
    }

    public function test_create_suite_rejects_invalid_history_retention(): void
    {
        $user = User::factory()->admin()->create();

        SorifyServer::actingAs($user)
            ->tool(CreateSuiteTool::class, ['name' => 'Smoke tests', 'history_retention' => 7])
            ->assertHasErrors(['selected history retention is invalid']);
    }

    public function test_update_suite_dispatches_prune_job_when_history_retention_changes(): void
    {
        Queue::fake();

        $user = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Alpha', 'base_url' => 'https://a.example.com', 'history_retention' => 5]);

        SorifyServer::actingAs($user)
            ->tool(UpdateSuiteTool::class, ['suite_id' => $suite->id, 'name' => 'Alpha', 'history_retention' => 3])
            ->assertOk();

        $this->assertDatabaseHas('test_suites', ['id' => $suite->id, 'history_retention' => 3]);
        Queue::assertPushed(PruneSuiteHistoryJob::class);
    }

    public function test_update_suite_does_not_dispatch_prune_job_when_history_retention_unchanged(): void
    {
        Queue::fake();

        $user = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Alpha', 'base_url' => 'https://a.example.com', 'history_retention' => 5]);

        SorifyServer::actingAs($user)
            ->tool(UpdateSuiteTool::class, ['suite_id' => $suite->id, 'name' => 'Alpha Renamed', 'history_retention' => 5])
            ->assertOk();

        Queue::assertNotPushed(PruneSuiteHistoryJob::class);
    }

    public function test_delete_suite_removes_it(): void
    {
        $user = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Alpha', 'base_url' => 'https://a.example.com']);

        SorifyServer::actingAs($user)
            ->tool(DeleteSuiteTool::class, ['suite_id' => $suite->id])
            ->assertOk();

        $this->assertDatabaseMissing('test_suites', ['id' => $suite->id]);
    }

    public function test_list_suites_sorts_by_name_ascending(): void
    {
        $user = User::factory()->admin()->create();
        $gamma = TestSuite::create(['name' => 'Gamma', 'base_url' => 'https://g.example.com']);
        $alpha = TestSuite::create(['name' => 'Alpha', 'base_url' => 'https://a.example.com']);
        $beta = TestSuite::create(['name' => 'Beta', 'base_url' => 'https://b.example.com']);

        SorifyServer::actingAs($user)
            ->tool(ListSuitesTool::class, ['sort' => 'name', 'sort_dir' => 'asc'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('data.0.id', $alpha->id)
                ->where('data.1.id', $beta->id)
                ->where('data.2.id', $gamma->id)
                ->etc());
    }

    public function test_list_suites_sorts_by_runs_count(): void
    {
        $user = User::factory()->admin()->create();
        $fewRuns = TestSuite::create(['name' => 'Few Runs', 'base_url' => 'https://a.example.com']);
        $manyRuns = TestSuite::create(['name' => 'Many Runs', 'base_url' => 'https://b.example.com']);

        // Give "Many Runs" 3 completed runs and "Few Runs" 1.
        TestRun::create(['test_suite_id' => $manyRuns->id, 'status' => 'completed']);
        TestRun::create(['test_suite_id' => $manyRuns->id, 'status' => 'completed']);
        TestRun::create(['test_suite_id' => $manyRuns->id, 'status' => 'completed']);
        TestRun::create(['test_suite_id' => $fewRuns->id, 'status' => 'completed']);

        // desc: many first
        SorifyServer::actingAs($user)
            ->tool(ListSuitesTool::class, ['sort' => 'runs', 'sort_dir' => 'desc'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('data.0.id', $manyRuns->id)
                ->where('data.1.id', $fewRuns->id)
                ->etc());

        // asc: few first
        SorifyServer::actingAs($user)
            ->tool(ListSuitesTool::class, ['sort' => 'runs', 'sort_dir' => 'asc'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('data.0.id', $fewRuns->id)
                ->where('data.1.id', $manyRuns->id)
                ->etc());
    }
}
