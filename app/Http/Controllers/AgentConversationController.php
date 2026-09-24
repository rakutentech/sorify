<?php

namespace App\Http\Controllers;

use App\Jobs\RunAgentTurnJob;
use App\Models\AgentConversation;
use App\Models\AgentMessage;
use App\Models\AgentProfile;
use App\Models\AgentTurn;
use App\Models\AgentTurnEvent;
use App\Services\Agent\AgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentConversationController extends Controller
{
    /**
     * Max run times offered for Agent mode, in minutes.
     */
    private const AGENT_MAX_RUN_MINUTES = [5, 10, 15, 30, 60];

    public function __construct(private readonly AgentService $agent) {}

    /**
     * The user's conversations, most recently active first.
     */
    public function index(Request $request): JsonResponse
    {
        $conversations = AgentConversation::query()
            ->where('user_id', $request->user()->id)
            ->with('profile:id,name')
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();

        return response()->json([
            'conversations' => $conversations->map(fn (AgentConversation $conversation) => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'page_name' => $conversation->page_name,
                'page_url' => $conversation->page_url,
                'profile_name' => $conversation->profile?->name,
                'updated_at' => $conversation->updated_at,
            ]),
        ]);
    }

    /**
     * Start a conversation from a page, with a user-editable context block.
     */
    public function store(Request $request): JsonResponse
    {
        $this->rejectWhenDisabled($request);

        $validated = $request->validate([
            'page_url' => ['nullable', 'string', 'max:500'],
            'page_name' => ['nullable', 'string', 'max:100'],
            'context' => ['nullable', 'string', 'max:8000'],
            'profile_id' => ['nullable', 'integer', 'exists:agent_profiles,id'],
            'agent_mode' => ['nullable', 'boolean'],
            'skill_ids' => ['nullable', 'array', 'max:20'],
            'skill_ids.*' => ['integer', Rule::exists('skills', 'id')->where('user_id', $request->user()->id)],
        ]);

        $profileId = $validated['profile_id'] ?? null;

        if ($profileId !== null) {
            $profile = AgentProfile::query()
                ->where('user_id', $request->user()->id)
                ->find($profileId);

            if ($profile === null) {
                abort(403, 'That agent profile does not belong to you.');
            }
        }

        $conversation = AgentConversation::create([
            'user_id' => $request->user()->id,
            'agent_profile_id' => $profileId,
            'agent_mode' => (bool) ($validated['agent_mode'] ?? true),
            'title' => mb_substr((string) ($validated['context'] ?? ''), 0, 60) !== ''
                ? 'Chat: '.mb_substr((string) ($validated['page_name'] ?? ''), 0, 50)
                : 'New chat',
            'page_url' => $validated['page_url'] ?? null,
            'page_name' => $validated['page_name'] ?? null,
            'context' => $validated['context'] ?? null,
            'skill_ids' => array_values(array_unique($validated['skill_ids'] ?? [])),
        ]);

        return response()->json(['conversation' => [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'page_name' => $conversation->page_name,
            'page_url' => $conversation->page_url,
            'context' => $conversation->context,
            'profile_name' => $conversation->profile?->name,
            'agent_mode' => (bool) $conversation->agent_mode,
            'skill_ids' => $conversation->skill_ids ?? [],
            'updated_at' => $conversation->updated_at,
        ]], 201);
    }

    /**
     * Update a conversation's title or context.
     */
    public function update(Request $request, AgentConversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'context' => ['nullable', 'string', 'max:8000'],
            'agent_mode' => ['nullable', 'boolean'],
            'agent_max_run_minutes' => ['nullable', 'integer', 'in:'.implode(',', self::AGENT_MAX_RUN_MINUTES)],
            'skill_ids' => ['nullable', 'array', 'max:20'],
            'skill_ids.*' => ['integer', Rule::exists('skills', 'id')->where('user_id', $request->user()->id)],
        ]);

        $conversation->fill(array_filter($validated, fn ($value) => $value !== null))->save();

        return response()->json(['conversation' => [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'context' => $conversation->context,
            'agent_mode' => (bool) $conversation->agent_mode,
            'agent_max_run_minutes' => (int) $conversation->agent_max_run_minutes,
            'skill_ids' => $conversation->skill_ids ?? [],
        ]]);
    }

    /**
     * A conversation's transcript.
     */
    public function messages(Request $request, AgentConversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'page_name' => $conversation->page_name,
                'page_url' => $conversation->page_url,
                'context' => $conversation->context,
                'profile_id' => $conversation->agent_profile_id,
                'profile_name' => $conversation->profile?->name,
                'updated_at' => $conversation->updated_at,
                'agent_mode' => (bool) $conversation->agent_mode,
                'agent_max_run_minutes' => (int) $conversation->agent_max_run_minutes,
                'skill_ids' => $conversation->skill_ids ?? [],
                'active_turn' => $this->activeTurn($conversation),
            ],
            'messages' => $conversation->messages()->get(),
        ]);
    }

    /**
     * One agent turn. The turn always runs in a queue job and this
     * returns immediately with the turn id; the UI replays the turn's
     * events from the events endpoint below. That follow stream
     * heartbeats while the job works, so long LLM round-trips and tool
     * runs can't idle the connection out, and a Stop lands via the
     * cancel flag even while a tool is executing. The conversation's
     * Agent mode toggle decides whether tools are available; Ask mode
     * (toggle off) is a plain, tool-free answer.
     */
    public function chat(Request $request, AgentConversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);
        $this->rejectWhenDisabled($request);

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:8000'],
            'model' => ['nullable', 'string', 'max:255'],
            'max_steps' => ['nullable', 'integer', 'in:'.implode(',', AgentService::MAX_STEPS)],
        ]);

        $profile = $conversation->profile;

        if ($profile === null) {
            abort(400, 'This conversation has no agent profile. Start a new chat.');
        }

        $model = $validated['model'] ?? null;

        // Remember the model picked in the chat header as the profile default.
        if ($model !== null && $model !== '' && $model !== $profile->default_model) {
            $profile->update(['default_model' => $model]);
        }

        $userMessage = $this->agent->startTurn($conversation, $validated['message']);

        RunAgentTurnJob::dispatch(
            $conversation->id,
            $userMessage->id,
            $validated['message'],
            $model,
            $conversation->agent_mode ? 'agent' : 'ask',
            $validated['max_steps'] ?? null,
        );

        return response()->json(['turn_id' => $userMessage->id], 202);
    }

    /**
     * Stop the turn currently running in this conversation (the user's
     * Stop button). Flags every unfinished turn row for the conversation
     * — the running loop notices at its next step boundary (one LLM
     * round-trip at most) and ends the turn cleanly, persisting what it
     * has so far. Aborting the fetch alone only detaches the UI; the
     * turn itself (an in-flight job) would
     * keep running without this.
     */
    public function cancelTurn(Request $request, AgentConversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        // Stale rows (worker died without closing the turn) are closed
        // outright — nobody is left to notice the flag.
        AgentTurn::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('finished_at')
            ->get()
            ->each(function (AgentTurn $turn) {
                $turn->forceFill(array_filter([
                    'cancel_requested_at' => now(),
                    'finished_at' => $turn->isStale() ? now() : null,
                ]))->save();
            });

        return response()->json(['ok' => true]);
    }

    /**
     * Replay a turn's persisted events over SSE, following the job's
     * progress live (both modes run in the queue). `after` is the seq
     * cursor, so a reconnecting client picks up where it left off. The
     * stream ends when a terminal event (`done` / `error`) is seen, the
     * client disconnects, or the wall-clock cap passes (the client then
     * simply reconnects — the turn itself is unaffected in the job).
     */
    public function turnEvents(Request $request, AgentConversation $conversation, int $turnId): StreamedResponse
    {
        $this->authorizeConversation($request, $conversation);

        $isTurn = AgentMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('id', $turnId)
            ->where('role', 'user')
            ->exists();

        abort_unless($isTurn, 404);

        $cursor = max(0, (int) $request->query('after', 0));

        // Generous wall clock: the selected max run time plus headroom for
        // the closing round-trip and event persistence.
        $streamLimit = max(1, (int) $conversation->agent_max_run_minutes) * 60 + 300;

        return response()->stream(function () use ($turnId, &$cursor, $streamLimit) {
            set_time_limit($streamLimit);

            $deadline = microtime(true) + $streamLimit;
            $lastHeartbeat = microtime(true);

            while (true) {
                if (connection_aborted() || microtime(true) > $deadline) {
                    return;
                }

                $events = AgentTurnEvent::query()
                    ->where('turn_id', $turnId)
                    ->where('seq', '>', $cursor)
                    ->orderBy('seq')
                    ->limit(200)
                    ->get();

                foreach ($events as $event) {
                    $cursor = $event->seq;

                    echo 'id: '.$event->seq."\n";
                    echo 'event: '.$event->event."\n";
                    echo 'data: '.json_encode($event->data ?? new \stdClass, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n\n";

                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }

                    flush();

                    if (in_array($event->event, AgentTurnEvent::TERMINAL_EVENTS, true)) {
                        return;
                    }
                }

                // Heartbeat so proxies don't idle the connection out while
                // the job works between events.
                if (microtime(true) - $lastHeartbeat >= 15) {
                    echo ": heartbeat\n\n";

                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }

                    flush();

                    $lastHeartbeat = microtime(true);
                }

                usleep(500000);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * A turn that has events but no terminal event yet — the agent
     * job is still working on it. Null when the conversation is idle.
     *
     * @return array{turn_id: int, last_seq: int, cancel_requested: bool}|null
     */
    private function activeTurn(AgentConversation $conversation): ?array
    {
        $turnId = AgentTurnEvent::query()
            ->where('conversation_id', $conversation->id)
            ->max('turn_id');

        if ($turnId === null) {
            return null;
        }

        $terminal = AgentTurnEvent::query()
            ->where('turn_id', $turnId)
            ->whereIn('event', AgentTurnEvent::TERMINAL_EVENTS)
            ->exists();

        if ($terminal) {
            return null;
        }

        return [
            'turn_id' => (int) $turnId,
            'last_seq' => (int) (AgentTurnEvent::query()->where('turn_id', $turnId)->max('seq') ?? 0),
            // A stop was already requested — the job will end the turn at
            // its next cancellation check. Lets a re-attached client show
            // "stopping" instead of "thinking".
            'cancel_requested' => AgentTurn::query()
                ->whereKey($turnId)
                ->whereNotNull('cancel_requested_at')
                ->exists(),
        ];
    }

    /**
     * Delete a conversation and its messages.
     */
    public function destroy(Request $request, AgentConversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);

        $conversation->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Delete every conversation (and its messages) of the chatting user.
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $count = AgentConversation::query()
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['deleted' => $count]);
    }

    private function authorizeConversation(Request $request, AgentConversation $conversation): void
    {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }
    }

    /**
     * Admin kill-switch: agents disabled for this user — no new
     * conversations, no new turns.
     */
    private function rejectWhenDisabled(Request $request): void
    {
        abort_if($request->user()?->agent_disabled, 403, 'AI agents have been disabled for your account by an administrator.');
    }
}
