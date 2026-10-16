<?php

namespace Tests\Feature;

use App\Models\AgentConversation;
use App\Models\AgentMessage;
use App\Models\AgentProfile;
use App\Models\User;
use App\Services\Agent\AgentService;
use App\Services\Agent\AgentToolAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Tool as PrismTool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The agent's Prism transport: turns are streamed through Prism's
 * chat-completions provider, its stream events map onto the chat's SSE
 * protocol, tools execute in-process and their results are fed back for
 * the next round-trip.
 */
class AgentPrismTurnTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AgentProfile $profile;

    private AgentConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->profile = AgentProfile::create([
            'user_id' => $this->user->id,
            'name' => 'My Gateway',
            'base_url' => 'https://gateway.example.test/v1',
            'api_token' => 'sk-secret',
            'history_retention_days' => 30,
        ]);

        $this->conversation = AgentConversation::create([
            'user_id' => $this->user->id,
            'agent_profile_id' => $this->profile->id,
            'title' => 'A chat',
            'page_url' => '/sorify/suites',
            'page_name' => 'TestSuites/Index',
            'context' => 'Some context',
        ]);
    }

    public function test_ask_mode_streams_a_plain_answer(): void
    {
        Http::fake(['*/chat/completions' => Http::response(
            $this->sse(
                '{"id":"c1","choices":[{"delta":{"role":"assistant","content":"Hello"}}]}',
                '{"id":"c1","choices":[{"delta":{"content":" there"}}]}',
                '{"id":"c1","choices":[{"delta":{},"finish_reason":"stop"}]}',
            ),
            200,
            ['Content-Type' => 'text/event-stream'],
        )]);

        $events = $this->runTurn('Hi', mode: 'ask');

        $this->assertSame('step', $events[0]['event']);
        $this->assertNull($events[0]['data']['max_steps']);

        $this->assertSame('delta', $events[1]['event']);
        $this->assertSame(['text' => 'Hello'], $events[1]['data']);
        $this->assertSame(['text' => ' there'], $events[2]['data']);

        $this->assertSame('done', $events[3]['event']);
        $this->assertNotNull($events[3]['data']['message_id']);

        $this->assertDatabaseHas('agent_messages', [
            'id' => $events[3]['data']['message_id'],
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'content' => 'Hello there',
        ]);
    }

    public function test_chat_requests_send_profile_extra_headers(): void
    {
        // Gateways sometimes route by client identity — the profile's
        // custom headers must reach the chat-completions request, merged
        // with (not replacing) Prism's Authorization header.
        $this->profile->update(['extra_headers' => ['User-Agent' => 'opencode/1.0.0']]);

        Http::fake(['*/chat/completions' => Http::response(
            $this->sse(
                '{"id":"c1","choices":[{"delta":{"role":"assistant","content":"Hello"}}]}',
                '{"id":"c1","choices":[{"delta":{},"finish_reason":"stop"}]}',
            ),
            200,
            ['Content-Type' => 'text/event-stream'],
        )]);

        $this->runTurn('Hi', mode: 'ask');

        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent', 'opencode/1.0.0')
            && $request->hasHeader('Authorization', 'Bearer sk-secret')
        );
    }

    public function test_reasoning_effort_is_forwarded_when_picked(): void
    {
        Http::fake(['*/chat/completions' => Http::response(
            $this->sse(
                '{"id":"c1","choices":[{"delta":{"role":"assistant","content":"Hello"}}]}',
                '{"id":"c1","choices":[{"delta":{},"finish_reason":"stop"}]}',
            ),
            200,
            ['Content-Type' => 'text/event-stream'],
        )]);

        $this->runTurn('Hi', mode: 'ask', reasoningEffort: 'low');

        Http::assertSent(fn ($request) => ($request->data()['reasoning_effort'] ?? null) === 'low');
    }

    public function test_max_reasoning_effort_is_forwarded_when_picked(): void
    {
        Http::fake(['*/chat/completions' => Http::response(
            $this->sse(
                '{"id":"c1","choices":[{"delta":{"role":"assistant","content":"Hello"}}]}',
                '{"id":"c1","choices":[{"delta":{},"finish_reason":"stop"}]}',
            ),
            200,
            ['Content-Type' => 'text/event-stream'],
        )]);

        $this->runTurn('Hi', mode: 'ask', reasoningEffort: 'max');

        Http::assertSent(fn ($request) => ($request->data()['reasoning_effort'] ?? null) === 'max');
    }

    public function test_rejected_reasoning_effort_is_retried_without_it(): void
    {
        $requests = [];

        Http::fake(['*/chat/completions' => function ($request) use (&$requests) {
            $requests[] = json_decode($request->body(), true);

            // First request: the endpoint validates reasoning_effort against
            // its own set (hosted vLLM DeepSeek: low/high/max — no medium).
            if (count($requests) === 1) {
                return Http::response(json_encode([
                    'error' => [
                        'message' => 'all: invalid reasoning_effort: only low, high or max is allowed for this model',
                        'type' => 'invalid_request_error',
                        'code' => 400,
                    ],
                ]), 400, ['Content-Type' => 'application/json']);
            }

            return Http::response(
                $this->sse(
                    '{"id":"c1","choices":[{"delta":{"role":"assistant","content":"Hello"}}]}',
                    '{"id":"c1","choices":[{"delta":{},"finish_reason":"stop"}]}',
                ),
                200,
                ['Content-Type' => 'text/event-stream'],
            );
        }]);

        $events = $this->runTurn('Hi', mode: 'ask', reasoningEffort: 'medium');

        // The rejected request carried the picked effort; the retry dropped it.
        $this->assertSame('medium', $requests[0]['reasoning_effort'] ?? null);
        $this->assertArrayNotHasKey('reasoning_effort', $requests[1]);

        // The turn recovered and completed instead of failing.
        $this->assertSame('done', end($events)['event']);
        $this->assertDatabaseHas('agent_messages', [
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'content' => 'Hello',
        ]);
    }

    public function test_gateway_requiring_explicit_none_is_retried_with_it(): void
    {
        $requests = [];

        Http::fake(['*/chat/completions' => function ($request) use (&$requests) {
            $requests[] = json_decode($request->body(), true);

            // First request: no reasoning_effort was sent (model default),
            // but the gateway applies the model's own default reasoning
            // and rejects it alongside function tools.
            if (count($requests) === 1) {
                return Http::response(json_encode([
                    'error' => [
                        'message' => "Function tools with reasoning_effort are not supported for gpt-5.6-terra in /v1/chat/completions. To use function tools, use /v1/responses or set reasoning_effort to 'none'.",
                        'type' => 'invalid_request_error',
                        'code' => 400,
                    ],
                ]), 400, ['Content-Type' => 'application/json']);
            }

            return Http::response(
                $this->sse(
                    '{"id":"c1","choices":[{"delta":{"role":"assistant","content":"Hello"}}]}',
                    '{"id":"c1","choices":[{"delta":{},"finish_reason":"stop"}]}',
                ),
                200,
                ['Content-Type' => 'text/event-stream'],
            );
        }]);

        // Agent mode (tools ride along), model default effort.
        $stubTool = (new PrismTool)->as('get_suite')->for('Get a suite')->using(fn (...$arguments) => 'Suite 7: Demo');

        $events = $this->runTurn('Hi', mode: 'agent', tools: [$stubTool]);

        // The rejected request sent nothing; the retry opted out explicitly.
        $this->assertArrayNotHasKey('reasoning_effort', $requests[0]);
        $this->assertSame('none', $requests[1]['reasoning_effort'] ?? null);

        // The turn recovered and completed instead of failing.
        $this->assertSame('done', end($events)['event']);
        $this->assertDatabaseHas('agent_messages', [
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'content' => 'Hello',
        ]);
    }

    public function test_reasoning_effort_is_omitted_for_the_model_default(): void
    {
        Http::fake(['*/chat/completions' => Http::response(
            $this->sse(
                '{"id":"c1","choices":[{"delta":{"role":"assistant","content":"Hello"}}]}',
                '{"id":"c1","choices":[{"delta":{},"finish_reason":"stop"}]}',
            ),
            200,
            ['Content-Type' => 'text/event-stream'],
        )]);

        $this->runTurn('Hi', mode: 'ask');

        // "Model default" must send nothing — endpoints differ in what
        // they reject, so an explicit value must not be sent.
        Http::assertSent(fn ($request) => ! array_key_exists('reasoning_effort', $request->data()));
    }

    public function test_null_max_tokens_is_omitted_from_the_request_body(): void
    {
        Http::fake(['*/chat/completions' => Http::response(
            $this->sse(
                '{"id":"c1","choices":[{"delta":{"role":"assistant","content":"Hello"}}]}',
                '{"id":"c1","choices":[{"delta":{},"finish_reason":"stop"}]}',
            ),
            200,
            ['Content-Type' => 'text/event-stream'],
        )]);

        $this->runTurn('Hi', mode: 'ask');

        // No max is configured, so an explicit null must not be sent —
        // strict gateways reject it ("Invalid type for 'max_tokens'")
        // while the model's own default applies.
        Http::assertSent(fn ($request) => ! array_key_exists('max_tokens', $request->data()));
    }

    public function test_agent_mode_executes_tools_and_feeds_results_back(): void
    {
        $requests = [];

        Http::fake(['*/chat/completions' => function ($request) use (&$requests) {
            $requests[] = json_decode($request->body(), true);

            $body = count($requests) === 1
                ? $this->sse(
                    '{"id":"c1","choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_1","function":{"name":"get_suite","arguments":"{\"suite_id\":"}}]}}]}',
                    '{"id":"c1","choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"7}"}}]},"finish_reason":"tool_calls"}]}',
                )
                : $this->sse(
                    '{"id":"c2","choices":[{"delta":{"role":"assistant","content":"Suite 7 is named Demo."}}]}',
                    '{"id":"c2","choices":[{"delta":{},"finish_reason":"stop"}]}',
                );

            return Http::response($body, 200, ['Content-Type' => 'text/event-stream']);
        }]);

        $stubTool = (new PrismTool)->as('get_suite')->for('Get a suite')->using(fn (...$arguments) => 'Suite 7: Demo');

        $events = $this->runTurn('What is suite 7?', tools: [$stubTool]);

        // The chat's SSE protocol: step, tool_start, tool_result, step,
        // delta…, done.
        $this->assertSame('step', $events[0]['event']);
        $this->assertSame(100, $events[0]['data']['max_steps']);

        $this->assertSame('tool_start', $events[1]['event']);
        $this->assertSame('get_suite', $events[1]['data']['name']);
        $this->assertSame(['suite_id' => 7], $events[1]['data']['arguments']);

        $this->assertSame('tool_result', $events[2]['event']);
        $this->assertSame('call_1', $events[2]['data']['id']);
        $this->assertFalse($events[2]['data']['is_error']);
        $this->assertSame('Suite 7: Demo', $events[2]['data']['result']);

        $this->assertSame('step', $events[3]['event']);
        $this->assertSame(['text' => 'Suite 7 is named Demo.'], $events[4]['data']);
        $this->assertSame('done', $events[5]['event']);

        // The second round-trip replays the tool result to the LLM…
        $this->assertCount(2, $requests);

        $firstMessages = collect($requests[0]['messages']);
        $this->assertSame('system', $firstMessages->first()['role']);
        $this->assertSame('user', $firstMessages->last()['role']);
        $this->assertNotEmpty($requests[0]['tools']);
        $this->assertSame('get_suite', $requests[0]['tools'][0]['function']['name']);

        $secondMessages = collect($requests[1]['messages']);
        $this->assertSame('system', $secondMessages->first()['role']);

        $toolMessage = $secondMessages->firstWhere('role', 'tool');
        $this->assertSame('call_1', $toolMessage['tool_call_id']);
        $this->assertSame('Suite 7: Demo', $toolMessage['content']);

        $assistantCall = $secondMessages->firstWhere('role', 'assistant');
        $this->assertSame('call_1', $assistantCall['tool_calls'][0]['id']);
        $this->assertSame('get_suite', $assistantCall['tool_calls'][0]['function']['name']);

        // …and the transcript persisted the step: the assistant row with
        // its tool calls, then the tool row.
        $this->assertDatabaseHas('agent_messages', [
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'content' => null,
        ]);

        $this->assertDatabaseHas('agent_messages', [
            'conversation_id' => $this->conversation->id,
            'role' => 'tool',
            'tool_call_id' => 'call_1',
            'name' => 'get_suite',
            'content' => 'Suite 7: Demo',
        ]);

        $this->assertDatabaseHas('agent_messages', [
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'content' => 'Suite 7 is named Demo.',
        ]);
    }

    public function test_tool_errors_are_surfaced_without_killing_the_turn(): void
    {
        Http::fake(['*/chat/completions' => function () {
            static $round = 0;
            $round++;

            $body = $round === 1
                ? $this->sse('{"id":"c1","choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_1","function":{"name":"get_suite","arguments":"{}"}}]},"finish_reason":"tool_calls"}]}')
                : $this->sse(
                    '{"id":"c2","choices":[{"delta":{"role":"assistant","content":"It failed."}}]}',
                    '{"id":"c2","choices":[{"delta":{},"finish_reason":"stop"}]}',
                );

            return Http::response($body, 200, ['Content-Type' => 'text/event-stream']);
        }]);

        $stubTool = (new PrismTool)
            ->as('get_suite')
            ->for('Get a suite')
            ->using(fn (...$arguments) => throw new \RuntimeException('boom'))
            ->failed(fn (\Throwable $exception) => 'tool exploded');

        $events = $this->runTurn('What is suite 7?', tools: [$stubTool]);

        $toolResult = collect($events)->firstWhere('event', 'tool_result');

        $this->assertTrue($toolResult['data']['is_error']);
        $this->assertSame('tool exploded', $toolResult['data']['result']);

        $this->assertSame('done', $events[count($events) - 1]['event']);

        // The error output is what the LLM sees on the next round-trip.
        $toolRow = AgentMessage::query()
            ->where('conversation_id', $this->conversation->id)
            ->where('role', 'tool')
            ->first();

        $this->assertSame('tool exploded', $toolRow->content);
    }

    public function test_step_budget_exhaustion_is_reported(): void
    {
        // A closure (not a static stub) so every round-trip gets a fresh
        // response body — a static one is consumed by the first request.
        Http::fake(['*/chat/completions' => fn () => Http::response(
            $this->sse('{"id":"c1","choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_1","function":{"name":"get_suite","arguments":"{}"}}]},"finish_reason":"tool_calls"}]}'),
            200,
            ['Content-Type' => 'text/event-stream'],
        )]);

        $stubTool = (new PrismTool)->as('get_suite')->for('Get a suite')->using(fn (...$arguments) => 'ok');

        $events = $this->runTurn('Keep going', maxSteps: 2, tools: [$stubTool]);

        $done = $events[count($events) - 1];

        $this->assertSame('done', $done['event']);
        $this->assertSame('max_steps', $done['data']['reason']);

        $this->assertDatabaseHas('agent_messages', [
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'content' => 'I stopped after reaching the maximum number of tool-call steps (2). Please continue in a new message if more work is needed.',
        ]);
    }

    public function test_rate_limited_round_trips_are_retried_with_waiting_events(): void
    {
        $calls = 0;

        Http::fake(['*/chat/completions' => function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                return Http::response([
                    'error' => [
                        'type' => 'rate_limit_exceeded',
                        'message' => 'Rate limit reached. Retry after: 1s',
                    ],
                ], 429);
            }

            return Http::response(
                $this->sse(
                    '{"id":"c1","choices":[{"delta":{"role":"assistant","content":"Recovered."}}]}',
                    '{"id":"c1","choices":[{"delta":{},"finish_reason":"stop"}]}',
                ),
                200,
                ['Content-Type' => 'text/event-stream'],
            );
        }]);

        $events = $this->runTurn('Hi', mode: 'ask');

        $waiting = collect($events)->firstWhere('event', 'waiting');

        $this->assertNotNull($waiting);
        $this->assertSame('rate_limited', $waiting['data']['reason']);
        $this->assertSame(1, $waiting['data']['seconds']);
        $this->assertSame(1, $waiting['data']['attempt']);

        $this->assertSame('done', $events[count($events) - 1]['event']);
        $this->assertSame(2, $calls);

        $this->assertDatabaseHas('agent_messages', [
            'conversation_id' => $this->conversation->id,
            'role' => 'assistant',
            'content' => 'Recovered.',
        ]);
    }

    public function test_history_replay_drops_tool_calls_without_results(): void
    {
        $toolCall = fn (string $id) => [
            'id' => $id,
            'type' => 'function',
            'function' => ['name' => 'get_suite', 'arguments' => '{}'],
        ];

        AgentMessage::create(['turn_id' => 1, 'conversation_id' => $this->conversation->id, 'role' => 'user', 'content' => 'a']);
        AgentMessage::create(['turn_id' => 2, 'conversation_id' => $this->conversation->id, 'role' => 'user', 'content' => 'b']);
        AgentMessage::create(['turn_id' => 2, 'conversation_id' => $this->conversation->id, 'role' => 'assistant', 'content' => null, 'tool_calls' => [$toolCall('call_1'), $toolCall('call_2')]]);

        // call_1 ran; call_2 was interrupted before its result persisted.
        AgentMessage::create(['turn_id' => 2, 'conversation_id' => $this->conversation->id, 'role' => 'tool', 'content' => 'result 1', 'tool_call_id' => 'call_1', 'name' => 'get_suite']);

        AgentMessage::create(['turn_id' => 2, 'conversation_id' => $this->conversation->id, 'role' => 'assistant', 'content' => 'answer']);
        AgentMessage::create(['turn_id' => 2, 'conversation_id' => $this->conversation->id, 'role' => 'assistant', 'content' => null, 'tool_calls' => [$toolCall('call_3')]]);

        $history = $this->history();

        $this->assertInstanceOf(UserMessage::class, $history[0]);
        $this->assertSame('a', $history[0]->text());
        $this->assertSame('b', $history[1]->text());

        // Only the tool call that actually produced a result is replayed.
        $this->assertInstanceOf(AssistantMessage::class, $history[2]);
        $this->assertCount(1, $history[2]->toolCalls);
        $this->assertSame('call_1', $history[2]->toolCalls[0]->id);

        $this->assertInstanceOf(ToolResultMessage::class, $history[3]);
        $this->assertSame('call_1', $history[3]->toolResults[0]->toolCallId);

        // The interrupted trailing assistant (tool calls without content)
        // is dropped entirely.
        $this->assertCount(5, $history);
        $this->assertInstanceOf(AssistantMessage::class, $history[4]);
        $this->assertSame('answer', $history[4]->content);
    }

    /**
     * @param  list<PrismTool>  $tools
     * @return list<array<string, mixed>>
     */
    private function runTurn(string $message, string $mode = 'agent', ?int $maxSteps = null, array $tools = [], ?string $reasoningEffort = null): array
    {
        $adapter = $this->mock(AgentToolAdapter::class);
        $adapter->shouldReceive('prismTools')->andReturn($tools);

        $service = new AgentService($adapter);

        $userMessage = $service->startTurn($this->conversation, $message);

        return iterator_to_array($service->runTurn($this->conversation, $userMessage, 'test-model', $mode, null, $maxSteps, $reasoningEffort), false);
    }

    /**
     * @return list<Message>
     */
    private function history(): array
    {
        $service = (new ReflectionClass(AgentService::class))->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(AgentService::class, 'history');
        $method->setAccessible(true);

        return $method->invoke($service, $this->conversation);
    }

    private function sse(string ...$payloads): string
    {
        return implode("\n\n", array_map(fn (string $payload) => 'data: '.$payload, $payloads))."\n\n";
    }
}
