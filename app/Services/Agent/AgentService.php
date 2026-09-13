<?php

namespace App\Services\Agent;

use App\Models\AgentConversation;
use App\Models\AgentMessage;
use App\Models\AgentProfile;
use Generator;
use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use Throwable;

/**
 * The AI agent: a streaming tool-calling loop over the user's
 * OpenAI-compatible profile.
 *
 * Each turn sends the system prompt (built from the conversation's page
 * context plus the profile's extra instructions), the persisted thread
 * history, and the user's message to the LLM. Text deltas are yielded as
 * `delta` events while they stream in; tool calls are executed in-process
 * through the AgentToolAdapter (which re-runs authorization gates as the
 * chatting user) and yielded as `tool_start` / `tool_result` events. The
 * loop repeats until the LLM produces a plain-text answer or the step budget
 * is exhausted.
 */
class AgentService
{
    private const MAX_TOOL_RESULT_CHARS = 60000;

    public function __construct(private readonly AgentToolAdapter $tools) {}

    /**
     * Run one agent turn, yielding SSE events.
     */
    public function chat(AgentConversation $conversation, string $message, ?string $model): Generator
    {
        $profile = $conversation->profile;

        if ($profile === null) {
            yield ['event' => 'error', 'data' => ['message' => 'This conversation has no agent profile (it may have been deleted). Start a new chat with a configured profile.']];

            return;
        }

        $model = $model ?: $profile->default_model;

        if ($model === null || $model === '') {
            yield ['event' => 'error', 'data' => ['message' => 'No model selected. Pick a model in the chat header first.']];

            return;
        }

        $userMessage = AgentMessage::create([
            'turn_id' => 0,
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $message,
        ]);

        // The turn id is the opening user message's own id; every assistant /
        // tool row of this turn carries it so pruning can drop whole turns.
        $turnId = $userMessage->id;
        $userMessage->forceFill(['turn_id' => $turnId])->save();

        // Title the conversation from its first user message.
        if ($conversation->messages()->count() === 1) {
            $conversation->forceFill(['title' => mb_substr($message, 0, 100)])->save();
        }

        $messages = [
            $this->systemMessage($conversation, $profile),
            ...$this->history($conversation),
            ['role' => 'user', 'content' => $message],
        ];

        $toolDefinitions = $this->tools->definitions();
        $stepsLeft = (int) config('sorify.agent.max_steps', 25);

        try {
            $client = $this->buildClient($profile);

            while (true) {
                if ($stepsLeft-- <= 0) {
                    $this->persistAssistant($conversation, $turnId, "I stopped after reaching the maximum number of tool-call steps ({$this->maxSteps()}). Please continue in a new message if more work is needed.");

                    yield ['event' => 'done', 'data' => ['reason' => 'max_steps']];

                    $this->touch($conversation);

                    return;
                }

                $stream = $client->chat()->createStreamed([
                    'model' => $model,
                    'messages' => $messages,
                    'tools' => $toolDefinitions,
                ]);

                $content = '';
                $toolCalls = [];

                foreach ($stream as $chunk) {
                    foreach ($chunk->choices as $choice) {
                        $delta = $choice->delta;

                        if (($delta->content ?? null) !== null && $delta->content !== '') {
                            $content .= $delta->content;

                            yield ['event' => 'delta', 'data' => ['text' => $delta->content]];
                        }

                        foreach ($delta->toolCalls ?? [] as $toolCall) {
                            $index = $toolCall->index ?? count($toolCalls);

                            $toolCalls[$index] ??= [
                                'id' => '',
                                'type' => 'function',
                                'function' => ['name' => '', 'arguments' => ''],
                            ];

                            if ($toolCall->id !== null && $toolCall->id !== '') {
                                $toolCalls[$index]['id'] = $toolCall->id;
                            }

                            if ($toolCall->function->name !== null && $toolCall->function->name !== '') {
                                $toolCalls[$index]['function']['name'] .= $toolCall->function->name;
                            }

                            $toolCalls[$index]['function']['arguments'] .= $toolCall->function->arguments;
                        }
                    }
                }

                $toolCalls = array_values(array_filter(
                    $toolCalls,
                    fn (array $toolCall) => $toolCall['function']['name'] !== ''
                ));

                if ($toolCalls === []) {
                    $assistant = $this->persistAssistant($conversation, $turnId, $content);

                    yield ['event' => 'done', 'data' => ['message_id' => $assistant?->id]];

                    $this->touch($conversation);

                    return;
                }

                $this->persistAssistant($conversation, $turnId, $content !== '' ? $content : null, $toolCalls);

                $messages[] = [
                    'role' => 'assistant',
                    'content' => $content !== '' ? $content : null,
                    'tool_calls' => $toolCalls,
                ];

                foreach ($toolCalls as $toolCall) {
                    $name = $toolCall['function']['name'];

                    $arguments = json_decode($toolCall['function']['arguments'], true);
                    if (! is_array($arguments)) {
                        $arguments = [];
                    }

                    yield ['event' => 'tool_start', 'data' => [
                        'id' => $toolCall['id'],
                        'name' => $name,
                        'arguments' => $arguments,
                    ]];

                    // The agent's model is the ground-truth attribution for
                    // any test code this tool call writes.
                    $result = $this->tools->execute($name, $arguments, [
                        'ai_model' => $model,
                        'via' => 'agent',
                    ]);

                    yield ['event' => 'tool_result', 'data' => [
                        'id' => $toolCall['id'],
                        'name' => $name,
                        'is_error' => $result['is_error'],
                        'result' => mb_substr($result['result'], 0, 2000),
                    ]];

                    $toolContent = mb_substr($result['result'], 0, self::MAX_TOOL_RESULT_CHARS);

                    AgentMessage::create([
                        'turn_id' => $turnId,
                        'conversation_id' => $conversation->id,
                        'role' => 'tool',
                        'content' => $toolContent,
                        'tool_call_id' => $toolCall['id'],
                        'name' => $name,
                    ]);

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'name' => $name,
                        'content' => $toolContent,
                    ];
                }
            }
        } catch (Throwable $exception) {
            report($exception);

            // Persist whatever streamed before the failure so the transcript
            // reflects what the user actually saw.
            AgentMessage::create([
                'turn_id' => $turnId,
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => '(agent request failed: '.mb_substr($exception->getMessage(), 0, 500).')',
            ]);

            yield ['event' => 'error', 'data' => ['message' => 'Agent request failed: '.$exception->getMessage()]];
        } finally {
            $this->touch($conversation);
            $this->pruneThread($conversation);
        }
    }

    private function buildClient(AgentProfile $profile)
    {
        $factory = OpenAI::factory()
            ->withBaseUri(AgentProfile::normalizeBaseUrl($profile->base_url))
            ->withApiKey($profile->api_token);

        if ($profile->proxy_url) {
            $factory->withHttpClient(new GuzzleClient([
                'proxy' => $profile->proxy_url,
                'connect_timeout' => 10,
                'timeout' => (int) config('sorify.agent.request_timeout', 300),
            ]));
        }

        return $factory->make();
    }

    /**
     * @return array<string, mixed>
     */
    private function systemMessage(AgentConversation $conversation, AgentProfile $profile): array
    {
        $prompt = <<<PROMPT
        You are Sorify, an AI QA agent embedded in the Sorify test automation platform.
        You manage Playwright regression tests: browsing suites, writing test code, triggering runs, and reporting results.

        The user is chatting from the page "{$conversation->page_name}" ({$conversation->page_url}).
        Page context provided by the user:
        ---
        {$conversation->context}
        ---

        Working with suites:
        - Tools take an explicit suite_id. When the page context names one, use it. Otherwise discover suites with list_suites or get_suite.

        How to write tests:
        - Test code is a bare Playwright script — NOT a @playwright/test spec. No imports, no require, no test()/describe()/it() wrappers.
        - The script runs top-level with `page`, `context`, `browser`, `baseUrl`, and `variables` already in scope.
        - Banned in test code: require, import, eval, exec, spawn, fs.*, process.env, child_process, globalThis, new Function.
        - Fail a test by throwing an Error (e.g. from a plain `if` check); passing silently ends the run as passed.
        - When asked to write regression tests for a site, first use fetch_url or browser_map to inspect the target, then create tests with bulk_create_tests, then trigger a run with trigger_run and report the outcome with get_run_status.

        Behavior:
        - Always confirm destructive actions (delete suite/tests/runs, member changes) with the user before calling the tool.
        - Report tool results concisely. Include test names and counts after creating tests, and statuses after runs.
        PROMPT;

        if ($profile->system_prompt) {
            $prompt .= "\n\nAdditional user instructions:\n".$profile->system_prompt;
        }

        return ['role' => 'system', 'content' => $prompt];
    }

    /**
     * Replayed history for the LLM: the most recent messages of the thread,
     * trimmed so the set never starts with an orphaned tool message.
     *
     * @return list<array<string, mixed>>
     */
    private function history(AgentConversation $conversation): array
    {
        $limit = (int) config('sorify.agent.history_replay', 50);

        $messages = AgentMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        $replay = [];

        foreach ($messages as $message) {
            // Tool messages must follow their assistant tool_calls — drop any
            // leading orphans left by the replay window slicing a turn.
            if ($replay === [] && $message->role === 'tool') {
                continue;
            }

            $replay[] = $message->toOpenAiMessage();
        }

        // Drop trailing tool messages when the previous turn was interrupted.
        while ($replay !== [] && end($replay)['role'] === 'tool') {
            array_pop($replay);
        }

        return $replay;
    }

    private function persistAssistant(AgentConversation $conversation, int $turnId, ?string $content, ?array $toolCalls = null): ?AgentMessage
    {
        if (($content === null || $content === '') && $toolCalls === null) {
            return null;
        }

        return AgentMessage::create([
            'turn_id' => $turnId,
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $content,
            'tool_calls' => $toolCalls,
        ]);
    }

    /**
     * Bump the conversation's updated_at so the list stays ordered by
     * last-activity.
     */
    private function touch(AgentConversation $conversation): void
    {
        $conversation->forceFill(['updated_at' => now()])->saveQuietly();
    }

    private function pruneThread(AgentConversation $conversation): void
    {
        $keepTurns = (int) config('sorify.agent.keep_turns', 100);

        $turnIds = AgentMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->orderByDesc('turn_id')
            ->pluck('turn_id')
            ->unique()
            ->values();

        if ($turnIds->count() <= $keepTurns) {
            return;
        }

        $staleTurnIds = $turnIds->slice($keepTurns)->values()->all();

        AgentMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('turn_id', $staleTurnIds)
            ->delete();
    }

    private function maxSteps(): int
    {
        return (int) config('sorify.agent.max_steps', 25);
    }
}
