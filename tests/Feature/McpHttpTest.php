<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpHttpTest extends TestCase
{
    use RefreshDatabase;

    private function toolsListPayload(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];
    }

    public function test_authenticated_request_lists_tools(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $response = $this->withBasicAuth($user->email, 'password123')
            ->postJson('/sorify/mcp', $this->toolsListPayload(), ['Accept' => 'application/json, text/event-stream']);

        $response->assertOk();
        $response->assertJsonPath('result.tools.0.name', 'list_suites');
    }

    public function test_request_without_credentials_is_unauthorized(): void
    {
        $response = $this->postJson('/sorify/mcp', $this->toolsListPayload(), ['Accept' => 'application/json, text/event-stream']);

        $response->assertStatus(401);
    }

    public function test_request_with_bad_credentials_is_unauthorized(): void
    {
        User::factory()->create(['email' => 'user@example.com', 'password' => 'password123']);

        $response = $this->withBasicAuth('user@example.com', 'wrong-password')
            ->postJson('/sorify/mcp', $this->toolsListPayload(), ['Accept' => 'application/json, text/event-stream']);

        $response->assertStatus(401);
    }

    public function test_get_is_not_allowed(): void
    {
        $this->get('/sorify/mcp')->assertStatus(405);
    }

    public function test_delete_is_not_allowed(): void
    {
        $this->delete('/sorify/mcp')->assertStatus(405);
    }

    public function test_old_rest_api_routes_no_longer_exist(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/sorify/api/suites')->assertStatus(404);
    }
}
