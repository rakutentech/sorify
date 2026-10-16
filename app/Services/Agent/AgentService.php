<?php

namespace App\Services\Agent;

use App\Models\AgentConversation;
use App\Models\AgentMessage;
use App\Models\AgentProfile;
use App\Models\AgentTurn;
use App\Models\AgentTurnEvent;
use App\Models\Setting;
use App\Models\Skill;
use Generator;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Tool as PrismTool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Throwable;

/**
 * The AI agent: a Prism-powered tool-calling loop over the user's
 * OpenAI-compatible profile.
 *
 * Each turn sends the system prompt (built from the conversation's page
 * context plus the profile's extra instructions), the persisted thread
 * history, and the user's message to the LLM through Prism's streaming
 * API. Prism owns the round-trip loop; its stream events are mapped here
 * onto the chat's SSE protocol (`delta`, `step`, `tool_start`,
 * `tool_result`, `waiting`, `done`) while the transcript is persisted
 * step by step. Tools execute in-process through the AgentToolAdapter
 * (which re-runs authorization gates as the chatting user) and the loop
 * repeats until the LLM produces a plain-text answer or the step budget
 * is exhausted.
 *
 * In `ask` mode no tools are sent, so the LLM answers in a single
 * round-trip — plain chat, no side effects.
 */
class AgentService
{
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
    public function runTurn(AgentConversation $conversation, AgentMessage $userMessage, ?string $model, string $mode = 'agent', ?int $deadlineSeconds = null, ?int $maxSteps = null, ?string $reasoningEffort = null): Generator
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

        // Messages accumulate as steps complete so a rate-limited
        // round-trip can be retried without replaying what already
        // streamed.
        $messages = [
            ...$this->history($conversation, $turnId),
            new UserMessage($message),
        ];

        $prismTools = $mode === 'ask' ? [] : $this->tools->prismTools($model);
        $stepsBudget = $this->maxSteps($maxSteps);
        $systemPrompt = $this->systemPrompt($conversation, $profile);

        $step = 0;
        $lastAssistantId = null;
        $lastStepHadToolCalls = false;
        $finished = false;
        $rateLimitRetry = 1;
        $maxRateLimitRetries = (int) config('sorify.agent.rate_limit_retries', 3);
        // Bounds reasoning-effort recoveries (drop, or pick up 'none') so a
        // strict endpoint can't bounce the turn between the two forever.
        $reasoningEffortRetries = 0;

