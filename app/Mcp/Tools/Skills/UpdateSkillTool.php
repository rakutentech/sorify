<?php

namespace App\Mcp\Tools\Skills;

use App\Models\Skill;
use App\Services\ActivityLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class UpdateSkillTool extends Tool
{
    protected string $name = 'update_skill';

    protected string $description = 'Update one of the user\'s own skills — rename it, edit the markdown content, or change its public visibility.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill_id' => $schema->integer()->required()->description('The skill ID.'),
            'name' => $schema->string()->description('New skill name (max 100 chars).'),
            'content' => $schema->string()->description('New skill body — the full markdown document, replacing the previous content.'),
            'description' => $schema->string()->description('New short description (max 500 chars).'),
            'is_public' => $schema->boolean()->description('Whether the skill is visible to other users on the shared skills page.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'skill_id' => 'required|integer|exists:skills,id',
            'name' => 'sometimes|string|max:100',
            'content' => 'sometimes|string|max:65535',
            'description' => 'sometimes|nullable|string|max:500',
            'is_public' => 'sometimes|boolean',
        ]);

        $skill = Skill::query()
            ->whereKey($data['skill_id'])
            ->ownedBy(Auth::id())
            ->firstOrFail();

        $wasPublic = (bool) $skill->is_public;

        $skill->fill(collect($data)->except('skill_id')->all())->save();

        if (! $wasPublic && $skill->is_public) {
            ActivityLogger::log('skill_published', Auth::user(), null, $skill, ['name' => $skill->name]);
        }

        return Response::structured(['skill' => $skill->toArray()]);
    }
}
