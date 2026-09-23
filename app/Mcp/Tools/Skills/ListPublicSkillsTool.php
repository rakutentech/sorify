<?php

namespace App\Mcp\Tools\Skills;

use App\Models\Skill;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListPublicSkillsTool extends Tool
{
    protected string $name = 'list_public_skills';

    protected string $description = 'List skills other users have shared publicly, optionally filtered by a search term.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Filter skills whose name or description contains this text.'),
            'per_page' => $schema->integer()->enum([10, 50, 100])->default(10)->description('Results per page.'),
            'page' => $schema->integer()->min(1)->default(1)->description('Page number.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'search' => 'nullable|string',
            'per_page' => 'nullable|integer|in:10,50,100',
            'page' => 'nullable|integer|min:1',
        ]);

        $perPage = $data['per_page'] ?? 10;
        $search = $data['search'] ?? '';

        $query = Skill::query()
            ->public()
            ->original()
            ->with('user:id,name')
            ->orderByDesc('copies_count')
            ->orderByDesc('updated_at');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage, page: $data['page'] ?? 1);

        $skills = collect($paginator->items())
            ->map(fn (Skill $skill) => [
                ...$skill->toSummaryArray(),
                'author_name' => $skill->user?->getRawOriginal('name'),
                'is_own' => $skill->user_id === Auth::id(),
            ])
            ->values()
            ->all();

        return Response::structured([
            'data' => $skills,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
