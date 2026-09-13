<?php

namespace App\Mcp\Tools\Agent;

use App\Models\AgentConversation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListAgentConversationsTool extends Tool
{
    protected string $name = 'list_agent_conversations';

    protected string $description = 'List your AI agent chat conversations started from the Sorify web app, most recently active first. Use get_agent_conversation to read one.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Filter conversations whose title or page name contains this text.'),
            'per_page' => $schema->integer()->enum([10, 50, 100])->default(10)->description('Results per page.'),
            'page' => $schema->integer()->min(1)->default(1)->description('Page number.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|in:10,50,100',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = AgentConversation::query()
            ->where('user_id', Auth::id())
            ->with('profile:id,name')
            ->orderByDesc('updated_at');

        if (($data['search'] ?? '') !== '') {
            $search = $data['search'];

            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('page_name', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($data['per_page'] ?? 10, page: $data['page'] ?? 1);

        $conversations = collect($paginator->items())
            ->map(fn (AgentConversation $conversation) => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'page_name' => $conversation->page_name,
                'page_url' => $conversation->page_url,
                'profile_name' => $conversation->profile?->name,
                'updated_at' => $conversation->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Response::structured([
            'data' => $conversations,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
