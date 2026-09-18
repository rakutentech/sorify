<?php

namespace App\Services\Agent;

use App\Models\AgentConversation;
use App\Models\AgentMessage;
use App\Models\AgentProfile;
use App\Models\AgentTurn;
use App\Models\AgentTurnEvent;
use Generator;
use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Responses\StreamResponse;
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
 *
 * In `ask` mode no tools are sent, so the LLM answers in a single
 * round-trip — plain chat, no side effects.
 */
class AgentService
{
    private const MAX_TOOL_RESULT_CHARS = 60000;

    /**
     * The per-turn step budget options offered in the chat box. One option
     * per row in the select; also the `in:` validation list the chat
     * endpoint accepts.
     */
    public const MAX_STEPS = [10, 25, 50, 100, 200, 500, 1000];

    public function __construct(private readonly AgentToolAdapter $tools) {}

    /**
     * Persist the turn-opening user message and return it. The turn id is
     * this message's own id; every assistant/tool row of the turn carries
     * it so pruning can drop whole turns.
     */
    public function startTurn(AgentConversation $conversation, string $message): AgentMessage
    {
        $userMessage = AgentMessage::create([
            'turn_id' => 0,
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $message,
        ]);

        $userMessage->forceFill(['turn_id' => $userMessage->id])->save();

        // Title the conversation from its first user message.
        if ($conversation->messages()->count() === 1) {
            $conversation->forceFill(['title' => mb_substr($message, 0, 100)])->save();
        }

        return $userMessage;
    }

    /**
     * The agent loop for an already-opened turn: system prompt, replayed
     * history and the user's message go to the LLM; text deltas stream,
     * tool calls execute in-process (as the conversation's owner) and the
     * loop repeats until the LLM answers, the step budget or the optional
     * wall-clock budget runs out.
     */
    public function runTurn(AgentConversation $conversation, AgentMessage $userMessage, ?string $model, string $mode = 'agent', ?int $deadlineSeconds = null, ?int $maxSteps = null): Generator
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

        $message = $userMessage->content ?? '';
        $turnId = $userMessage->id;
        $startedAt = microtime(true);

        // Track the running turn so it can be stopped on request (the
        // user's Stop button, or an admin from the running-agents page).
        // The row records the turn's mode (ask / agent) and no content.
        $turn = $this->startTrackedTurn($conversation, $turnId, $mode);

        $messages = [
            $this->systemMessage($conversation, $profile),
            ...$this->history($conversation, $turnId),
            ['role' => 'user', 'content' => $message],
        ];

        $toolDefinitions = $mode === 'ask' ? [] : $this->tools->definitions();
        $stepsBudget = $this->maxSteps($maxSteps);
        $stepsLeft = $stepsBudget;
        $step = 0;

