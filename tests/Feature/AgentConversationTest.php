<?php

namespace Tests\Feature;

use App\Jobs\RunAgentTurnJob;
use App\Models\AgentConversation;
use App\Models\AgentMessage;
use App\Models\AgentProfile;
use App\Models\AgentTurn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

    public function test_conversation_list_shows_running_and_queued_turn_status(): void
    {
        $running = AgentConversation::create($this->conversationAttributes(['title' => 'Running']));
        $queued = AgentConversation::create($this->conversationAttributes(['title' => 'Queued']));
        $idle = AgentConversation::create($this->conversationAttributes(['title' => 'Idle']));

        // Unfinished tracked turn = running.
        AgentTurn::create([
            'conversation_id' => $running->id,
            'user_id' => $this->user->id,
            'mode' => 'agent',
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        // A turn job waiting in the agent queue = queued. The payload text
        // carries the serialized command's conversation id, like the real
        // dispatcher writes it.
        $this->insertAgentQueueJob($queued->id);

        $response = $this->actingAs($this->user)->getJson('/sorify/agent/conversations');

        $byTitle = collect($response->json('conversations'))->keyBy('title');

        $this->assertSame('running', $byTitle['Running']['status']);
        $this->assertNotNull($byTitle['Running']['last_activity_at']);

        $this->assertSame('queued', $byTitle['Queued']['status']);
        $this->assertNull($byTitle['Queued']['last_activity_at']);

        $this->assertNull($byTitle['Idle']['status']);
    }

    public function test_running_status_beats_queued_for_the_same_conversation(): void
    {
        $conversation = AgentConversation::create($this->conversationAttributes());

        // A running turn's job also sits in the queue table (reserved) —
        // running must win so the list doesn't say both.
        AgentTurn::create([
            'conversation_id' => $conversation->id,
            'user_id' => $this->user->id,
            'mode' => 'agent',
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $this->insertAgentQueueJob($conversation->id);

        $response = $this->actingAs($this->user)->getJson('/sorify/agent/conversations');

        $this->assertSame('running', $response->json('conversations.0.status'));
    }

    /**
     * Insert a RunAgentTurnJob-shaped row into the queue table, the way
     * the dispatcher writes it — only the conversation id matters to the
     * list endpoint's status detection.
     */
    private function insertAgentQueueJob(int $conversationId): void
    {
        DB::table('jobs')->insert([
            'queue' => 'agent',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\RunAgentTurnJob',
                'data' => ['command' => 'O:24:"App\\Jobs\\RunAgentTurnJob":22:{s:14:"conversationId";i:'.$conversationId.';}'],
            ]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);
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

    public function test_store_creates_conversation_in_agent_mode_by_default(): void
    {
        $response = $this->actingAs($this->user)->postJson('/sorify/agent/conversations', [
            'profile_id' => $this->profile->id,
        ]);

        $response->assertCreated()->assertJsonPath('conversation.agent_mode', true);

        $this->assertTrue((bool) AgentConversation::findOrFail($response->json('conversation.id'))->agent_mode);
    }

    public function test_store_can_opt_out_of_agent_mode(): void
    {
        $response = $this->actingAs($this->user)->postJson('/sorify/agent/conversations', [
            'profile_id' => $this->profile->id,
            'agent_mode' => false,
        ]);

        $response->assertCreated()->assertJsonPath('conversation.agent_mode', false);
    }

    public function test_store_creates_conversation_in_agent_mode(): void
    {
        // The AI buttons ("explain error", "explain code", …) start chats
        // that imply real agent work — the conversation is created with
        // agent mode on so tools are available from the first turn.
        $response = $this->actingAs($this->user)->postJson('/sorify/agent/conversations', [
            'profile_id' => $this->profile->id,
            'agent_mode' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('conversation.agent_mode', true);

        $conversation = AgentConversation::findOrFail($response->json('conversation.id'));

        $this->assertTrue((bool) $conversation->agent_mode);
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

    public function test_chat_in_ask_mode_dispatches_job_and_returns_turn_id(): void
    {
        Queue::fake();

        $conversation = AgentConversation::create($this->conversationAttributes());

        $response = $this->actingAs($this->user)->postJson("/sorify/agent/conversations/{$conversation->id}/chat", [
            'message' => 'hello',
            'max_steps' => 25,
        ]);

        $response->assertStatus(202);

        $turnId = $response->json('turn_id');

        $this->assertSame('user', AgentMessage::query()->findOrFail($turnId)->role);

        // Agent mode off means Ask mode — same job, just without tools.
        Queue::assertPushed(RunAgentTurnJob::class, fn (RunAgentTurnJob $job) => $job->turnId === $turnId
            && $job->conversationId === $conversation->id
            && $job->mode === 'ask'
            && $job->maxSteps === 25);
    }

    public function test_chat_rejects_a_max_steps_outside_the_offered_options(): void
    {
        $conversation = AgentConversation::create($this->conversationAttributes());

        $this->actingAs($this->user)->postJson("/sorify/agent/conversations/{$conversation->id}/chat", [
            'message' => 'hello',
            'max_steps' => 33,
        ])->assertStatus(422)->assertJsonValidationErrors(['max_steps']);
    }

    public function test_chat_rejects_an_unknown_reasoning_effort(): void
    {
        $conversation = AgentConversation::create($this->conversationAttributes());

        $this->actingAs($this->user)->postJson("/sorify/agent/conversations/{$conversation->id}/chat", [
            'message' => 'hello',
            'reasoning_effort' => 'ultra',
        ])->assertStatus(422)->assertJsonValidationErrors(['reasoning_effort']);
    }

    public function test_chat_accepts_the_max_reasoning_effort(): void
    {
        Queue::fake();
        $conversation = AgentConversation::create($this->conversationAttributes());

        $response = $this->actingAs($this->user)->postJson("/sorify/agent/conversations/{$conversation->id}/chat", [
            'message' => 'hello',
            'reasoning_effort' => 'max',
        ]);

        $response->assertStatus(202);

        // 'max' reaches the turn job verbatim — hosted DeepSeek-style
        // endpoints take low/high/max instead of OpenAI's medium.
        Queue::assertPushed(RunAgentTurnJob::class, fn (RunAgentTurnJob $job) => $job->reasoningEffort === 'max');
    }

    public function test_chat_accepts_the_none_reasoning_effort(): void
    {
        Queue::fake();
        $conversation = AgentConversation::create($this->conversationAttributes());

        $response = $this->actingAs($this->user)->postJson("/sorify/agent/conversations/{$conversation->id}/chat", [
            'message' => 'hello',
            'reasoning_effort' => 'none',
        ]);

        $response->assertStatus(202);

        // 'none' is the explicit opt-out gateways ask for when function
        // tools conflict with their default reasoning.
        Queue::assertPushed(RunAgentTurnJob::class, fn (RunAgentTurnJob $job) => $job->reasoningEffort === 'none');
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

    public function test_destroy_all_deletes_only_my_conversations(): void
    {
        $mine = AgentConversation::create($this->conversationAttributes(['title' => 'Mine']));

        $other = User::factory()->create();

        $theirs = AgentConversation::create([
            'user_id' => $other->id,
            'title' => 'Theirs',
        ]);

        AgentMessage::create([
            'turn_id' => 1,
            'conversation_id' => $mine->id,
            'role' => 'user',
            'content' => 'hello',
        ]);

        $response = $this->actingAs($this->user)->deleteJson('/sorify/agent/conversations');

        $response->assertOk()->assertJsonPath('deleted', 1);

        $this->assertNull($mine->fresh());
        $this->assertSame(0, AgentMessage::count());
        $this->assertNotNull($theirs->fresh());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function test_cancel_turn_flags_running_turns_of_the_conversation(): void
    {
        $conversation = AgentConversation::create($this->conversationAttributes());

        $running = AgentTurn::create([
            'id' => 21,
            'conversation_id' => $conversation->id,
            'user_id' => $this->user->id,
            'mode' => 'ask',
            'started_at' => now()->subMinute(),
        ]);

        $finished = AgentTurn::create([
            'id' => 22,
            'conversation_id' => $conversation->id,
            'user_id' => $this->user->id,
            'mode' => 'agent',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
        ]);

        // A running turn in another conversation must not be touched.
        $otherTurn = AgentTurn::create([
            'id' => 23,
            'conversation_id' => AgentConversation::create($this->conversationAttributes(['title' => 'Other']))->id,
            'user_id' => $this->user->id,
            'mode' => 'agent',
            'started_at' => now()->subMinute(),
        ]);

        $this->actingAs($this->user)->postJson("/sorify/agent/conversations/{$conversation->id}/cancel-turn")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNotNull($running->fresh()->cancel_requested_at);
        $this->assertNull($finished->fresh()->cancel_requested_at);
        $this->assertNull($otherTurn->fresh()->cancel_requested_at);
    }

    public function test_cancel_turn_closes_a_stale_turn_immediately(): void
    {
        $conversation = AgentConversation::create($this->conversationAttributes());

        // No activity for 20 minutes — the worker died without closing it.
        $turn = AgentTurn::create([
            'id' => 24,
            'conversation_id' => $conversation->id,
            'user_id' => $this->user->id,
            'mode' => 'agent',
            'started_at' => now()->subMinutes(25),
            'last_activity_at' => now()->subMinutes(20),
        ]);

        $this->actingAs($this->user)->postJson("/sorify/agent/conversations/{$conversation->id}/cancel-turn")
            ->assertOk();

        $fresh = $turn->fresh();

        $this->assertNotNull($fresh->cancel_requested_at);
        $this->assertNotNull($fresh->finished_at);
    }

    public function test_cancel_turn_is_private_per_user(): void
    {
        $conversation = AgentConversation::create($this->conversationAttributes());

        $other = User::factory()->create();

        $this->actingAs($other)->postJson("/sorify/agent/conversations/{$conversation->id}/cancel-turn")
            ->assertForbidden();
    }

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
