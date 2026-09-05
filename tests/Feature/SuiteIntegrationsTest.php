<?php

namespace Tests\Feature;

use App\Mcp\Servers\SorifyServer;
use App\Mcp\Tools\Suites\CreateSuiteTool;
use App\Mcp\Tools\Suites\GetSuiteTool;
use App\Mcp\Tools\Suites\UpdateSuiteTool;
use App\Models\GithubApp;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuiteIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'github_action',
            'label' => 'Deploy',
            'repository' => 'acme/app',
            'workflow' => 'deploy.yml',
            'ref' => 'main',
            'inputs' => [['name' => 'environment', 'value' => 'staging']],
            'enabled' => true,
            'trigger_before' => true,
            'trigger_after' => false,
        ], $overrides);
    }

    // ─── HTTP: create / update / delete ───────────────────────────────────────

    public function test_admin_can_create_an_integration(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->validPayload())
            ->assertStatus(201)
            ->assertJsonPath('type', 'github_action');

        $integration = $suite->integrations()->first();
        $this->assertNotNull($integration);
        $this->assertSame('github_action', $integration->type);
        $this->assertSame('Deploy', $integration->label);
        $this->assertSame('acme/app', $integration->config['repository']);
        $this->assertSame('deploy.yml', $integration->config['workflow']);
        $this->assertSame('main', $integration->config['ref']);
        $this->assertSame(['environment' => 'staging'], $integration->config['inputs']);
        $this->assertTrue($integration->enabled);
        $this->assertTrue($integration->trigger_before);
        $this->assertFalse($integration->trigger_after);
    }

    public function test_update_replaces_integration_config_and_flags(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $integration = $suite->integrations()->create([
            'type' => 'github_action',
            'config' => ['repository' => 'acme/app', 'workflow' => 'deploy.yml'],
            'trigger_after' => true,
        ]);

        $this->actingAs($admin)
            ->putJson("/sorify/suites/{$suite->id}/integrations/{$integration->id}", $this->validPayload([
                'repository' => 'acme/other',
                'trigger_before' => false,
                'trigger_after' => true,
            ]))
            ->assertOk()
            ->assertJsonPath('config.repository', 'acme/other');

        $integration->refresh();
        $this->assertSame('acme/other', $integration->config['repository']);
        $this->assertFalse($integration->trigger_before);
        $this->assertTrue($integration->trigger_after);
    }

    public function test_delete_removes_the_integration(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $integration = $suite->integrations()->create([
            'type' => 'github_action',
            'config' => ['repository' => 'acme/app', 'workflow' => 'deploy.yml'],
        ]);

        $this->actingAs($admin)
            ->deleteJson("/sorify/suites/{$suite->id}/integrations/{$integration->id}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertSame(0, $suite->integrations()->count());
    }

    public function test_integration_of_another_suite_is_not_reachable(): void
    {
        $admin = User::factory()->admin()->create();
        $suiteA = TestSuite::create(['name' => 'A', 'created_by' => $admin->id]);
        $suiteB = TestSuite::create(['name' => 'B', 'created_by' => $admin->id]);
        $integration = $suiteB->integrations()->create([
            'type' => 'github_action',
            'config' => ['repository' => 'acme/app', 'workflow' => 'deploy.yml'],
        ]);

        $this->actingAs($admin)
            ->putJson("/sorify/suites/{$suiteA->id}/integrations/{$integration->id}", $this->validPayload())
            ->assertNotFound();
    }

    // ─── HTTP request integrations ─────────────────────────────────────────

    private function validHttpPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'http_request',
            'label' => 'Deploy hook',
            'url' => 'https://example.com/api/deploy?env=prod',
            'method' => 'POST',
            'inputs' => [
                ['name' => 'environment', 'value' => 'staging'],
            ],
            'headers' => [
                ['name' => 'X-API-Key', 'value' => 'key-123'],
            ],
            'enabled' => true,
            'trigger_before' => true,
            'trigger_after' => true,
        ], $overrides);
    }

    public function test_http_request_integration_can_be_created(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->validHttpPayload())
            ->assertStatus(201)
            ->assertJsonPath('type', 'http_request');

        $integration = $suite->integrations()->first();
        $this->assertSame('http_request', $integration->type);
        $this->assertSame('https://example.com/api/deploy?env=prod', $integration->config['url']);
        $this->assertSame('POST', $integration->config['method']);
        $this->assertSame(['environment' => 'staging'], $integration->config['inputs']);
        $this->assertSame(['X-API-Key' => 'key-123'], $integration->config['headers']);
        $this->assertTrue($integration->enabled);
        $this->assertTrue($integration->trigger_before);
        $this->assertTrue($integration->trigger_after);
    }

    public function test_http_request_values_round_trip_to_the_page(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $suite->integrations()->create([
            'type' => 'http_request',
            'config' => [
                'url' => 'https://example.com/api',
                'method' => 'POST',
                'inputs' => ['api_key' => 'visible-value'],
                'headers' => ['Authorization' => 'Bearer token'],
            ],
        ]);

        $this->actingAs($admin)
            ->get("/sorify/suites/{$suite->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('suite.integrations.0.config.inputs.api_key', 'visible-value')
                ->where('suite.integrations.0.config.headers.Authorization', 'Bearer token')
                ->etc());
    }

    public function test_http_request_url_is_required_and_must_be_http(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->postJson("/sorify/suites/{$suite->id}/integrations", ['type' => 'http_request', 'method' => 'GET'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['url']);

        $this->actingAs($admin)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->validHttpPayload(['url' => 'javascript:alert(1)']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['url']);
    }

    public function test_http_request_method_must_be_supported(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->validHttpPayload(['method' => 'PATCH']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method']);
    }

    public function test_proxy_is_stored_on_http_request_integrations(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->validHttpPayload([
                'proxy' => 'http://proxy.example.com:8080',
            ]))
            ->assertStatus(201);

        $this->assertSame(
            'http://proxy.example.com:8080',
            $suite->integrations()->where('type', 'http_request')->first()->config['proxy'],
        );

        // GitHub Action integrations carry no proxy of their own — their
        // GitHub App's proxy (Admin → GitHub Apps) applies.
        $this->actingAs($admin)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->validPayload([
                'proxy' => 'http://proxy.example.com:8080',
            ]))
            ->assertStatus(201);

        $this->assertArrayNotHasKey(
            'proxy',
            $suite->integrations()->where('type', 'github_action')->first()->config,
        );
    }

    public function test_http_request_body_must_be_valid_json(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->validHttpPayload([
                'body' => '{"deploy": true,}',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);

        $this->actingAs($admin)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->validHttpPayload([
                'body' => '{"deploy": true}',
            ]))
            ->assertStatus(201);

        $this->assertSame('{"deploy": true}', $suite->integrations()->first()->config['body']);
    }

    public function test_http_request_header_names_must_be_valid(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->validHttpPayload([
                'headers' => [['name' => 'Bad Header: x', 'value' => 'nope']],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['headers.0.name']);
    }

    public function test_github_integration_can_target_a_specific_app(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        $ghe = GithubApp::create([
            'name' => 'GHE',
            'base_url' => 'https://ghe.example.com',
            'client_id' => 'Iv1.ghe',
            'client_secret' => 'secret',
        ]);

        $this->actingAs($admin)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->validPayload([
                'github_app_id' => $ghe->id,
            ]))
            ->assertStatus(201);

        $this->assertSame($ghe->id, $suite->integrations()->first()->github_app_id);

        // Switching the type to http_request clears the app reference.
        $this->actingAs($admin)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->validHttpPayload())
            ->assertStatus(201);

        $this->assertNull(
            $suite->integrations()->where('type', 'http_request')->first()->github_app_id,
        );
    }

    public function test_github_integration_rejects_an_unknown_app(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->validPayload([
                'github_app_id' => 999,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['github_app_id']);
    }

    public function test_github_fields_are_not_required_for_http_request(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->validHttpPayload())
            ->assertStatus(201);
    }

    // ─── Validation ─────────────────────────────────────────────────────────

    public function test_repository_and_workflow_are_required(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->postJson("/sorify/suites/{$suite->id}/integrations", ['type' => 'github_action'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['repository', 'workflow']);
    }

    public function test_repository_must_be_owner_slash_repo(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->validPayload(['repository' => 'not a repo']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['repository']);
    }

    public function test_unknown_type_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->validPayload(['type' => 'slack']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    public function test_reserved_sorify_input_names_are_dropped(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);

        $this->actingAs($admin)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->validPayload([
                'inputs' => [
                    ['name' => 'environment', 'value' => 'staging'],
                    ['name' => 'sorify_run_id', 'value' => '999'],
                ],
            ]))
            ->assertStatus(201);

        $integration = $suite->integrations()->first();
        $this->assertSame(['environment' => 'staging'], $integration->config['inputs']);
    }

    // ─── Authorization ───────────────────────────────────────────────────────

    public function test_member_without_edit_cannot_manage_integrations(): void
    {
        $member = User::factory()->create();
        $suite = TestSuite::create(['name' => 'Suite']);
        $suite->members()->attach($member->id, [
            'can_view' => true, 'can_edit' => false, 'can_delete' => false, 'can_run' => false,
        ]);

        $this->actingAs($member)
            ->post("/sorify/suites/{$suite->id}/integrations", $this->validPayload())
            ->assertForbidden();
    }

    public function test_non_member_cannot_manage_integrations(): void
    {
        $user = User::factory()->create();
        $suite = TestSuite::create(['name' => 'Suite']);

        $this->actingAs($user)
            ->postJson("/sorify/suites/{$suite->id}/integrations", $this->validPayload())
            ->assertForbidden();
    }

    // ─── Exposure ────────────────────────────────────────────────────────────

    public function test_show_page_exposes_integrations_as_inertia_props(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $suite->integrations()->create([
            'type' => 'github_action',
            'label' => 'Deploy',
            'config' => ['repository' => 'acme/app', 'workflow' => 'deploy.yml'],
            'trigger_after' => true,
        ]);

        $this->actingAs($admin)
            ->get("/sorify/suites/{$suite->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('suite.integrations.0.type', 'github_action')
                ->where('suite.integrations.0.config.repository', 'acme/app')
                ->where('suite.integrations.0.trigger_after', true)
                ->etc());
    }

    // ─── MCP tools ───────────────────────────────────────────────────────────

    public function test_mcp_create_suite_with_integrations(): void
    {
        $admin = User::factory()->admin()->create();

        SorifyServer::actingAs($admin)
            ->tool(CreateSuiteTool::class, [
                'name' => 'MCP Integrations',
                'integrations' => [$this->validPayload()],
            ])
            ->assertOk();

        $suite = TestSuite::where('name', 'MCP Integrations')->first();
        $this->assertSame(1, $suite->integrations()->count());
        $this->assertSame('acme/app', $suite->integrations()->first()->config['repository']);
    }

    public function test_mcp_create_suite_with_http_request_integration(): void
    {
        $admin = User::factory()->admin()->create();

        SorifyServer::actingAs($admin)
            ->tool(CreateSuiteTool::class, [
                'name' => 'MCP HTTP Integration',
                'integrations' => [$this->validHttpPayload(['body' => '{"deploy": true}'])],
            ])
            ->assertOk();

        $suite = TestSuite::where('name', 'MCP HTTP Integration')->first();
        $integration = $suite->integrations()->first();
        $this->assertSame('http_request', $integration->type);
        $this->assertSame('https://example.com/api/deploy?env=prod', $integration->config['url']);
        $this->assertSame('POST', $integration->config['method']);
        $this->assertSame(['environment' => 'staging'], $integration->config['inputs']);
        $this->assertSame(['X-API-Key' => 'key-123'], $integration->config['headers']);
        $this->assertSame('{"deploy": true}', $integration->config['body']);
    }

    public function test_mcp_update_suite_replaces_integrations_of_both_types(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $suite->integrations()->create([
            'type' => 'github_action',
            'config' => ['repository' => 'old/repo', 'workflow' => 'old.yml'],
        ]);

        SorifyServer::actingAs($admin)
            ->tool(UpdateSuiteTool::class, [
                'suite_id' => $suite->id,
                'name' => 'Suite',
                'integrations' => [
                    $this->validPayload(['repository' => 'new/repo']),
                    $this->validHttpPayload(['method' => 'GET', 'body' => null]),
                ],
            ])
            ->assertOk();

        $integrations = $suite->integrations()->get();
        $this->assertSame(2, $integrations->count());
        $this->assertSame('new/repo', $integrations->where('type', 'github_action')->first()->config['repository']);
        $this->assertSame('GET', $integrations->where('type', 'http_request')->first()->config['method']);
        $this->assertNull($integrations->where('type', 'http_request')->first()->config['body']);
    }

    public function test_mcp_update_suite_omitting_integrations_leaves_them_untouched(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $suite->integrations()->create([
            'type' => 'github_action',
            'config' => ['repository' => 'acme/app', 'workflow' => 'deploy.yml'],
        ]);

        SorifyServer::actingAs($admin)
            ->tool(UpdateSuiteTool::class, [
                'suite_id' => $suite->id,
                'name' => 'Renamed',
            ])
            ->assertOk();

        $this->assertSame(1, $suite->integrations()->count());
    }

    public function test_mcp_get_suite_returns_integrations(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $suite->integrations()->create([
            'type' => 'github_action',
            'label' => 'Deploy',
            'config' => ['repository' => 'acme/app', 'workflow' => 'deploy.yml'],
            'trigger_after' => true,
        ]);

        SorifyServer::actingAs($admin)
            ->tool(GetSuiteTool::class, ['suite_id' => $suite->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('suite.integrations.0.type', 'github_action')
                ->where('suite.integrations.0.config.repository', 'acme/app')
                ->etc());
    }
}
