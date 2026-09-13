<?php

namespace App\Mcp\Tools\Agent;

use App\Models\AgentConversation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetAgentConversationTool extends Tool
{
    protected string $name = 'get_agent_conversation';

    protected string $description = 'Read the full transcript of one of your AI agent chat conversations started from the Sorify web app, including the page context it was started from, tool calls the agent made, and their results.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'conversation_id' => $schema->integer()->required()->description('The conversation ID (see list_agent_conversations).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'conversation_id' => 'required|integer|exists:agent_conversations,id',
        ]);

        // Conversations are private to their owner — a not-found error also
        // avoids leaking the existence of other users' chats.
        $conversation = AgentConversation::query()
            ->where('user_id', Auth::id())
            ->with('profile:id,name')
            ->find($data['conversation_id']);

        if ($conversation === null) {
            return Response::error("No conversation found with ID [{$data['conversation_id']}].");
        }

        return Response::structured([
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'page_name' => $conversation->page_name,
                'page_url' => $conversation->page_url,
                'context' => $conversation->context,
                'profile_name' => $conversation->profile?->name,
                'created_at' => $conversation->created_at?->toIso8601String(),
                'updated_at' => $conversation->updated_at?->toIso8601String(),
            ],
            'messages' => $conversation->messages()->get()->map(fn ($message) => [
                'turn_id' => $message->turn_id,
                'role' => $message->role,
                'content' => $message->content,
                'tool_calls' => $message->tool_calls,
                'tool_call_id' => $message->tool_call_id,
                'name' => $message->name,
                'created_at' => $message->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
