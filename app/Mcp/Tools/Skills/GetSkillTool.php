<?php

namespace App\Mcp\Tools\Skills;

use App\Models\Skill;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetSkillTool extends Tool
{
    protected string $name = 'get_skill';

    protected string $description = 'Get one skill with its full markdown content. Own skills and public skills are readable.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill_id' => $schema->integer()->required()->description('The skill ID.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['skill_id' => 'required|integer|exists:skills,id']);

        $skill = Skill::query()
            ->whereKey($data['skill_id'])
            ->with('user:id,name')
            ->firstOrFail();

        if (! $skill->is_public && $skill->user_id !== Auth::id()) {
            return Response::error('That skill is private.');
        }

        return Response::structured(['skill' => [
            ...$skill->toArray(),
            'author_name' => $skill->user?->getRawOriginal('name'),
            'is_own' => $skill->user_id === Auth::id(),
        ]]);
    }
}
