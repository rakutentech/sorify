<?php

namespace Tests\Feature;

use App\Mcp\Servers\SorifyServer;
use App\Mcp\Tools\Suites\CreateSuiteTool;
use App\Mcp\Tools\Suites\UpdateSuiteTool;
use App\Models\GithubApp;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GitHub App access lists: when a GitHub App lists specific users, only
 * those users (and admins) may add or edit github_action integrations that
 * dispatch as it — an empty list leaves the previous everyone-with-edit-
 * rights behavior in place. Removing a user from the list force-disables
 * the integrations affected by that user, with an explanatory note on the
 * suite page (the same pattern as deleting a GitHub App).
 */
class GithubAppAccessListTest extends TestCase
{
    use RefreshDatabase;

    private function dispatchApp(array $overrides = []): GithubApp
    {
        return GithubApp::create(array_merge([
            'name' => 'GHE',
            'base_url' => 'https://ghe.example.com',
            'client_id' => 'Iv1.ghe',
            'client_secret' => 'secret',
            'app_id' => '123',
            'private_key' => '-----BEGIN RSA PRIVATE KEY-----\\nabc\\n-----END RSA PRIVATE KEY-----\\n',
            'actions_enabled' => true,
        ], $overrides));
    }

    private function suiteWithEditor(User $editor): TestSuite
    {
        $suite = TestSuite::create(['name' => 'Suite']);
        $suite->members()->attach($editor->id, [
            'can_view' => true, 'can_edit' => true, 'can_delete' => false, 'can_run' => true,
        ]);

        return $suite;
    }

