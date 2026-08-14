<?php

namespace Tests\Feature;

use App\Mcp\Servers\SorifyServer;
use App\Mcp\Tools\Runs\TriggerRunTool;
use App\Mcp\Tools\Suites\CreateSuiteTool;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ViewOnlyUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_only_user_cannot_create_a_suite(): void
    {
        $user = User::factory()->viewOnly()->create();

        $this->actingAs($user)
            ->post('/sorify/suites', ['name' => 'Blocked suite', 'base_url' => 'https://example.com'])
            ->assertForbidden();

        $this->assertDatabaseMissing('test_suites', ['name' => 'Blocked suite']);
    }

    public function test_member_user_can_create_a_suite(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/sorify/suites', ['name' => 'Allowed suite', 'base_url' => 'https://example.com'])
            ->assertRedirect();

        $this->assertDatabaseHas('test_suites', ['name' => 'Allowed suite']);
    }

    public function test_privileges_for_view_only_member_force_edit_delete_run_false_regardless_of_pivot(): void
    {
        $user = User::factory()->viewOnly()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $suite->members()->attach($user->id, [
            'can_view' => true, 'can_edit' => true, 'can_delete' => true, 'can_run' => true,
        ]);

        $privileges = $suite->privilegesFor($user);

        $this->assertTrue($privileges['view']);
        $this->assertFalse($privileges['edit']);
        $this->assertFalse($privileges['delete']);
        $this->assertFalse($privileges['run']);
    }

    public function test_adding_a_view_only_user_to_a_suite_clamps_edit_delete_run(): void
    {
        $admin = User::factory()->admin()->create();
        $viewOnly = User::factory()->viewOnly()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);

        $this->actingAs($admin)
            ->post("/sorify/suites/{$suite->id}/users", [
                'user_id' => $viewOnly->id,
                'can_view' => true,
                'can_edit' => true,
                'can_delete' => true,
                'can_run' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('test_suite_user', [
            'test_suite_id' => $suite->id,
            'user_id' => $viewOnly->id,
            'can_view' => true,
            'can_edit' => false,
            'can_delete' => false,
            'can_run' => false,
        ]);
    }

    public function test_updating_a_view_only_members_privileges_clamps_edit_delete_run(): void
    {
        $admin = User::factory()->admin()->create();
        $viewOnly = User::factory()->viewOnly()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $suite->members()->attach($viewOnly->id, [
            'can_view' => true, 'can_edit' => false, 'can_delete' => false, 'can_run' => false,
        ]);

        $this->actingAs($admin)
            ->put("/sorify/suites/{$suite->id}/users/{$viewOnly->id}", [
                'can_view' => true,
                'can_edit' => true,
                'can_delete' => true,
                'can_run' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('test_suite_user', [
            'test_suite_id' => $suite->id,
            'user_id' => $viewOnly->id,
            'can_view' => true,
            'can_edit' => false,
            'can_delete' => false,
            'can_run' => false,
        ]);
    }

    public function test_mcp_create_suite_denies_view_only_user(): void
    {
        $user = User::factory()->viewOnly()->create();

        SorifyServer::actingAs($user)
            ->tool(CreateSuiteTool::class, ['name' => 'Blocked suite', 'base_url' => 'https://example.com'])
            ->assertHasErrors(['This action is unauthorized']);

        $this->assertDatabaseMissing('test_suites', ['name' => 'Blocked suite']);
    }

    public function test_mcp_trigger_run_denies_non_member(): void
    {
        $user = User::factory()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);

        SorifyServer::actingAs($user)
            ->tool(TriggerRunTool::class, ['suite_id' => $suite->id])
            ->assertHasErrors(['This action is unauthorized']);

        $this->assertDatabaseMissing('test_runs', ['test_suite_id' => $suite->id]);
    }

    public function test_admin_can_change_a_users_role_to_viewer(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        $this->actingAs($admin)
            ->put("/sorify/admin/users/{$member->id}", ['role' => 'viewer'])
            ->assertRedirect();

        $member->refresh();
        $this->assertFalse($member->is_admin);
        $this->assertTrue($member->is_view_only);
    }

    public function test_admin_can_create_a_viewer_user(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/sorify/admin/users', [
                'name' => 'New Viewer',
                'email' => 'viewer@example.com',
                'password' => 'secret-password',
                'role' => 'viewer',
            ])
            ->assertRedirect();

        $created = User::where('email', 'viewer@example.com')->first();
        $this->assertNotNull($created);
        $this->assertFalse($created->is_admin);
        $this->assertTrue($created->is_view_only);
    }
}
