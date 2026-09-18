<?php

namespace Tests\Feature;

use App\Models\AgentConversation;
use App\Models\AgentProfile;
use App\Models\AgentTurn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin: the running-AI-agents page — listing turns and stopping them.
 */
class AdminAgentRunsTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_lists_running_turns_with_user_and_elapsed_time(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['name' => 'Alice']);

        $profile = AgentProfile::create([
            'user_id' => $user->id,
            'name' => 'Mine',
            'base_url' => 'https://api.openai.com',
            'api_token' => 'sk-x',
            'history_retention_days' => 30,
        ]);

        $conversation = AgentConversation::create([
            'user_id' => $user->id,
            'agent_profile_id' => $profile->id,
            'title' => 'Crawl the checkout',
        ]);

        AgentTurn::create([
            'id' => 42,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'mode' => 'agent',
            'started_at' => now()->subMinutes(3),
            'last_activity_at' => now()->subSeconds(10),
        ]);

        $response = $this->actingAs($admin)->get('/sorify/admin/agent-runs');

        $response
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AgentRuns/Index')
                ->has('turns', 1)
                ->where('turns.0.mode', 'agent')
                ->where('turns.0.user.name', 'Alice')
                ->where('turns.0.stale', false)
                ->where('turns.0.cancel_requested', false)
                ->where('turns.0.elapsed_seconds', fn ($seconds) => $seconds >= 170)
                // Chat contents stay private — no conversation payload.
                ->missing('turns.0.conversation'));
    }

    public function test_ask_turns_are_listed_without_chat_contents(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        $conversation = AgentConversation::create([
            'user_id' => $user->id,
            'title' => 'Private live chat',
        ]);

        AgentTurn::create([
            'id' => 2,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'mode' => 'ask',
            'started_at' => now()->subMinute(),
        ]);

        $this->actingAs($admin)->get('/sorify/admin/agent-runs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('turns', 1)
                ->where('turns.0.mode', 'ask')
                ->missing('turns.0.conversation'));
    }

    public function test_finished_turns_are_not_listed(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        $conversation = AgentConversation::create([
            'user_id' => $user->id,
            'title' => 'Done chat',
        ]);

        AgentTurn::create([
            'id' => 1,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'mode' => 'agent',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
        ]);

        $response = $this->actingAs($admin)->get('/sorify/admin/agent-runs');

        $response
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('turns', []));
    }

    public function test_stop_flags_a_running_turn(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        $conversation = AgentConversation::create([
            'user_id' => $user->id,
            'title' => 'A chat',
        ]);

        $turn = AgentTurn::create([
            'id' => 7,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'mode' => 'agent',
            'started_at' => now()->subMinute(),
        ]);

        $this->actingAs($admin)->postJson("/sorify/admin/agent-runs/{$turn->id}/stop")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNotNull($turn->fresh()->cancel_requested_at);
    }

    public function test_stop_a_finished_turn_conflicts(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        $conversation = AgentConversation::create([
            'user_id' => $user->id,
            'title' => 'A chat',
        ]);

        $turn = AgentTurn::create([
            'id' => 8,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'mode' => 'agent',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
        ]);

        $this->actingAs($admin)->postJson("/sorify/admin/agent-runs/{$turn->id}/stop")
            ->assertConflict();
    }

    public function test_stop_closes_a_stale_turn_immediately(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        $conversation = AgentConversation::create([
            'user_id' => $user->id,
            'title' => 'A chat',
        ]);

        // No activity for 20 minutes — the worker died without closing it.
        $turn = AgentTurn::create([
            'id' => 11,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'mode' => 'agent',
            'started_at' => now()->subMinutes(25),
            'last_activity_at' => now()->subMinutes(20),
        ]);

        $this->actingAs($admin)->postJson("/sorify/admin/agent-runs/{$turn->id}/stop")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $fresh = $turn->fresh();

        $this->assertNotNull($fresh->cancel_requested_at);
        $this->assertNotNull($fresh->finished_at);
    }

    public function test_stop_flags_an_ask_turn(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();

        $conversation = AgentConversation::create([
            'user_id' => $user->id,
            'title' => 'A live chat',
        ]);

        $turn = AgentTurn::create([
            'id' => 32,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'mode' => 'ask',
            'started_at' => now()->subMinute(),
        ]);

        $this->actingAs($admin)->postJson("/sorify/admin/agent-runs/{$turn->id}/stop")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNotNull($turn->fresh()->cancel_requested_at);
    }

    public function test_non_admins_are_rejected(): void
    {
        $user = User::factory()->create();

        $conversation = AgentConversation::create([
            'user_id' => $user->id,
            'title' => 'A chat',
        ]);

        $turn = AgentTurn::create([
            'id' => 9,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'mode' => 'agent',
            'started_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)->get('/sorify/admin/agent-runs')->assertForbidden();
        $this->actingAs($user)->postJson("/sorify/admin/agent-runs/{$turn->id}/stop")->assertForbidden();

        // Nothing was touched.
        $this->assertNull($turn->fresh()->cancel_requested_at);
    }
}