        try {
            $client = $this->buildClient($profile);

            while (true) {
                $this->touchTrackedTurn($turn);

                // This turn was stopped on request (the user's Stop
                // button, or an admin from the running-agents page) —
                // stop cleanly at the next step boundary.
                if ($this->turnCancelRequested($turn)) {
                    $this->persistAssistant($conversation, $turnId, 'I stopped because this run was stopped on request.');

                    yield ['event' => 'done', 'data' => ['reason' => 'cancelled']];

                    $this->touch($conversation);

                    return;
                }

                if ($stepsLeft-- <= 0) {
                    $this->persistAssistant($conversation, $turnId, "I stopped after reaching the maximum number of tool-call steps ({$stepsBudget}). Please continue in a new message if more work is needed.");

                    yield ['event' => 'done', 'data' => ['reason' => 'max_steps']];

                    $this->touch($conversation);

                    return;
                }

                // The whole turn is bounded by its wall-clock budget
                // (the conversation's max run time) — stop cleanly
                // when it is gone.
                if ($deadlineSeconds !== null && microtime(true) - $startedAt > $deadlineSeconds) {
                    $this->persistAssistant($conversation, $turnId, "I stopped after reaching the maximum run time ({$this->deadlineMinutes($deadlineSeconds)} minutes). Please continue in a new message if more work is needed.");

                    yield ['event' => 'done', 'data' => ['reason' => 'max_time']];

                    $this->touch($conversation);

                    return;
                }

                // Signal the client that the LLM round-trip is starting, so
                // the UI can show a "thinking" state with the step count.
                $step++;

                yield ['event' => 'step', 'data' => [
                    'step' => $step,
                    'max_steps' => $mode === 'agent' ? $stepsBudget : null,
                ]];

                $payload = [
                    'model' => $model,
                    'messages' => $messages,
                ];

                if ($toolDefinitions !== []) {
                    $payload['tools'] = $toolDefinitions;
                }

                $stream = yield from $this->createStream($client, $payload);

                $content = '';
                $toolCalls = [];
                $cancelledMidStream = false;
                $lastCancelCheck = microtime(true);

                foreach ($stream as $chunk) {
                    // Stop requests are also honoured mid-response: a
                    // single LLM answer can stream for minutes, and the
                    // user's Stop should land within seconds instead of at
                    // the next step boundary. Polled at an interval so the
                    // database isn't queried on every chunk.
                    if (microtime(true) - $lastCancelCheck >= 1) {
                        $lastCancelCheck = microtime(true);

                        if ($this->turnCancelRequested($turn)) {
                            $cancelledMidStream = true;

                            break;
                        }
                    }

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

                if ($cancelledMidStream) {
                    // Keep whatever streamed before the stop landed, then
                    // close the turn out with the stop note.
                    if ($content !== '') {
                        $this->persistAssistant($conversation, $turnId, $content);
                    }

                    $this->persistAssistant($conversation, $turnId, 'I stopped because this run was stopped on request.');

                    yield ['event' => 'done', 'data' => ['reason' => 'cancelled']];

                    $this->touch($conversation);

                    return;
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
            // The client disconnected (e.g. browser window closed while
            // waiting out a rate limit) — nothing to report or persist.
            if (connection_aborted()) {
                return;
            }

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
            $this->finishTrackedTurn($turn);
            $this->touch($conversation);
            $this->pruneThread($conversation);
        }
    }

    /**
     * ── Turn tracking (admin "running agents" page) ─────────────────────────
     */

    /**
     * Open the agent_turns row for a turn (both modes: the user's Stop
     * button needs to reach Ask turns too). Idempotent: a turn that is
     * retried after a worker restart reuses the same turn id. Rows carry
     * no content — only who/when/mode.
     */
    private function startTrackedTurn(AgentConversation $conversation, int $turnId, string $mode): AgentTurn
    {
        // Hygiene: drop rows abandoned by dead workers more than a day
        // ago so the table doesn't accumulate ghosts.
        AgentTurn::query()
            ->whereNull('finished_at')
            ->where('started_at', '<', now()->subDay())
            ->delete();

        return AgentTurn::query()->firstOrCreate(
            ['id' => $turnId],
            [
                'conversation_id' => $conversation->id,
                'user_id' => $conversation->user_id,
                'mode' => $mode === 'ask' ? 'ask' : 'agent',
                'started_at' => now(),
            ],
        );
    }

    /**
     * Keep the row's last-activity timestamp fresh while the turn runs.
     */
    private function touchTrackedTurn(?AgentTurn $turn): void
    {
        if ($turn === null) return;

        $turn->forceFill(['last_activity_at' => now()])->save();
    }

    /**
     * Close the row when the turn ends (answer, stop or failure).
     */
    private function finishTrackedTurn(?AgentTurn $turn): void
    {
        if ($turn === null) return;

        $turn->forceFill(['finished_at' => now()])->save();
    }

    /**
     * Whether this turn was asked to stop (the user's Stop button, or an
     * admin from the running-agents page). Re-read from the database each
     * step (cheap, bounded by the step budget) so a stop request lands
     * within one LLM round-trip.
     */
    private function turnCancelRequested(?AgentTurn $turn): bool
    {
        if ($turn === null) return false;

        if ($turn->cancel_requested_at !== null) {
            return true;
        }

        return AgentTurn::query()
            ->whereKey($turn->id)
            ->whereNotNull('cancel_requested_at')
            ->exists();
    }

    private function buildClient(AgentProfile $profile)
    {
        $factory = OpenAI::factory()
            ->withBaseUri(AgentProfile::normalizeBaseUrl($profile->base_url))
            ->withApiKey($profile->api_token);

        // Always bound the HTTP call — without this, a stalled endpoint
        // would hang the SSE chat stream forever (connect cap + a total
        // request cap drawn from config). The proxy is additive.
        $options = [
            'connect_timeout' => 10,
            'timeout' => (int) config('sorify.agent.request_timeout', 300),
        ];

        if ($profile->proxy_url) {
            $options['proxy'] = $profile->proxy_url;
        }

        $factory->withHttpClient(new GuzzleClient($options));

        return $factory->make();
    }

    /**
     * Open the LLM stream, retrying when the provider rate-limits the
     * request (HTTP 429 or a "rate limit" error — some gateways return
     * 200-status errors in the stream body, so match the message too).
     *
     * While waiting out the provider's advertised retry delay, `waiting`
     * events are yielded so the UI can show why the turn is paused
     * instead of the turn dying with "Agent request failed".
     *
     * @param  \OpenAI\Client  $client
     * @param  array<string, mixed>  $payload
     * @return StreamResponse
     */
    private function createStream($client, array $payload): Generator
    {
        $maxRetries = (int) config('sorify.agent.rate_limit_retries', 3);

        for ($retry = 1; ; $retry++) {
            try {
                return $client->chat()->createStreamed($payload);
            } catch (Throwable $exception) {
                if (! $this->isRateLimited($exception) || $retry > $maxRetries) {
                    throw $exception;
                }

                $seconds = $this->retryDelaySeconds($exception, $retry);

                yield ['event' => 'waiting', 'data' => [
                    'reason' => 'rate_limited',
                    'seconds' => $seconds,
                    'attempt' => $retry,
                    'max_attempts' => $maxRetries,
                ]];

                $this->waitOutRateLimit($seconds, $exception);
            }
        }
    }

    /**
     * A 429 from the API, or a rate-limit message relayed by the provider.
     * Gateways behind OpenAI-compatible endpoints sometimes report the
     * limit in the body with a 200 status, so the message is scanned too.
     */
    private function isRateLimited(Throwable $exception): bool
    {
        if ($exception instanceof ErrorException && $exception->getStatusCode() === 429) {
            return true;
        }

        return (bool) preg_match('/rate[\s_-]?limit/i', $exception->getMessage());
    }

    /**
     * Honor the provider's advertised delay when present (e.g.
     * "Retry after: 17s"); fall back to exponential backoff. Always
     * bounded by the configured max wait.
     */
    private function retryDelaySeconds(Throwable $exception, int $retry): int
    {
        $max = (int) config('sorify.agent.rate_limit_max_wait', 60);

        if (preg_match('/retry[ -]after:?\s*(\d+)\s*(s|sec|seconds?)?/i', $exception->getMessage(), $match) === 1) {
            return min((int) $match[1], $max);
        }

        return min(5 * (2 ** ($retry - 1)), $max);
    }

    /**
     * Sleep out the retry delay, one second at a time, bailing out as
     * soon as the client disconnects so a closed browser window doesn't
     * pin a worker for the full delay.
     */
    private function waitOutRateLimit(int $seconds, Throwable $exception): void
    {
        for ($elapsed = 0; $elapsed < $seconds; $elapsed++) {
            if (connection_aborted()) {
                throw $exception;
            }

            sleep(1);
        }
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
     * Replayed history for the LLM: the most recent messages before the
     * current turn, trimmed so the set never starts with an orphaned
     * tool message. (The current turn's user message is appended
     * explicitly by the caller, so it is excluded here.)
     *
     * @return list<array<string, mixed>>
     */
    private function history(AgentConversation $conversation, ?int $beforeTurnId = null): array
    {
        $limit = (int) config('sorify.agent.history_replay', 50);

        $messages = AgentMessage::query()
            ->where('conversation_id', $conversation->id)
            ->when($beforeTurnId !== null, fn ($query) => $query->where('turn_id', '<', $beforeTurnId))
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

        AgentTurnEvent::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('turn_id', $staleTurnIds)
            ->delete();

        AgentMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('turn_id', $staleTurnIds)
            ->delete();
    }

    /**
     * The turn's step budget: the per-turn value picked in the chat box, or
     * the sorify.agent.max_steps config when none was sent.
     */
    private function maxSteps(?int $maxSteps): int
    {
        return (int) ($maxSteps ?: config('sorify.agent.max_steps', 100));
    }

    private function deadlineMinutes(int $deadlineSeconds): int
    {
        return max(1, (int) round($deadlineSeconds / 60));
    }
}
