<?php

namespace Tests\Feature;

use App\Jobs\RunAgentTurnJob;
use App\Models\AgentConversation;
use App\Models\AgentMessage;
use App\Models\AgentProfile;
use App\Models\AgentTurn;
use App\Models\AgentTurnEvent;
use App\Models\User;
use App\Services\Agent\AgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Agent mode (formerly night mode): the per-conversation toggle for the
 * full agent — tools, background execution, max run time.
 */
class AgentModeTest extends TestCase
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

    public function test_agent_mode_is_off_by_default(): void
    {
        $conversation = AgentConversation::create($this->conversationAttributes())->fresh();

        $this->assertFalse($conversation->agent_mode);
        $this->assertSame(10, $conversation->agent_max_run_minutes);
    }

    public function test_update_toggles_agent_mode_and_max_run_minutes(): void
    {
        $conversation = AgentConversation::create($this->conversationAttributes());

        $response = $this->actingAs($this->user)->putJson("/sorify/agent/conversations/{$conversation->id}", [
            'agent_mode' => true,
            'agent_max_run_minutes' => 30,
        ]);

        $response->assertOk()
            ->assertJsonPath('conversation.agent_mode', true)
            ->assertJsonPath('conversation.agent_max_run_minutes', 30);

        $conversation = $conversation->fresh();

        $this->assertTrue($conversation->agent_mode);
        $this->assertSame(30, $conversation->agent_max_run_minutes);
    }

    public function test_update_rejects_invalid_max_run_minutes(): void
    {
        $conversation = AgentConversation::create($this->conversationAttributes());

        $this->actingAs($this->user)->putJson("/sorify/agent/conversations/{$conversation->id}", [
            'agent_mode' => true,
            'agent_max_run_minutes' => 7,
        ])->assertInvalid(['agent_max_run_minutes']);
    }

    public function test_chat_in_agent_mode_dispatches_job_and_returns_turn_id(): void
    {
        Queue::fake();

        $conversation = AgentConversation::create($this->conversationAttributes(['agent_mode' => true]));

        $response = $this->actingAs($this->user)->postJson("/sorify/agent/conversations/{$conversation->id}/chat", [
            'message' => 'write tests overnight',
            'model' => 'gpt-4o',
        ]);

        $response->assertStatus(202);

        $turnId = $response->json('turn_id');
        $userMessage = AgentMessage::query()->findOrFail($turnId);

        $this->assertSame('user', $userMessage->role);
        $this->assertSame($turnId, $userMessage->turn_id);
        $this->assertSame('write tests overnight', $conversation->fresh()->title);

        Queue::assertPushed(RunAgentTurnJob::class, fn (RunAgentTurnJob $job) => $job->turnId === $turnId
            && $job->conversationId === $conversation->id
            && $job->model === 'gpt-4o'
            && $job->mode === 'agent');
    }

    public function test_ask_turns_run_in_the_job_with_the_conversation_deadline(): void
    {
        $conversation = AgentConversation::create($this->conversationAttributes(['agent_mode' => false, 'agent_max_run_minutes' => 15]));

        $userMessage = AgentMessage::create([
            'turn_id' => 0,
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'hello',
        ]);
        $userMessage->forceFill(['turn_id' => $userMessage->id])->save();

        $agent = Mockery::mock(AgentService::class);
        $agent->shouldReceive('runTurn')
            ->once()
            ->withArgs(fn (AgentConversation $conv, AgentMessage $msg, $model, $mode, $deadline, $maxSteps) => $deadline === 15 * 60 && $mode === 'ask')
            ->andReturn((function () {
                yield ['event' => 'done', 'data' => ['reason' => 'answered']];
            })());

        $job = new RunAgentTurnJob($conversation->id, $userMessage->id, 'hello', 'gpt-4o', 'ask', null);

        $this->assertSame(15 * 60 + 120, $job->timeout());

        $job->handle($agent);

        // The turn's events are persisted so the client can follow them.
        $this->assertSame('done', AgentTurnEvent::query()->where('turn_id', $userMessage->id)->value('event'));
    }

    public function test_turn_events_stream_replays_events_and_ends_at_terminal(): void
    {
        $conversation = AgentConversation::create($this->conversationAttributes(['agent_mode' => true]));

        $userMessage = AgentMessage::create([
            'turn_id' => 0,
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'hello',
        ]);
        $userMessage->forceFill(['turn_id' => $userMessage->id])->save();

        AgentTurnEvent::create([
            'conversation_id' => $conversation->id,
            'turn_id' => $userMessage->id,
            'seq' => 1,
            'event' => 'step',
            'data' => ['step' => 1],
        ]);
        AgentTurnEvent::create([
            'conversation_id' => $conversation->id,
            'turn_id' => $userMessage->id,
            'seq' => 2,
            'event' => 'delta',
            'data' => ['text' => 'hi'],
        ]);
        AgentTurnEvent::create([
            'conversation_id' => $conversation->id,
            'turn_id' => $userMessage->id,
            'seq' => 3,
            'event' => 'done',
            'data' => ['message_id' => 9],
        ]);

        $response = $this->actingAs($this->user)
            ->get("/sorify/agent/conversations/{$conversation->id}/turns/{$userMessage->id}/events");

        $response->assertOk();

        $body = $response->streamedContent();

        $this->assertStringContainsString('event: step', $body);
        $this->assertStringContainsString('event: delta', $body);
        $this->assertStringContainsString('event: done', $body);
        $this->assertStringContainsString('id: 3', $body);
    }

    public function test_turn_events_after_cursor_skips_older_events(): void
    {
        $conversation = AgentConversation::create($this->conversationAttributes(['agent_mode' => true]));

        $userMessage = AgentMessage::create([
            'turn_id' => 0,
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'hello',
        ]);
        $userMessage->forceFill(['turn_id' => $userMessage->id])->save();

        AgentTurnEvent::create([
            'conversation_id' => $conversation->id,
            'turn_id' => $userMessage->id,
            'seq' => 1,
            'event' => 'step',
            'data' => ['step' => 1],
        ]);
        AgentTurnEvent::create([
            'conversation_id' => $conversation->id,
            'turn_id' => $userMessage->id,
            'seq' => 2,
            'event' => 'done',
            'data' => [],
        ]);

        $body = $this->actingAs($this->user)
            ->get("/sorify/agent/conversations/{$conversation->id}/turns/{$userMessage->id}/events?after=1")
            ->streamedContent();

        $this->assertStringNotContainsString('event: step', $body);
        $this->assertStringContainsString('event: done', $body);
    }

    public function test_turn_events_rejects_turn_of_foreign_conversation(): void
    {
        $conversation = AgentConversation::create($this->conversationAttributes(['agent_mode' => true]));
        $foreign = AgentConversation::create([
            'user_id' => User::factory()->create()->id,
            'agent_profile_id' => $this->profile->id,
            'title' => 'Foreign',
        ]);

        $userMessage = AgentMessage::create([
            'turn_id' => 0,
            'conversation_id' => $foreign->id,
            'role' => 'user',
            'content' => 'hello',
        ]);
        $userMessage->forceFill(['turn_id' => $userMessage->id])->save();

        $this->actingAs($this->user)
            ->getJson("/sorify/agent/conversations/{$conversation->id}/turns/{$userMessage->id}/events")
            ->assertNotFound();
    }

    public function test_turn_events_are_private_per_user(): void
    {
        $conversation = AgentConversation::create($this->conversationAttributes(['agent_mode' => true]));

        $userMessage = AgentMessage::create([
            'turn_id' => 0,
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'hello',
        ]);
        $userMessage->forceFill(['turn_id' => $userMessage->id])->save();

        $this->actingAs(User::factory()->create())
            ->getJson("/sorify/agent/conversations/{$conversation->id}/turns/{$userMessage->id}/events")
            ->assertForbidden();
    }

    public function test_messages_includes_active_turn(): void
    {
        $conversation = AgentConversation::create($this->conversationAttributes(['agent_mode' => true]));

        $userMessage = AgentMessage::create([
            'turn_id' => 0,
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'hello',
        ]);
        $userMessage->forceFill(['turn_id' => $userMessage->id])->save();

        AgentTurnEvent::create([
            'conversation_id' => $conversation->id,
            'turn_id' => $userMessage->id,
            'seq' => 1,
            'event' => 'step',
            'data' => ['step' => 1],
        ]);

        AgentTurn::create([
            'id' => $userMessage->id,
            'conversation_id' => $conversation->id,
            'user_id' => $this->user->id,
            'mode' => 'agent',
            'started_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->getJson("/sorify/agent/conversations/{$conversation->id}/messages");

        $response->assertOk()
            ->assertJsonPath('conversation.agent_mode', true)
            ->assertJsonPath('conversation.active_turn.turn_id', $userMessage->id)
            ->assertJsonPath('conversation.active_turn.last_seq', 1)
            ->assertJsonPath('conversation.active_turn.cancel_requested', false);

        // A stop was requested but the turn is still running — a
        // re-attached client must be able to tell it is stopping.
        AgentTurn::query()->whereKey($userMessage->id)->update(['cancel_requested_at' => now()]);

        $this->actingAs($this->user)->getJson("/sorify/agent/conversations/{$conversation->id}/messages")
            ->assertJsonPath('conversation.active_turn.cancel_requested', true);

        // A terminal event closes the turn — no longer active.
        AgentTurnEvent::create([
            'conversation_id' => $conversation->id,
            'turn_id' => $userMessage->id,
            'seq' => 2,
            'event' => 'done',
            'data' => ['message_id' => 5],
        ]);

        $this->actingAs($this->user)->getJson("/sorify/agent/conversations/{$conversation->id}/messages")
            ->assertJsonPath('conversation.active_turn', null);
    }

    public function test_job_failure_writes_terminal_error_event(): void
    {
        $conversation = AgentConversation::create($this->conversationAttributes(['agent_mode' => true]));

        $userMessage = AgentMessage::create([
            'turn_id' => 0,
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'hello',
        ]);
        $userMessage->forceFill(['turn_id' => $userMessage->id])->save();

        $job = new RunAgentTurnJob($conversation->id, $userMessage->id, 'hello', 'gpt-4o');
        $job->failed(new \RuntimeException('worker died'));

        $terminal = AgentTurnEvent::query()->where('turn_id', $userMessage->id)->get();

        $this->assertCount(1, $terminal);
        $this->assertSame('error', $terminal->first()->event);
        $this->assertSame(1, $terminal->first()->seq);
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
