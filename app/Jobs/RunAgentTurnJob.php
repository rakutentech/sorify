<?php

namespace App\Jobs;

use App\Models\AgentConversation;
use App\Models\AgentMessage;
use App\Models\AgentTurn;
use App\Models\AgentTurnEvent;
use App\Services\Agent\AgentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Run one agent turn in the queue, detached from the chat request — it
 * keeps going even after the browser window is closed. Each SSE event
 * the turn yields is persisted to agent_turn_events; the UI replays them
 * (cursor = seq) to render the turn live or catch up after a reconnect.
 */
class RunAgentTurnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $turnId,
        public readonly string $message,
        public readonly ?string $model,
        public readonly string $mode = 'agent',
        public readonly ?int $maxSteps = null,
    ) {
        // Dedicated queue: agent turns run for up to an hour, and must not
        // sit behind (nor block) test executions on the shared queue. The
        // test workers also list `agent` last as a fallback, so turns still
        // run if every dedicated agent worker is down — only when the test
        // queues are empty.
        $this->onQueue('agent');
    }

    /**
     * The worker timeout scales with the conversation's max run time,
     * plus headroom for the final LLM round-trip and persistence.
     */
    public function timeout(): int
    {
        $minutes = (int) (AgentConversation::query()->find($this->conversationId)?->agent_max_run_minutes ?? 10);

        return $minutes * 60 + 120;
    }

    public function handle(AgentService $agent): void
    {
        $conversation = AgentConversation::query()->findOrFail($this->conversationId);
        $userMessage = AgentMessage::query()->findOrFail($this->turnId);

        // The agent's tools re-run their authorization gates against the
        // chatting user — log that user in so the worker acts as them.
        Auth::loginUsingId($conversation->user_id);

        $deadlineSeconds = max(1, (int) $conversation->agent_max_run_minutes) * 60;

        $seq = (int) (AgentTurnEvent::query()->where('turn_id', $this->turnId)->max('seq') ?? 0);

        foreach ($agent->runTurn($conversation, $userMessage, $this->model, $this->mode, $deadlineSeconds, $this->maxSteps) as $event) {
            AgentTurnEvent::query()->create([
                'conversation_id' => $conversation->id,
                'turn_id' => $this->turnId,
                'seq' => ++$seq,
                'event' => $event['event'],
                'data' => $event['data'],
            ]);
        }

        $this->ensureTerminalEvent($conversation);
    }

    /**
     * A timeout or crash kills the worker mid-turn — without this, the
     * replay stream (and the "turn running" indicator) would hang forever.
     */
    public function failed(Throwable $exception): void
    {
        // The tracked row would otherwise linger as a ghost "running" /
        // "Stopping…" entry on the admin page — nobody is left to run the
        // generator's finally, so close it here.
        AgentTurn::query()
            ->whereKey($this->turnId)
            ->whereNull('finished_at')
            ->update(['finished_at' => now()]);

        $conversation = AgentConversation::query()->find($this->conversationId);

        if ($conversation !== null) {
            $this->ensureTerminalEvent($conversation);
        }
    }

    /**
     * Guarantee the turn always ends with a terminal event for the UI. The
     * generator normally yields `done`/`error` itself; this covers the
     * paths where it can't (hard crash, worker kill).
     */
    private function ensureTerminalEvent(AgentConversation $conversation): void
    {
        $terminal = AgentTurnEvent::query()
            ->where('turn_id', $this->turnId)
            ->whereIn('event', AgentTurnEvent::TERMINAL_EVENTS)
            ->exists();

        if ($terminal) {
            return;
        }

        AgentTurnEvent::query()->create([
            'conversation_id' => $conversation->id,
            'turn_id' => $this->turnId,
            'seq' => (int) (AgentTurnEvent::query()->where('turn_id', $this->turnId)->max('seq') ?? 0) + 1,
            'event' => 'error',
            'data' => ['message' => 'This turn stopped unexpectedly (worker timeout or failure). Continue in a new message if more work is needed.'],
        ]);
    }
}
