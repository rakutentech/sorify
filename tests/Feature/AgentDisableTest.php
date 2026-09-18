<?php

namespace Tests\Feature;

use App\Models\AgentConversation;
use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin kill-switch: a user whose agents were disabled by an admin cannot
 * create profiles, test connections, start conversations or chat.
 */
class AgentDisableTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['agent_disabled' => true]);
    }

    public function test_disabled_user_cannot_store_a_profile(): void
    {
        $this->actingAs($this->user)->postJson('/sorify/agent/profiles', [
            'name' => 'X',
            'base_url' => 'https://api.openai.com',
            'api_token' => 'sk-x',
            'history_retention_days' => 30,
        ])->assertForbidden();
    }

    public function test_disabled_user_cannot_update_a_profile(): void
    {
        $profile = AgentProfile::create([
            'user_id' => $this->user->id,
            'name' => 'Mine',
            'base_url' => 'https://api.openai.com',
            'api_token' => 'sk-x',
            'history_retention_days' => 30,
        ]);

        $this->actingAs($this->user)->putJson("/sorify/agent/profiles/{$profile->id}", [
            'name' => 'Renamed',
            'base_url' => 'https://api.openai.com',
            'api_token' => null,
            'history_retention_days' => 30,
        ])->assertForbidden();
    }

    public function test_disabled_user_cannot_test_a_connection(): void
    {
        $this->actingAs($this->user)->postJson('/sorify/agent/profiles/test-connection', [
            'base_url' => 'https://api.openai.com',
            'api_token' => 'sk-x',
        ])->assertForbidden();
    }

    public function test_disabled_user_cannot_start_a_conversation(): void
    {
        $this->actingAs($this->user)->postJson('/sorify/agent/conversations', [
            'page_url' => '/sorify/suites',
        ])->assertForbidden();
    }

    public function test_disabled_user_cannot_chat(): void
    {
        $profile = AgentProfile::create([
            'user_id' => $this->user->id,
            'name' => 'Mine',
            'base_url' => 'https://api.openai.com',
            'api_token' => 'sk-x',
            'history_retention_days' => 30,
        ]);

        $conversation = AgentConversation::create([
            'user_id' => $this->user->id,
            'agent_profile_id' => $profile->id,
            'title' => 'A chat',
        ]);

        $this->actingAs($this->user)->postJson("/sorify/agent/conversations/{$conversation->id}/chat", [
            'message' => 'hello',
        ])->assertForbidden();
    }

    public function test_enabled_user_is_unaffected(): void
    {
        $enabled = User::factory()->create(['agent_disabled' => false]);

        $this->actingAs($enabled)->postJson('/sorify/agent/profiles', [
            'name' => 'X',
            'base_url' => 'https://api.openai.com',
            'api_token' => 'sk-x',
            'history_retention_days' => 30,
        ])->assertCreated();
    }

    public function test_admin_can_toggle_the_flag(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $target = User::factory()->create(['agent_disabled' => false]);

        $this->actingAs($admin)->putJson("/sorify/admin/users/{$target->id}", [
            'agent_disabled' => true,
        ])->assertRedirect();

        $this->assertTrue($target->fresh()->agent_disabled);

        $this->actingAs($admin)->putJson("/sorify/admin/users/{$target->id}", [
            'agent_disabled' => false,
        ])->assertRedirect();

        $this->assertFalse($target->fresh()->agent_disabled);
    }

    public function test_admin_users_index_reports_agent_state(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $configured = User::factory()->create();
        AgentProfile::create([
            'user_id' => $configured->id,
            'name' => 'Mine',
            'base_url' => 'https://api.openai.com',
            'api_token' => 'sk-x',
            'history_retention_days' => 30,
        ]);

        $response = $this->actingAs($admin)->get('/sorify/admin/users');

        $response
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Users/Index')
                ->where('users', fn ($users) => collect($users)
                    ->firstWhere('id', $configured->id)['has_agent_profile'] === true
                    && collect($users)->firstWhere('id', $this->user->id)['agent_disabled'] === true));
    }
}
