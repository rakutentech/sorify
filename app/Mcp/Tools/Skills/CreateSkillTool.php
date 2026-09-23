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

class CreateSkillTool extends Tool
{
    protected string $name = 'create_skill';

    protected string $description = 'Create a skill — a markdown instruction document the user can attach to My AI Agent chats. A user can keep at most '.Skill::MAX_PER_USER.' skills in total (own skills plus installed copies); creating fails once the collection is full.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Skill name (max 100 chars).'),
            'content' => $schema->string()->required()->description('The skill body — a markdown document with instructions the agent should follow when the skill is attached to a chat.'),
            'description' => $schema->string()->description('Short description of what the skill does (max 500 chars).'),
            'is_public' => $schema->boolean()->description('Whether other users can see the skill on the shared skills page and copy it. Default false (private).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (Skill::query()->ownedBy(Auth::id())->count() >= Skill::MAX_PER_USER) {
            return Response::error('Skill limit reached — you can keep at most '.Skill::MAX_PER_USER
                .' skills. Delete one to free a slot.');
        }

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'content' => 'required|string|max:65535',
            'description' => 'nullable|string|max:500',
            'is_public' => 'nullable|boolean',
        ]);

        $skill = Skill::create([
            ...$data,
            'user_id' => Auth::id(),
            'is_public' => (bool) ($data['is_public'] ?? false),
        ]);

        if ($skill->is_public) {
            ActivityLogger::log('skill_published', Auth::user(), null, $skill, ['name' => $skill->name]);
        }

        return Response::structured(['skill' => $skill->toArray()]);
    }
}