        try {
            // This turn was stopped on request before it started — stop
            // cleanly without a round-trip.
            if ($this->turnCancelRequested($turn)) {
                $this->persistAssistant($conversation, $turnId, 'I stopped because this run was stopped on request.');

                yield ['event' => 'done', 'data' => ['reason' => 'cancelled']];

                $this->touch($conversation);

                return;
            }

            while (! $finished) {
                // A rate-limited round-trip resumes with the steps it
                // already consumed taken off the budget.
                $remainingSteps = max(1, $stepsBudget - $step);

                $stream = $this->openStream($profile, $model, $systemPrompt, $messages, $prismTools, $remainingSteps, $reasoningEffort);

                try {
                    // ── Per-step accumulators (reset at each step) ──────
                    $stepContent = '';
                    $stepToolCalls = [];
                    $stepToolResults = [];
                    $stepToolRows = [];
                    $interrupted = null; // 'cancelled' | 'max_time'
                    $lastCancelCheck = microtime(true);

                    foreach ($stream as $event) {
                        // Stop requests and the wall-clock budget are also
                        // honoured mid-response: a single LLM answer can
                        // stream for minutes and the user's Stop should
                        // land within seconds. Polled at an interval so
                        // the database isn't queried on every event.
                        if (microtime(true) - $lastCancelCheck >= 1) {
                            $lastCancelCheck = microtime(true);

                            if ($this->turnCancelRequested($turn)) {
                                $interrupted = 'cancelled';

                                break;
                            }

                            if ($deadlineSeconds !== null && microtime(true) - $startedAt > $deadlineSeconds) {
                                $interrupted = 'max_time';

                                break;
                            }
                        }

                        if ($event instanceof StepStartEvent) {
                            $step++;

                            $this->touchTrackedTurn($turn);

                            // Signal the client that the LLM round-trip is
                            // starting, so the UI can show a "thinking"
                            // state with the step count.
                            yield ['event' => 'step', 'data' => [
                                'step' => $step,
                                'max_steps' => $mode === 'agent' ? $stepsBudget : null,
                            ]];

                            continue;
                        }

                        if ($event instanceof TextDeltaEvent) {
                            $stepContent .= $event->delta;

                            yield ['event' => 'delta', 'data' => ['text' => $event->delta]];

                            continue;
                        }

                        if ($event instanceof ToolCallEvent) {
                            $stepToolCalls[] = $event->toolCall;

                            yield ['event' => 'tool_start', 'data' => [
                                'id' => $event->toolCall->id,
                                'name' => $event->toolCall->name,
                                'arguments' => $this->decodeArguments($event->toolCall),
                            ]];

                            continue;
                        }

                        if ($event instanceof ToolResultEvent) {
                            yield ['event' => 'tool_result', 'data' => [
                                'id' => $event->toolResult->toolCallId,
                                'name' => $event->toolResult->toolName,
                                'is_error' => ! $event->success,
                                'result' => mb_substr((string) $event->toolResult->result, 0, 2000),
                            ]];

                            $stepToolResults[] = $event->toolResult;

                            $stepToolRows[] = [
                                'turn_id' => $turnId,
                                'conversation_id' => $conversation->id,
                                'role' => 'tool',
                                'content' => mb_substr((string) $event->toolResult->result, 0, AgentToolAdapter::MAX_TOOL_RESULT_CHARS),
                                'tool_call_id' => $event->toolResult->toolCallId,
                                'name' => $event->toolResult->toolName,
                            ];

                            continue;
                        }

                        if ($event instanceof StepFinishEvent) {
                            // Persist the step the way the transcript
                            // expects it: the assistant row (text and/or
                            // tool calls), then its tool rows.
                            $assistant = $this->persistStep($conversation, $turnId, $stepContent, $stepToolCalls, $stepToolRows);

                            if ($assistant !== null) {
                                $lastAssistantId = $assistant->id;
                            }

                            // A step that produced nothing (an empty
                            // round from a degenerate provider response)
                            // leaves the previous step's verdict intact.
                            if ($stepContent !== '' || $stepToolCalls !== []) {
                                $lastStepHadToolCalls = $stepToolCalls !== [];
                            }

                            // Mirror the completed step onto the message
                            // list so a retry after a rate-limited
                            // round-trip resumes from the same point.
                            if ($stepContent !== '' || $stepToolCalls !== []) {
                                $messages[] = new AssistantMessage($stepContent, $stepToolCalls);
                            }

                            if ($stepToolResults !== []) {
                                $messages[] = new ToolResultMessage($stepToolResults);
                            }

                            $stepContent = '';
                            $stepToolCalls = [];
                            $stepToolResults = [];
                            $stepToolRows = [];

                            continue;
                        }

                        if ($event instanceof StreamEndEvent) {
                            $finished = true;

                            if ($lastStepHadToolCalls) {
                                // The step budget ran out with the model
                                // still asking for tools — the provider's
                                // stream ends right after the step
                                // instead of continuing the loop.
                                $assistant = $this->persistAssistant($conversation, $turnId, "I stopped after reaching the maximum number of tool-call steps ({$stepsBudget}). Please continue in a new message if more work is needed.");

                                $lastAssistantId = $assistant?->id;

                                yield ['event' => 'done', 'data' => ['reason' => 'max_steps']];
                            } else {
                                yield ['event' => 'done', 'data' => ['message_id' => $lastAssistantId]];
                            }

                            break;
                        }
                    }

                    if ($interrupted !== null) {
                        // Keep whatever streamed before the stop landed, then
                        // close the turn out with the stop note.
                        $this->persistStep($conversation, $turnId, $stepContent, $stepToolCalls, $stepToolRows);

                        if ($interrupted === 'cancelled') {
                            $this->persistAssistant($conversation, $turnId, 'I stopped because this run was stopped on request.');

                            yield ['event' => 'done', 'data' => ['reason' => 'cancelled']];
                        } else {
                            $this->persistAssistant($conversation, $turnId, "I stopped after reaching the maximum run time ({$this->deadlineMinutes($deadlineSeconds)} minutes). Please continue in a new message if more work is needed.");

                            yield ['event' => 'done', 'data' => ['reason' => 'max_time']];
                        }

                        $finished = true;
                    }
                } catch (Throwable $exception) {
                    // Endpoints validate `reasoning_effort` against their own
                    // model-specific set (e.g. a hosted DeepSeek model allows
                    // low/high/max — no "medium"), rejecting values the
                    // chat's picker legally offers. Drop the option and try
                    // the round-trip once more without it instead of failing
                    // the whole turn.
                    //
                    // The inverse also exists: gateways that apply a model's
                    // default reasoning when the option is ABSENT reject
                    // that alongside function tools ("Function tools with
                    // reasoning_effort are not supported ... set
                    // reasoning_effort to 'none'"). When they say so, comply
                    // once, explicitly.
                    if ($reasoningEffortRetries < 2 && $this->rejectsReasoningEffort($exception)) {
                        if ($reasoningEffort !== null) {
                            $reasoningEffort = null;
                            $reasoningEffortRetries++;

                            continue;
                        }

                        if ($prismTools !== [] && $this->suggestsNoReasoningEffort($exception)) {
                            $reasoningEffort = 'none';
                            $reasoningEffortRetries++;

                            continue;
                        }
                    }

                    if (! $this->isRateLimited($exception) || $rateLimitRetry > $maxRateLimitRetries) {
                        throw $exception;
                    }

                    // While waiting out the provider's advertised retry
                    // delay, `waiting` events are yielded so the UI can
                    // show why the turn is paused instead of the turn
                    // dying with "Agent request failed". The reopen below
                    // resumes from the messages accumulated so far, so
                    // nothing that already streamed is repeated.
                    $seconds = $this->retryDelaySeconds($exception, $rateLimitRetry);

                    yield ['event' => 'waiting', 'data' => [
                        'reason' => 'rate_limited',
                        'seconds' => $seconds,
                        'attempt' => $rateLimitRetry,
                        'max_attempts' => $maxRateLimitRetries,
                    ]];

                    $this->waitOutRateLimit($seconds, $exception);

                    $rateLimitRetry++;
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
        if ($turn === null) {
            return;
        }

        $turn->forceFill(['last_activity_at' => now()])->save();
    }

    /**
     * Close the row when the turn ends (answer, stop or failure).
     */
    private function finishTrackedTurn(?AgentTurn $turn): void
    {
        if ($turn === null) {
            return;
        }

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
        if ($turn === null) {
            return false;
        }

        if ($turn->cancel_requested_at !== null) {
            return true;
        }

        return AgentTurn::query()
            ->whereKey($turn->id)
            ->whereNotNull('cancel_requested_at')
            ->exists();
    }

    /**
     * Open the Prism stream for the remaining conversation. Prism runs
     * the tool-calling loop itself; its events are consumed by runTurn.
     *
     * @param  list<Message>  $messages
     * @param  list<PrismTool>  $tools
     * @param  string|null  $reasoningEffort  low|medium|high; null = the
     *                                        model's own default (nothing
     *                                        is sent)
     * @return Generator<StreamEvent>
     */
    private function openStream(AgentProfile $profile, string $model, string $systemPrompt, array $messages, array $tools, int $stepsBudget, ?string $reasoningEffort = null): Generator
    {
        // Always bound the HTTP call — without this, a stalled endpoint
        // would hang the SSE chat stream forever (connect cap + a total
        // request cap drawn from config). The proxy is additive.
        $clientOptions = [
            'connect_timeout' => 10,
            'timeout' => (int) config('sorify.agent.request_timeout', 300),
        ];

        if ($profile->proxy_url) {
            $clientOptions['proxy'] = $profile->proxy_url;
        }

        // Custom headers configured on the profile (e.g. a User-Agent the
        // endpoint requires). 'headers' is a mergeable client option, so
        // these merge with the Authorization header Prism sets from the
        // token — they cannot replace it (Authorization is also stripped
        // when the profile is saved).
        if (! empty($profile->extra_headers)) {
            $clientOptions['headers'] = array_merge(
                (array) ($clientOptions['headers'] ?? []),
                $profile->extra_headers,
            );
        }

        $request = Prism::text()
            ->using('openai-compatible', $model, [
                'url' => AgentProfile::normalizeBaseUrl($profile->base_url),
                'api_key' => $profile->api_token,
            ])
            ->withClientOptions($clientOptions)
            ->withSystemPrompt($systemPrompt)
            ->withMessages($messages)
            ->withMaxSteps($stepsBudget);

        if ($tools !== []) {
            $request = $request->withTools($tools);
        }

        // Forwarded by the custom provider handler as `reasoning_effort`
        // in the chat-completions body. Only set when the user picked an
        // explicit level — the "model default" option must send nothing.
        if ($reasoningEffort !== null) {
            $request = $request->withProviderOptions(['reasoning_effort' => $reasoningEffort]);
        }

        return $request->asStream();
    }

    /**
     * A 429 from the API, or a rate-limit message relayed by the provider.
     * Prism rethrows provider errors as PrismException carrying the HTTP
     * status as the code (PrismRateLimitedException where the provider
     * maps it); gateways behind OpenAI-compatible endpoints sometimes
     * report the limit in the body with a 200 status, so the message is
     * scanned too.
     */
    private function isRateLimited(Throwable $exception): bool
    {
        if ($exception instanceof PrismRateLimitedException) {
            return true;
        }

        if ((int) $exception->getCode() === 429) {
            return true;
        }

        return (bool) preg_match('/rate[\s_-]?limit/i', $exception->getMessage());
    }

    /**
     * A 400 naming `reasoning_effort`: the endpoint understood the parameter
     * but rejected the value against its own allowed set (e.g. hosted vLLM
     * models that take low/high/max only). Retrying without the option is
     * the correct recovery — the model's own default always applies.
     */
    private function rejectsReasoningEffort(Throwable $exception): bool
    {
        if (! preg_match('/reasoning[\s_-]?effort/i', $exception->getMessage())) {
            return false;
        }

        $message = $exception->getMessage();

        return (int) $exception->getCode() === 400
            || str_contains($message, '[400]')
            || str_contains($message, 'invalid_request');
    }

    /**
     * The endpoint rejected its own default reasoning alongside function
     * tools and asks for an explicit opt-out — "set reasoning_effort to
     * 'none'". Only an explicit suggestion matches: a bare mention of
     * "none" in an unrelated error must not flip reasoning off.
     */
    private function suggestsNoReasoningEffort(Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return (bool) preg_match('/reasoning[\s_-]?effort[\'"\s:=]+to[\'"\s]+none|effort[\'"\s:=]+[\'"]none[\'"]|[\'"]none[\'"]/', $message);
    }

    /**
     * Honor the provider's advertised delay when present (e.g.
     * "Retry after: 17s"); fall back to exponential backoff. Always
     * bounded by the configured max wait.
     */
    private function retryDelaySeconds(Throwable $exception, int $retry): int
    {
        $max = (int) config('sorify.agent.rate_limit_max_wait', 60);

        if ($exception instanceof PrismRateLimitedException && $exception->retryAfter !== null) {
            return min($exception->retryAfter, $max);
        }

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
     * Tool call arguments as an array — tolerant of malformed JSON, which
     * some gateways emit in stream fragments and which should degrade to
     * "no arguments" instead of killing the event stream.
     *
     * @return array<string, mixed>
     */
    private function decodeArguments(ToolCall $toolCall): array
    {
        try {
            return $toolCall->arguments();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * A Prism tool call in the persisted `tool_calls` JSON shape (the
     * same shape existing transcripts already carry).
     *
     * @return array<string, mixed>
     */
    private function persistedToolCall(ToolCall $toolCall): array
    {
        return [
            'id' => $toolCall->id,
            'type' => 'function',
            'function' => [
                'name' => $toolCall->name,
                'arguments' => json_encode($this->decodeArguments($toolCall), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            ],
        ];
    }

    /**
     * Persist one completed step: the assistant row (text and/or tool
     * calls) followed by its tool rows. A step with nothing to say is
     * skipped entirely.
     */
    private function persistStep(AgentConversation $conversation, int $turnId, string $content, array $toolCalls, array $toolRows): ?AgentMessage
    {
        $assistant = $this->persistAssistant(
            $conversation,
            $turnId,
            $content !== '' ? $content : null,
            $toolCalls === [] ? null : array_map($this->persistedToolCall(...), $toolCalls),
        );

        foreach ($toolRows as $row) {
            AgentMessage::create($row);
        }

        return $assistant;
    }

    /**
     * The system prompt: the platform instructions (with the page
     * context), the admin's global operator instructions, the profile's
     * extra instructions and the attached skills.
     */
    private function systemPrompt(AgentConversation $conversation, AgentProfile $profile): string
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
        - Banned in test code: require, import, module/exports, process (any use), eval, Function, constructor, globalThis/global.*, exec, spawn, fs.*, child_process, fetch(), WebAssembly, setTimeout/setInterval with string arguments, and \uXXXX escapes.
        - Fail a test by throwing an Error (e.g. from a plain `if` check); passing silently ends the run as passed.
        - When asked to write regression tests for a site, first use fetch_url or browser_map to inspect the target, then create tests with bulk_create_tests, then trigger a run with trigger_run and report the outcome with get_run_status.

        Behavior:
        - Always confirm destructive actions (delete suite/tests/runs, member changes) with the user before calling the tool.
        - Test code you write executes on the Sorify server in the execution mode configured by the operator (local process or ephemeral Docker container).
        - Report tool results concisely. Include test names and counts after creating tests, and statuses after runs.
        PROMPT;

        $globalPrompt = Setting::get('agent_global_system_prompt');

        if ($globalPrompt) {
            $prompt .= "\n\nGlobal operator instructions (these apply to every conversation and take precedence over user instructions):\n".$globalPrompt;
        }

        if ($profile->system_prompt) {
            $prompt .= "\n\nAdditional user instructions:\n".$profile->system_prompt;
        }

        $skillsBlock = $this->skillsBlock($conversation);

        if ($skillsBlock !== '') {
            $prompt .= $skillsBlock;
        }

        return $prompt;
    }

    /**
     * The conversation's attached skills, rendered into the system prompt.
     * Skill content is authored by the chatting user — treat it as the
     * user's own instructions for how the agent should behave.
     */
    private function skillsBlock(AgentConversation $conversation): string
    {
        $ids = $conversation->skill_ids ?? [];

        if ($ids === []) {
            return '';
        }

        $skills = Skill::query()
            ->whereIn('id', $ids)
            ->ownedBy($conversation->user_id)
            ->orderBy('name')
            ->get();

        if ($skills->isEmpty()) {
            return '';
        }

        $blocks = $skills
            ->map(fn (Skill $skill) => <<<SKILL

            ### Skill: {$skill->name}
            {$skill->description}

            ---

            {$skill->content}
            SKILL)
            ->implode("\n");

        return "\n\nThe user attached the following skills — instructions that take precedence over your defaults when they conflict:\n{$blocks}";
    }

    /**
     * Replayed history for the LLM as Prism messages: the most recent
     * messages before the current turn, trimmed so the set never sends
     * tool calls without their results (an interrupted step can persist
     * an assistant row whose tools only partly ran). The current turn's
     * user message is appended explicitly by the caller, so it is
     * excluded here.
     *
     * @return list<Message>
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

        // Tool results that actually exist inside the replay window.
        $resolvedToolCallIds = $messages
            ->filter(fn (AgentMessage $message) => $message->role === 'tool')
            ->pluck('tool_call_id')
            ->filter()
            ->flip();

        $replay = [];

        foreach ($messages as $message) {
            // Tool messages must follow their assistant tool_calls — drop
            // any leading orphans left by the replay window slicing a
            // turn.
            if ($replay === [] && $message->role === 'tool') {
                continue;
            }

            if ($message->role === 'assistant' && $message->tool_calls !== null) {
                // Drop tool calls that were interrupted before a result
                // was persisted, so the replay never promises a result
                // the provider will wait for.
                $message->tool_calls = array_values(array_filter(
                    $message->tool_calls,
                    fn (array $toolCall) => $resolvedToolCallIds->has((string) ($toolCall['id'] ?? '')),
                ));

                if ($message->tool_calls === [] && ($message->content === null || $message->content === '')) {
                    continue;
                }
            }

            $replay[] = $message->toPrismMessage();
        }

        // Drop trailing tool messages when the previous turn was
        // interrupted — and any tool calls left dangling on the last
        // assistant message by the same cut.
        while ($replay !== []) {
            $last = $replay[count($replay) - 1];

            if ($last instanceof ToolResultMessage) {
                array_pop($replay);

                continue;
            }

            if ($last instanceof AssistantMessage && $last->toolCalls !== []) {
                if ($last->content === '') {
                    array_pop($replay);

                    continue;
                }

                $replay[count($replay) - 1] = new AssistantMessage($last->content);

                continue;
            }

            break;
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
