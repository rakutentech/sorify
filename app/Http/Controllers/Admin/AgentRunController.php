<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentTurn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin: currently running AI agent turns — who is running one, in
 * which mode, for how long, and the ability to stop a turn (the turn
 * loop checks the cancel flag at each step boundary and stops cleanly).
 * Chat contents are never shown here — not the conversation title
 * (derived from the user's first message) nor any message text.
 */
class AgentRunController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/AgentRuns/Index', [
            'turns' => $this->runningTurns(),
        ]);
    }

    /**
     * Fresh list for the page's polling.
     */
    public function list(): JsonResponse
    {
        return response()->json(['turns' => $this->runningTurns()]);
    }

    /**
     * Request a stop: flags the turn, the running loop notices at its next
     * step boundary (one LLM round-trip at most) and ends the turn. Stale
     * rows (worker died without closing the turn) are closed outright —
     * nobody is left to notice the flag.
     */
    public function stop(Request $request, AgentTurn $turn): JsonResponse
    {
        if ($turn->finished_at !== null) {
            return response()->json(['ok' => false, 'message' => 'That turn already finished.'], 409);
        }

        $turn->forceFill(array_filter([
            'cancel_requested_at' => now(),
            'finished_at' => $turn->isStale() ? now() : null,
        ]))->save();

        return response()->json(['ok' => true]);
    }

    /**
     * Unfinished turns (Ask and Agent modes alike), newest first.
     * Stale rows (worker died without closing the turn — no activity for
     * 15+ minutes) are surfaced so an admin can clear them; stopping them
     * just closes the row. No chat content is exposed: only who, which
     * mode, and when.
     *
     * @return list<array<string, mixed>>
     */
    private function runningTurns(): array
    {
        return AgentTurn::query()
            ->with(['user:id,name,email,avatar'])
            ->whereNull('finished_at')
            ->orderByDesc('started_at')
            ->limit(100)
            ->get()
            ->map(fn (AgentTurn $turn) => [
                'id' => $turn->id,
                'mode' => $turn->mode,
                'started_at' => $turn->started_at->toIso8601String(),
                'last_activity_at' => $turn->last_activity_at?->toIso8601String(),
                'elapsed_seconds' => max(0, abs(now()->diffInSeconds($turn->started_at))),
                'stale' => $turn->isStale(),
                'cancel_requested' => $turn->cancel_requested_at !== null,
                'user' => $turn->user?->only(['id', 'name', 'email', 'avatar_url']),
            ])
            ->all();
    }
}
