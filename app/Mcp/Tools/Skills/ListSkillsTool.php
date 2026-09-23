<?php

namespace App\Mcp\Tools\Skills;

use App\Models\Skill;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListSkillsTool extends Tool
{
    protected string $name = 'list_skills';

    protected string $description = 'List the current user\'s own skills, optionally filtered by a search term.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Filter skills whose name or description contains this text.'),
            'include_content' => $schema->boolean()->description('Include the full markdown content of each skill (default false — summaries only).'),
            'per_page' => $schema->integer()->enum([10, 50, 100])->default(10)->description('Results per page.'),
            'page' => $schema->integer()->min(1)->default(1)->description('Page number.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'search' => 'nullable|string',
            'include_content' => 'nullable|boolean',
            'per_page' => 'nullable|integer|in:10,50,100',
            'page' => 'nullable|integer|min:1',
        ]);

        $perPage = $data['per_page'] ?? 10;
        $search = $data['search'] ?? '';

        $query = Skill::query()
            ->ownedBy(Auth::id())
            ->orderBy('name');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage, page: $data['page'] ?? 1);
        $includeContent = (bool) ($data['include_content'] ?? false);

        $skills = collect($paginator->items())
            ->map(fn (Skill $skill) => $includeContent ? $skill->toArray() : $skill->toSummaryArray())
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
