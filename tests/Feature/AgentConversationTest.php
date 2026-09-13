<?php

namespace Tests\Feature;

use App\Models\AgentConversation;
use App\Models\AgentMessage;
use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentConversationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AgentProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->profile = AgentProfile::create([
            'user_id' => $this->user->id,
            'name' => 'My OpenAI',
            'base_url' => 'https://api.openai.com',
            'api_token' => 'sk-secret',
            'history_retention_days' => 30,
        ]);
    }

    public function test_conversations_are_listed_most_recent_first(): void
    {
        $older = AgentConversation::create($this->conversationAttributes(['title' => 'Older', 'updated_at' => now()->subDay()]));
        $newer = AgentConversation::create($this->conversationAttributes(['title' => 'Newer']));

        $response = $this->actingAs($this->user)->getJson('/sorify/agent/conversations');

        $response->assertOk();

        $this->assertSame('Newer', $response->json('conversations.0.title'));
        $this->assertSame('Older', $response->json('conversations.1.title'));
    }

    public function test_conversations_are_private_per_user(): void
    {
        AgentConversation::create($this->conversationAttributes());

        $other = User::factory()->create();

        $this->actingAs($other)->getJson('/sorify/agent/conversations')
            ->assertOk()
            ->assertJsonCount(0, 'conversations');
    }

    public function test_store_creates_conversation_with_page_context(): void
    {
        $response = $this->actingAs($this->user)->postJson('/sorify/agent/conversations', [
            'profile_id' => $this->profile->id,
            'page_url' => '/sorify/suites/12',
            'page_name' => 'TestSuites/Show',
            'context' => 'User is viewing suite 12 (Checkout flow).',
        ]);

        $response->assertCreated();

        $conversation = AgentConversation::findOrFail($response->json('conversation.id'));

        $this->assertSame('TestSuites/Show', $conversation->page_name);
        $this->assertSame('/sorify/suites/12', $conversation->page_url);
        $this->assertSame('User is viewing suite 12 (Checkout flow).', $conversation->context);
        $this->assertSame($this->profile->id, $conversation->agent_profile_id);
    }

    public function test_store_rejects_foreign_profile(): void
    {
        $foreign = AgentProfile::create([
            'user_id' => User::factory()->create()->id,
            'name' => 'Foreign',
            'base_url' => 'https://api.openai.com',
            'api_token' => 'sk-x',
            'history_retention_days' => 30,
        ]);

        $response = $this->actingAs($this->user)->postJson('/sorify/agent/conversations', [
            'profile_id' => $foreign->id,
        ]);

        $response->assertForbidden();
    }

    public function test_messages_endpoint_returns_own_transcript_only(): void
    {
        $conversation = AgentConversation::create($this->conversationAttributes());
        $foreign = AgentConversation::create([
            'user_id' => User::factory()->create()->id,
            'agent_profile_id' => $this->profile->id,
            'title' => 'Foreign',
        ]);

        AgentMessage::create([
            'turn_id' => 1,
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'mine',
        ]);

        $response = $this->actingAs($this->user)->getJson("/sorify/agent/conversations/{$conversation->id}/messages");

        $response->assertOk()
            ->assertJsonPath('conversation.context', 'Some context')
            ->assertJsonCount(1, 'messages');

        $this->actingAs($this->user)
            ->getJson("/sorify/agent/conversations/{$foreign->id}/messages")
            ->assertForbidden();
    }

    public function test_update_edits_title_and_context(): void
    {
        $conversation = AgentConversation::create($this->conversationAttributes());

        $response = $this->actingAs($this->user)->putJson("/sorify/agent/conversations/{$conversation->id}", [
            'title' => 'Renamed chat',
            'context' => 'Updated context',
        ]);

        $response->assertOk();

        $conversation = $conversation->fresh();

        $this->assertSame('Renamed chat', $conversation->title);
        $this->assertSame('Updated context', $conversation->context);
    }

    public function test_chat_requires_a_profile_on_the_conversation(): void
    {
        $conversation = AgentConversation::create([
            'user_id' => $this->user->id,
            'agent_profile_id' => null,
            'title' => 'No profile',
        ]);

        $response = $this->actingAs($this->user)->postJson("/sorify/agent/conversations/{$conversation->id}/chat", [
            'message' => 'hello',
        ]);

        $response->assertStatus(400);
    }

    public function test_destroy_deletes_conversation_and_messages(): void
    {
        $conversation = AgentConversation::create($this->conversationAttributes());

        AgentMessage::create([
            'turn_id' => 1,
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'hello',
        ]);

        $other = User::factory()->create();

        $this->actingAs($other)->deleteJson("/sorify/agent/conversations/{$conversation->id}")
            ->assertForbidden();

        $this->actingAs($this->user)->deleteJson("/sorify/agent/conversations/{$conversation->id}")
            ->assertOk();

        $this->assertNull($conversation->fresh());
        $this->assertSame(0, AgentMessage::count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function conversationAttributes(array $overrides = []): array
    {
        return [
            'user_id' => $this->user->id,
            'agent_profile_id' => $this->profile->id,
            'title' => 'A chat',
            'page_url' => '/sorify/suites',
            'page_name' => 'TestSuites/Index',
            'context' => 'Some context',
            ...$overrides,
        ];
    }
}
