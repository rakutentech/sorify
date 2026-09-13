<?php

namespace App\Http\Controllers;

use App\Models\AgentConversation;
use App\Models\AgentProfile;
use App\Services\Agent\AgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentConversationController extends Controller
{
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
        $validated = $request->validate([
            'page_url' => ['nullable', 'string', 'max:500'],
            'page_name' => ['nullable', 'string', 'max:100'],
            'context' => ['nullable', 'string', 'max:8000'],
            'profile_id' => ['nullable', 'integer', 'exists:agent_profiles,id'],
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
            'title' => mb_substr((string) $validated['context'], 0, 60) !== ''
                ? 'Chat: '.mb_substr((string) ($validated['page_name'] ?? ''), 0, 50)
                : 'New chat',
            'page_url' => $validated['page_url'] ?? null,
            'page_name' => $validated['page_name'] ?? null,
            'context' => $validated['context'] ?? null,
        ]);

        return response()->json(['conversation' => [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'page_name' => $conversation->page_name,
            'page_url' => $conversation->page_url,
            'context' => $conversation->context,
            'profile_name' => $conversation->profile?->name,
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
        ]);

        $conversation->fill(array_filter($validated, fn ($value) => $value !== null))->save();

        return response()->json(['conversation' => [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'context' => $conversation->context,
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
            ],
            'messages' => $conversation->messages()->get(),
        ]);
    }

    /**
     * One streamed agent turn over SSE.
     */
    public function chat(Request $request, AgentConversation $conversation): StreamedResponse
    {
        $this->authorizeConversation($request, $conversation);

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:8000'],
            'model' => ['nullable', 'string', 'max:255'],
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

        return response()->stream(function () use ($conversation, $validated, $model) {
            foreach ($this->agent->chat($conversation, $validated['message'], $model) as $event) {
                if (connection_aborted()) {
                    break;
                }

                echo 'event: '.$event['event']."\n";
                echo 'data: '.json_encode($event['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n\n";

                if (ob_get_level() > 0) {
                    @ob_flush();
                }

                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
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

    private function authorizeConversation(Request $request, AgentConversation $conversation): void
    {
        if ($conversation->user_id !== $request->user()->id) {
            abort(403);
        }
    }
}