    private function githubPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'github_action',
            'repository' => 'acme/app',
            'workflow' => 'deploy.yml',
            'enabled' => true,
        ], $overrides);
    }

    private function adminUpdate(GithubApp $app, array $overrides = []): TestResponse
    {
        return $this->actingAs(User::factory()->admin()->create())
            ->put("/sorify/admin/github-apps/{$app->id}", array_merge([
                'name' => $app->name,
                'base_url' => $app->base_url,
                'client_id' => $app->client_id,
                'client_secret' => '',
            ], $overrides));
    }

    // ─── Admin management of the access list ──────────────────────────────────

    public function test_admin_can_manage_the_access_list(): void
    {
        $app = $this->dispatchApp();
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->adminUpdate($app, ['allowed_user_ids' => [$alice->id, $bob->id]])
            ->assertRedirect();

        $this->assertSame(
            [$alice->id, $bob->id],
            $app->allowedUsers()->orderBy('users.id')->pluck('users.id')->all(),
        );

        // Omitting the field (the row-toggle shortcut) keeps the stored list.
        $this->adminUpdate($app)->assertRedirect();

        $this->assertSame(
            [$alice->id, $bob->id],
            $app->allowedUsers()->orderBy('users.id')->pluck('users.id')->all(),
        );

        // The listing exposes the current members for the edit form.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->get('/sorify/admin/github-apps')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('apps.0.allowed_users.0.id', $alice->id)
                ->etc());
    }

    // ─── Add / edit enforcement ───────────────────────────────────────────────

    public function test_non_whitelisted_editor_cannot_create_a_github_action_integration(): void
    {
        $editor = User::factory()->create();
        $suite = $this->suiteWithEditor($editor);
        $whitelisted = User::factory()->create();
        $app = $this->dispatchApp();
        $app->allowedUsers()->attach($whitelisted->id);

        $this->actingAs($editor)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->githubPayload(['github_app_id' => $app->id]))
            ->assertForbidden();

        $this->assertSame(0, $suite->integrations()->count());
    }

    public function test_non_whitelisted_editor_cannot_update_a_github_action_integration(): void
    {
        $editor = User::factory()->create();
        $suite = $this->suiteWithEditor($editor);
        $whitelisted = User::factory()->create();
        $app = $this->dispatchApp();
        $app->allowedUsers()->attach($whitelisted->id);
        $integration = $suite->integrations()->create([
            'type' => 'github_action',
            'github_app_id' => $app->id,
            'config' => ['repository' => 'acme/app', 'workflow' => 'deploy.yml'],
        ]);

        $this->actingAs($editor)
            ->putJson("/sorify/suites/{$suite->id}/integrations/{$integration->id}", $this->githubPayload())
            ->assertForbidden();

        $this->assertSame('acme/app', $integration->fresh()->config['repository']);
    }

    public function test_whitelisted_editor_can_create_and_update_github_action_integrations(): void
    {
        $editor = User::factory()->create();
        $suite = $this->suiteWithEditor($editor);
        $app = $this->dispatchApp();
        $app->allowedUsers()->attach($editor->id);

        $this->actingAs($editor)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->githubPayload(['github_app_id' => $app->id]))
            ->assertStatus(201);

        $integration = $suite->integrations()->first();
        $this->assertSame($editor->id, $integration->created_by);

        $this->actingAs($editor)
            ->putJson("/sorify/suites/{$suite->id}/integrations/{$integration->id}", $this->githubPayload([
                'repository' => 'acme/other',
            ]))
            ->assertOk()
            ->assertJsonPath('config.repository', 'acme/other');
    }

    public function test_empty_access_list_stays_backward_compatible(): void
    {
        $editor = User::factory()->create();
        $suite = $this->suiteWithEditor($editor);
        $app = $this->dispatchApp();

        $this->actingAs($editor)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->githubPayload(['github_app_id' => $app->id]))
            ->assertStatus(201);
    }

    public function test_admins_bypass_the_access_list(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $whitelisted = User::factory()->create();
        $app = $this->dispatchApp();
        $app->allowedUsers()->attach($whitelisted->id);

        $this->actingAs($admin)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->githubPayload(['github_app_id' => $app->id]))
            ->assertStatus(201);
    }

    public function test_http_request_integrations_are_not_gated(): void
    {
        $editor = User::factory()->create();
        $suite = $this->suiteWithEditor($editor);
        $app = $this->dispatchApp();
        $app->allowedUsers()->attach(User::factory()->create()->id);

        $this->actingAs($editor)
            ->postJson("/sorify/suites/{$suite->id}/integrations", [
                'type' => 'http_request',
                'url' => 'https://example.com/api',
                'method' => 'POST',
            ])
            ->assertStatus(201);
    }

    public function test_integrations_without_an_app_are_checked_against_the_first_dispatch_app(): void
    {
        $editor = User::factory()->create();
        $suite = $this->suiteWithEditor($editor);
        $appA = $this->dispatchApp(['name' => 'A']);
        $appA->allowedUsers()->attach(User::factory()->create()->id);
        $this->dispatchApp(['name' => 'B', 'client_id' => 'Iv1.b']);

        $this->actingAs($editor)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->githubPayload())
            ->assertForbidden();
    }

    // ─── MCP tools ───────────────────────────────────────────────────────────

    public function test_mcp_update_suite_rejects_non_whitelisted_github_action_integrations(): void
    {
        $editor = User::factory()->create();
        $suite = $this->suiteWithEditor($editor);
        $app = $this->dispatchApp();
        $app->allowedUsers()->attach(User::factory()->create()->id);

        SorifyServer::actingAs($editor)
            ->tool(UpdateSuiteTool::class, [
                'suite_id' => $suite->id,
                'name' => 'Suite',
                'integrations' => [$this->githubPayload(['github_app_id' => $app->id])],
            ])
            ->assertHasErrors(['access list']);

        $this->assertSame(0, $suite->integrations()->count());
    }

    public function test_mcp_create_suite_with_whitelisted_user_works_and_stamps_created_by(): void
    {
        $editor = User::factory()->create();
        $app = $this->dispatchApp();
        $app->allowedUsers()->attach($editor->id);

        SorifyServer::actingAs($editor)
            ->tool(CreateSuiteTool::class, [
                'name' => 'MCP Access List',
                'integrations' => [$this->githubPayload(['github_app_id' => $app->id])],
            ])
            ->assertOk();

        $suite = TestSuite::where('name', 'MCP Access List')->first();
        $this->assertSame($editor->id, $suite->integrations()->first()->created_by);
    }

    // ─── Removal sweep ───────────────────────────────────────────────────────

    public function test_removing_a_user_disables_integrations_in_their_editable_suites(): void
    {
        $admin = User::factory()->admin()->create();
        $removed = User::factory()->create(['name' => 'Removed User']);
        $keeper = User::factory()->create();
        $suite = $this->suiteWithEditor($removed);
        $app = $this->dispatchApp();
        $app->allowedUsers()->attach([$removed->id, $keeper->id]);

        $own = $suite->integrations()->create([
            'type' => 'github_action',
            'github_app_id' => $app->id,
            'config' => ['repository' => 'acme/app', 'workflow' => 'deploy.yml'],
            'created_by' => $removed->id,
        ]);
        $legacy = $suite->integrations()->create([
            'type' => 'github_action',
            'github_app_id' => $app->id,
            'config' => ['repository' => 'acme/app', 'workflow' => 'legacy.yml'],
            'created_by' => null,
        ]);

        $this->adminUpdate($app, ['allowed_user_ids' => [$keeper->id]])
            ->assertRedirect();

        $this->assertFalse($own->fresh()->enabled);
        $this->assertStringContainsString('access list', (string) $own->fresh()->disabled_note);
        $this->assertStringContainsString('Removed User', (string) $own->fresh()->disabled_note);

        // Legacy integrations (created before the feature) are treated as
        // the removed user's in their suites.
        $this->assertFalse($legacy->fresh()->enabled);
        $this->assertStringContainsString('access list', (string) $legacy->fresh()->disabled_note);
    }

    public function test_removal_spares_integrations_created_by_a_still_whitelisted_user(): void
    {
        $removed = User::factory()->create();
        $keeper = User::factory()->create();
        $suite = $this->suiteWithEditor($removed);
        $suite->members()->attach($keeper->id, [
            'can_view' => true, 'can_edit' => true, 'can_delete' => false, 'can_run' => false,
        ]);
        $app = $this->dispatchApp();
        $app->allowedUsers()->attach([$removed->id, $keeper->id]);

        $theirs = $suite->integrations()->create([
            'type' => 'github_action',
            'github_app_id' => $app->id,
            'config' => ['repository' => 'acme/app', 'workflow' => 'theirs.yml'],
            'created_by' => $keeper->id,
        ]);

        $this->adminUpdate($app, ['allowed_user_ids' => [$keeper->id]])
            ->assertRedirect();

        $this->assertTrue($theirs->fresh()->enabled);
        $this->assertNull($theirs->fresh()->disabled_note);
    }

    public function test_removal_ignores_suites_where_the_user_has_no_edit_permission(): void
    {
        $viewer = User::factory()->create();
        $suite = TestSuite::create(['name' => 'Suite']);
        $suite->members()->attach($viewer->id, [
            'can_view' => true, 'can_edit' => false, 'can_delete' => false, 'can_run' => false,
        ]);
        $app = $this->dispatchApp();
        $app->allowedUsers()->attach($viewer->id);

        $integration = $suite->integrations()->create([
            'type' => 'github_action',
            'github_app_id' => $app->id,
            'config' => ['repository' => 'acme/app', 'workflow' => 'deploy.yml'],
            'created_by' => $viewer->id,
        ]);

        $this->adminUpdate($app, ['allowed_user_ids' => []])
            ->assertRedirect();

        $this->assertTrue($integration->fresh()->enabled);
        $this->assertNull($integration->fresh()->disabled_note);
    }

    // ─── Suite page exposure ─────────────────────────────────────────────────

    public function test_show_page_lists_only_github_apps_the_user_may_use(): void
    {
        $editor = User::factory()->create();
        $suite = $this->suiteWithEditor($editor);
        $appA = $this->dispatchApp(['name' => 'A']);
        $appA->allowedUsers()->attach(User::factory()->create()->id);
        $appB = $this->dispatchApp(['name' => 'B', 'client_id' => 'Iv1.b']);
        $appB->allowedUsers()->attach($editor->id);

        $this->actingAs($editor)
            ->get("/sorify/suites/{$suite->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('githubApps', 1)
                ->where('githubApps.0.name', 'B')
                ->where('githubActionsConfigured', true)
                ->where('githubActionsAllowed', true)
                ->etc());
    }

    public function test_show_page_flags_a_user_without_access_to_any_app(): void
    {
        $editor = User::factory()->create();
        $suite = $this->suiteWithEditor($editor);
        $app = $this->dispatchApp();
        $app->allowedUsers()->attach(User::factory()->create()->id);

        $this->actingAs($editor)
            ->get("/sorify/suites/{$suite->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('githubApps', 0)
                ->where('githubActionsConfigured', true)
                ->where('githubActionsAllowed', false)
                ->etc());
    }
}
