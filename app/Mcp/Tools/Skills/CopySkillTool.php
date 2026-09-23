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

class CopySkillTool extends Tool
{
    protected string $name = 'copy_skill';

    protected string $description = 'Copy a public skill (or one of your own) into your private skill collection. The copy is fully editable and detached — later edits by the original author don\'t propagate. The original\'s copy counter is incremented. Each user can copy a given skill only once; a repeat call returns the existing copy with already_installed: true. A user can keep at most '.Skill::MAX_PER_USER.' skills in total, so copying fails once the collection is full.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill_id' => $schema->integer()->required()->description('The ID of the skill to copy.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['skill_id' => 'required|integer|exists:skills,id']);

        $skill = Skill::query()->findOrFail($data['skill_id']);

        if (! $skill->is_public && $skill->user_id !== Auth::id()) {
            return Response::error('Only public skills can be copied.');
        }

        $existing = Skill::query()
            ->ownedBy(Auth::id())
            ->where('copied_from_id', $skill->id)
            ->first();

        if ($existing) {
            return Response::structured([
                'skill' => $existing->toArray(),
                'already_installed' => true,
            ]);
        }

        if (Skill::query()->ownedBy(Auth::id())->count() >= Skill::MAX_PER_USER) {
            return Response::error('Skill limit reached — you can keep at most '.Skill::MAX_PER_USER
                .' skills. Delete one to free a slot.');
        }

        $copy = Skill::create([
            'user_id' => Auth::id(),
            'name' => $skill->name,
            'description' => $skill->description,
            'content' => $skill->content,
            'is_public' => false,
            'copied_from_id' => $skill->id,
        ]);

        $skill->increment('copies_count');

        $skill->loadMissing('user:id,name');

        ActivityLogger::log('skill_installed', Auth::user(), null, $copy, [
            'name' => $skill->name,
            'author_name' => $skill->user_id !== Auth::id()
                ? $skill->user?->getRawOriginal('name')
                : null,
        ]);

        return Response::structured(['skill' => $copy->toArray()]);
    }
}
