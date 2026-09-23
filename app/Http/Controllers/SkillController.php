<?php

namespace App\Http\Controllers;

use App\Models\Skill;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Inertia\Inertia;
use Inertia\Response;

class SkillController extends Controller
{
    /**
     * The shared skills browse page: public skills from every user.
     *
     * Any authenticated user can browse — no agent profile needed.
     */
    public function browse(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $perPage = (int) $request->input('per_page', 24);
        $focusId = $request->integer('skill') ?: null;

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

        $paginator = $query->paginate($perPage)->withQueryString();

        $userId = $request->user()->id;

        // Which of the listed skills the current user has already
        // installed — the browse page disables those install buttons.
        $installedIds = Skill::query()
            ->ownedBy($userId)
            ->whereIn('copied_from_id', collect($paginator->items())->pluck('id'))
            ->pluck('copied_from_id')
            ->all();

        return Inertia::render('Skills/Browse', [
            'skills' => $paginator->through(fn (Skill $skill) => [
                'id' => $skill->id,
                'user_id' => $skill->user_id,
                'name' => $skill->name,
                'description' => $skill->description,
                'content' => $skill->content,
                'copies_count' => (int) $skill->copies_count,
                'author_name' => $skill->user?->getRawOriginal('name'),
                'updated_at' => $skill->updated_at,
                'installed' => in_array($skill->id, $installedIds),
                // The author sees "Your skill" instead of an Install button.
                'owned' => $skill->user_id === $userId,
            ]),
            'filters' => ['search' => $search, 'skill' => $focusId],
            // Install buttons go inert once the viewer's collection is full.
            'skillLimit' => Skill::MAX_PER_USER,
            'limitReached' => $this->atSkillLimit($userId),
        ]);
    }

    /**
     * The chatting user's own skills (summaries only — no markdown body).
     */
    public function index(Request $request): JsonResponse
    {
        $skills = Skill::query()
            ->ownedBy($request->user()->id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'skills' => $skills->map(fn (Skill $skill) => $skill->toSummaryArray()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($this->atSkillLimit($request->user()->id)) {
            return $this->skillLimitResponse();
        }

        $validated = $this->validated($request, required: true);

        $skill = Skill::create([
            ...$validated,
            'user_id' => $request->user()->id,
            'is_public' => (bool) ($validated['is_public'] ?? false),
        ]);

        if ($skill->is_public) {
            $this->logPublished($request->user(), $skill);
        }

        return response()->json(['skill' => $skill], 201);
    }

    public function update(Request $request, Skill $skill): JsonResponse
    {
        $this->authorizeSkill($request, $skill);

        $validated = $this->validated($request, required: false);

        $wasPublic = (bool) $skill->is_public;

        $skill->fill($validated);
        $skill->is_public = (bool) ($validated['is_public'] ?? $skill->is_public);
        $skill->save();

        if (! $wasPublic && $skill->is_public) {
            $this->logPublished($request->user(), $skill);
        }

        return response()->json(['skill' => $skill]);
    }

    public function destroy(Request $request, Skill $skill): IlluminateResponse|JsonResponse
    {
        $this->authorizeSkill($request, $skill);

        $skill->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Copy someone else's public skill (or one of your own) into your
     * private, fully editable collection. The copy is detached — later
     * edits by the original owner don't propagate — and the original's
     * copy counter is incremented. Each user can install a given skill
     * only once; a repeat install returns the existing copy.
     */
    public function copy(Request $request, Skill $skill): JsonResponse
    {
        if (! $skill->is_public && $skill->user_id !== $request->user()->id) {
            abort(403, 'Only public skills can be copied.');
        }

        $existing = Skill::query()
            ->ownedBy($request->user()->id)
            ->where('copied_from_id', $skill->id)
            ->first();

        if ($existing) {
            return response()->json([
                'skill' => $existing->toSummaryArray(),
                'already_installed' => true,
            ]);
        }

        if ($this->atSkillLimit($request->user()->id)) {
            return $this->skillLimitResponse();
        }

        $copy = Skill::create([
            'user_id' => $request->user()->id,
            'name' => $skill->name,
            'description' => $skill->description,
            'content' => $skill->content,
            'is_public' => false,
            'copied_from_id' => $skill->id,
        ]);

        $skill->increment('copies_count');

        $this->logInstalled($request->user(), $copy, $skill);

        return response()->json(['skill' => $copy->toSummaryArray()], 201);
    }

    /**
     * Store requires name + content; update accepts partial payloads
     * (rename only, visibility toggle only, ...).
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$presence, 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'content' => [$presence, 'string', 'max:65535'],
            'is_public' => ['nullable', 'boolean'],
        ]);
    }

    private function authorizeSkill(Request $request, Skill $skill): void
    {
        if ($skill->user_id !== $request->user()->id) {
            abort(403);
        }
    }

    private function atSkillLimit(int $userId): bool
    {
        return Skill::query()->ownedBy($userId)->count() >= Skill::MAX_PER_USER;
    }

    private function skillLimitResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Skill limit reached — you can keep at most '.Skill::MAX_PER_USER
                .' skills. Delete one to free a slot.',
        ], 422);
    }

    /**
     * Publishing is a global (suite-less) feed event: the skill is now
     * visible to everyone on the shared Skills page.
     */
    private function logPublished($user, Skill $skill): void
    {
        ActivityLogger::log('skill_published', $user, null, $skill, ['name' => $skill->name]);
    }

    /**
     * Installing a skill is a global feed event so the author (and everyone
     * else) can see the skill being picked up. Own-skill installs are logged
     * too — only the "by {author}" attribution is reserved for someone
     * else's skill.
     */
    private function logInstalled($user, Skill $copy, Skill $original): void
    {
        $original->loadMissing('user:id,name');

        ActivityLogger::log('skill_installed', $user, null, $copy, [
            'name' => $original->name,
            'author_name' => $original->user_id !== $user->id
                ? $original->user?->getRawOriginal('name')
                : null,
        ]);
    }
}
