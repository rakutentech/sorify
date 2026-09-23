<?php

namespace App\Mcp\Tools\Skills;

use App\Models\Skill;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class DeleteSkillTool extends Tool
{
    protected string $name = 'delete_skill';

    protected string $description = 'Delete one of the user\'s own skills.';

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
            ->ownedBy(Auth::id())
            ->firstOrFail();

        $skill->delete();

        return Response::structured(['deleted' => true, 'skill_id' => $data['skill_id']]);
    }
}
