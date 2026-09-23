<?php

namespace Tests\Feature;

use App\Mcp\Servers\SorifyServer;
use App\Mcp\Tools\Skills\CopySkillTool;
use App\Mcp\Tools\Skills\CreateSkillTool;
use App\Mcp\Tools\Skills\DeleteSkillTool;
use App\Mcp\Tools\Skills\GetSkillTool;
use App\Mcp\Tools\Skills\ListPublicSkillsTool;
use App\Mcp\Tools\Skills\ListSkillsTool;
use App\Mcp\Tools\Skills\UpdateSkillTool;
use App\Models\Activity;
use App\Models\AgentConversation;
use App\Models\AgentProfile;
use App\Models\Skill;
use App\Models\User;
use App\Services\Agent\AgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class SkillTest extends TestCase
{
    use RefreshDatabase;

    private function createSkill(User $user, array $overrides = []): Skill
    {
        return Skill::create([
            'user_id' => $user->id,
            'name' => 'QA review checklist',
            'description' => 'Review tests before running',
            'content' => "# QA review\n\nAlways use strict selectors.",
            ...$overrides,
        ]);
    }

    // ── Own skills CRUD ──────────────────────────────────────────────────────

    public function test_index_lists_only_own_skills(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->createSkill($alice, ['name' => 'Alice skill']);

        $this->actingAs($bob)->getJson('/sorify/skills')
            ->assertOk()
            ->assertJsonCount(0, 'skills');

        $this->actingAs($alice)->getJson('/sorify/skills')
            ->assertOk()
            ->assertJsonCount(1, 'skills')
            ->assertJsonPath('skills.0.name', 'Alice skill');
    }

    public function test_store_creates_private_skill_by_default(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/sorify/skills', [
            'name' => 'My skill',
            'description' => 'Do things',
            'content' => '# Instructions',
        ]);

        $response->assertCreated()
            ->assertJsonPath('skill.name', 'My skill')
            ->assertJsonPath('skill.is_public', false);

        $skill = Skill::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertFalse((bool) $skill->is_public);
    }

    public function test_store_requires_name_and_content(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/sorify/skills', [
            'name' => '',
            'content' => '',
        ])->assertStatus(422)->assertJsonValidationErrors(['name', 'content']);
    }

    public function test_update_renames_edits_and_toggles_public(): void
    {
        $user = User::factory()->create();
        $skill = $this->createSkill($user);

        $this->actingAs($user)->putJson("/sorify/skills/{$skill->id}", [
            'name' => 'Renamed',
            'content' => '# New content',
            'is_public' => true,
        ])->assertOk()->assertJsonPath('skill.name', 'Renamed');

        $skill->refresh();

        $this->assertSame('Renamed', $skill->name);
        $this->assertSame('# New content', $skill->content);
        $this->assertTrue((bool) $skill->is_public);
    }

    public function test_update_rejects_someone_elses_skill(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $skill = $this->createSkill($alice);

        $this->actingAs($bob)->putJson("/sorify/skills/{$skill->id}", [
            'name' => 'Hijacked',
            'content' => '# No',
        ])->assertForbidden();
    }

    public function test_destroy_deletes_own_skill(): void
    {
        $user = User::factory()->create();
        $skill = $this->createSkill($user);

        $this->actingAs($user)->deleteJson("/sorify/skills/{$skill->id}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('skills', ['id' => $skill->id]);
    }

    // ── Feed activity ────────────────────────────────────────────────────────

    public function test_publishing_a_skill_logs_a_feed_activity(): void
    {
        $user = User::factory()->create();

        // Creating it public right away logs the event.
        $this->actingAs($user)->postJson('/sorify/skills', [
            'name' => 'Shared skill',
            'content' => '# Hello',
            'is_public' => true,
        ])->assertCreated();

        $activity = Activity::query()->where('type', 'skill_published')->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->id, $activity->actor_id);
        $this->assertNull($activity->suite_id);
        $this->assertSame(['name' => 'Shared skill'], $activity->payload);
    }

    public function test_toggling_private_to_public_logs_once(): void
    {
        $user = User::factory()->create();
        $skill = $this->createSkill($user, ['is_public' => false]);

        // Private edits don't log.
        $this->actingAs($user)->putJson("/sorify/skills/{$skill->id}", ['name' => 'Still private'])
            ->assertOk();

        $this->assertDatabaseCount('activities', 0);

        // Flipping the switch to public logs.
        $this->actingAs($user)->putJson("/sorify/skills/{$skill->id}", ['is_public' => true])
            ->assertOk();

        $this->assertDatabaseCount('activities', 1);
        $this->assertDatabaseHas('activities', ['type' => 'skill_published']);

        // Already-public updates don't log again.
        $this->actingAs($user)->putJson("/sorify/skills/{$skill->id}", ['name' => 'Renamed again'])
            ->assertOk();

        $this->assertDatabaseCount('activities', 1);

        // Turning it back private doesn't log either.
        $this->actingAs($user)->putJson("/sorify/skills/{$skill->id}", ['is_public' => false])
            ->assertOk();

        $this->assertDatabaseCount('activities', 1);
    }

    public function test_creating_a_private_skill_logs_nothing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/sorify/skills', [
            'name' => 'Private skill',
            'content' => '# Secret',
        ])->assertCreated();

        $this->assertDatabaseCount('activities', 0);
    }

    public function test_installing_someones_skill_logs_a_feed_activity(): void
    {
        $author = User::factory()->create(['name' => 'Original Author']);
        $member = User::factory()->create();

        $skill = $this->createSkill($author, ['name' => 'Shared skill', 'is_public' => true]);

        $this->actingAs($member)->postJson("/sorify/skills/{$skill->id}/copy")
            ->assertCreated();

        $activity = Activity::query()->where('type', 'skill_installed')->first();

        $this->assertNotNull($activity);
        $this->assertSame($member->id, $activity->actor_id);
        $this->assertNull($activity->suite_id);
        $this->assertSame(['name' => 'Shared skill', 'author_name' => 'Original Author'], $activity->payload);

        // Installing one of your own skills is logged too — but without
        // the "by {author}" attribution, since it is your own skill.
        $own = $this->createSkill($member, ['name' => 'My own skill']);

        $this->actingAs($member)->postJson("/sorify/skills/{$own->id}/copy")
            ->assertCreated();

        $ownInstall = Activity::query()
            ->where('type', 'skill_installed')
            ->where('actor_id', $member->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($ownInstall);
        $this->assertSame(['name' => 'My own skill', 'author_name' => null], $ownInstall->payload);
        $this->assertSame(2, Activity::query()->where('type', 'skill_installed')->count());
    }

    public function test_a_user_cannot_exceed_the_skill_limit(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < Skill::MAX_PER_USER - 1; $i++) {
            Skill::create(['user_id' => $user->id, 'name' => "Skill {$i}", 'content' => '# x']);
        }

        // The 50th skill is an existing install of someone's skill.
        $author = User::factory()->create();
        $original = $this->createSkill($author, ['name' => 'Shared', 'is_public' => true]);
        $existing = $this->createSkill($user, ['name' => 'My install', 'copied_from_id' => $original->id]);

        // Creating the 51st skill is rejected.
        $this->actingAs($user)->postJson('/sorify/skills', [
            'name' => 'Over the limit',
            'content' => '# No room',
        ])->assertStatus(422);

        $this->assertSame(Skill::MAX_PER_USER, Skill::query()->ownedBy($user->id)->count());

        // Installing is rejected too — but an already-installed skill still
        // resolves to its existing copy rather than the limit error.
        $this->actingAs($user)->postJson("/sorify/skills/{$original->id}/copy")
            ->assertOk()
            ->assertJsonPath('skill.id', $existing->id)
            ->assertJsonPath('already_installed', true);

        $fresh = $this->createSkill($author, ['name' => 'Other', 'is_public' => true]);

        $this->actingAs($user)->postJson("/sorify/skills/{$fresh->id}/copy")
            ->assertStatus(422);

        $this->assertSame(Skill::MAX_PER_USER, Skill::query()->ownedBy($user->id)->count());
    }

    public function test_browse_marks_owned_skills_and_reports_the_limit(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $own = $this->createSkill($user, ['name' => 'Mine', 'is_public' => true]);
        $their = $this->createSkill($other, ['name' => 'Theirs', 'is_public' => true]);

        $response = $this->actingAs($user)->get('/sorify/skills/browse');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('skillLimit', Skill::MAX_PER_USER)
            ->where('limitReached', false)
            ->has('skills.data', 2)
            ->etc());

        // Own vs. other's skill both carry the right flag.
        $props = $response->inertiaProps()['skills']['data'];

        $this->assertSame([$own->id => true, $their->id => false], collect($props)
            ->mapWithKeys(fn ($skill) => [$skill['id'] => $skill['owned']])
            ->all());

        // At the limit the page tells the client to disable installs.
        for ($i = 0; $i < Skill::MAX_PER_USER; $i++) {
            Skill::create(['user_id' => $user->id, 'name' => "Extra {$i}", 'content' => '# x']);
        }

        $this->actingAs($user)->get('/sorify/skills/browse')->assertInertia(fn (Assert $page) => $page
            ->where('limitReached', true)
            ->etc());
    }

    public function test_profile_lists_where_each_installed_skill_was_copied_from(): void
    {
        $author = User::factory()->create(['name' => 'Original Author']);
        $member = User::factory()->create();

        $original = $this->createSkill($author, ['name' => 'Shared skill', 'is_public' => true]);

        $copyId = $this->actingAs($member)
            ->postJson("/sorify/skills/{$original->id}/copy")
            ->assertCreated()
            ->json('skill.id');

        $response = $this->actingAs($member)->get('/sorify/profile');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('skill_limit', Skill::MAX_PER_USER)
            ->etc());

        $copy = collect($response->inertiaProps()['skills'])
            ->firstWhere('id', $copyId);

        $this->assertSame([
            'id' => $original->id,
            'name' => 'Shared skill',
            'is_public' => true,
            'author_name' => 'Original Author',
        ], $copy['original']);

        // Deleting the original leaves the installed copy in place, but
        // the profile no longer points at anything.
        $original->delete();

        $response = $this->actingAs($member)->get('/sorify/profile');

        $copy = collect($response->inertiaProps()['skills'])
            ->firstWhere('id', $copyId);

        $this->assertNull($copy['original']);
        $this->assertSame($original->id, $copy['copied_from_id']);
    }

    // ── Browse + copy ────────────────────────────────────────────────────────

    public function test_browse_lists_public_skills_from_every_user(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);

        $this->createSkill($alice, ['name' => 'Public skill', 'is_public' => true]);
        $this->createSkill($bob, ['name' => 'Private skill']);

        $response = $this->actingAs($bob)->get('/sorify/skills/browse');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('skills.data.0.name', 'Public skill')
            ->where('skills.data.0.author_name', 'Alice')
            ->has('skills.data', 1));
    }

    public function test_browse_hides_copies_even_when_they_are_public(): void
    {
        $author = User::factory()->create();
        $member = User::factory()->create();

        $original = $this->createSkill($author, ['name' => 'Original skill', 'is_public' => true]);

        $copyId = $this->actingAs($member)
            ->postJson("/sorify/skills/{$original->id}/copy")
            ->assertCreated()
            ->json('skill.id');

        // The member later shares their installed copy too — the browse
        // page must still show the original only.
        Skill::findOrFail($copyId)->update(['is_public' => true]);

        $this->actingAs($member)->get('/sorify/skills/browse')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('skills.data.0.name', 'Original skill')
                ->has('skills.data', 1));

        // The MCP listing mirrors the browse page.
        SorifyServer::actingAs($member)
            ->tool(ListPublicSkillsTool::class, ['search' => 'skill'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->has('data', 1)
                ->where('data.0.name', 'Original skill')
                ->etc());
    }

    public function test_member_without_agent_profile_can_browse_and_copy(): void
    {
        $author = User::factory()->create();
        $member = User::factory()->create(['is_view_only' => false]);

        $skill = $this->createSkill($author, ['is_public' => true]);

        // The member has no agent profile configured.
        $this->assertDatabaseMissing('agent_profiles', ['user_id' => $member->id]);

        $this->actingAs($member)->get('/sorify/skills/browse')->assertOk();

        $response = $this->actingAs($member)->postJson("/sorify/skills/{$skill->id}/copy");

        $response->assertCreated();

        $copyId = $response->json('skill.id');

        $this->assertNotSame($skill->id, $copyId);

        $copy = Skill::findOrFail($copyId);

        $this->assertSame($member->id, $copy->user_id);
        $this->assertFalse((bool) $copy->is_public);
        $this->assertSame($skill->id, $copy->copied_from_id);

        $skill->refresh();

        $this->assertSame(1, $skill->copies_count);
    }

    public function test_copy_is_detached_from_the_original(): void
    {
        $author = User::factory()->create();
        $member = User::factory()->create();

        $skill = $this->createSkill($author, ['is_public' => true]);

        $copyId = $this->actingAs($member)
            ->postJson("/sorify/skills/{$skill->id}/copy")
            ->assertCreated()
            ->json('skill.id');

        // The original changes — the copy must not.
        $skill->update(['content' => '# Changed']);

        $this->assertSame(
            "# QA review\n\nAlways use strict selectors.",
            Skill::findOrFail($copyId)->content,
        );
    }

    public function test_cannot_copy_a_private_skill_of_someone_else(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $skill = $this->createSkill($alice, ['is_public' => false]);

        $this->actingAs($bob)->postJson("/sorify/skills/{$skill->id}/copy")
            ->assertForbidden();

        $this->assertSame(0, $skill->refresh()->copies_count);
    }

    public function test_a_skill_can_only_be_installed_once(): void
    {
        $author = User::factory()->create();
        $member = User::factory()->create();

        $skill = $this->createSkill($author, ['is_public' => true]);

        $first = $this->actingAs($member)
            ->postJson("/sorify/skills/{$skill->id}/copy")
            ->assertCreated()
            ->json('skill.id');

        $response = $this->actingAs($member)
            ->postJson("/sorify/skills/{$skill->id}/copy")
            ->assertOk();

        $this->assertTrue($response->json('already_installed'));
        $this->assertSame($first, $response->json('skill.id'));

        // No duplicate row and no second counter bump.
        $this->assertSame(1, Skill::query()
            ->where('user_id', $member->id)
            ->where('copied_from_id', $skill->id)
            ->count());
        $this->assertSame(1, $skill->refresh()->copies_count);
    }

    public function test_browse_marks_skills_the_user_already_installed(): void
    {
        $author = User::factory()->create();
        $member = User::factory()->create();

        $installed = $this->createSkill($author, ['name' => 'Installed one', 'is_public' => true]);
        $this->createSkill($author, ['name' => 'Fresh one', 'is_public' => true]);

        $this->actingAs($member)->postJson("/sorify/skills/{$installed->id}/copy")->assertCreated();

        $this->actingAs($member)->get('/sorify/skills/browse')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('skills.data.0.name', 'Installed one')
                ->where('skills.data.0.installed', true)
                ->where('skills.data.1.name', 'Fresh one')
                ->where('skills.data.1.installed', false));
    }

    // ── Conversations ────────────────────────────────────────────────────────

    public function test_conversation_accepts_own_skill_ids(): void
    {
        $user = User::factory()->create();
        $skill = $this->createSkill($user);
        $other = $this->createSkill(User::factory()->create());

        $response = $this->actingAs($user)->postJson('/sorify/agent/conversations', [
            'skill_ids' => [$skill->id],
        ]);

        $response->assertCreated();

        $conversation = AgentConversation::findOrFail($response->json('conversation.id'));

        $this->assertSame([$skill->id], $conversation->skill_ids);

        // A skill owned by someone else is rejected.
        $this->actingAs($user)->postJson('/sorify/agent/conversations', [
            'skill_ids' => [$other->id],
        ])->assertStatus(422);
    }

    public function test_messages_returns_the_conversation_skill_ids(): void
    {
        $user = User::factory()->create();
        $skill = $this->createSkill($user);

        $conversation = AgentConversation::create([
            'user_id' => $user->id,
            'skill_ids' => [$skill->id],
        ]);

        $this->actingAs($user)
            ->getJson("/sorify/agent/conversations/{$conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('conversation.skill_ids.0', $skill->id);
    }

    public function test_selected_skills_are_injected_into_the_system_prompt(): void
    {
        $user = User::factory()->create();

        $selected = $this->createSkill($user, [
            'name' => 'Strict selectors',
            'content' => 'Always use strict selectors.',
        ]);

        $this->createSkill($user, [
            'name' => 'Unselected skill',
            'content' => 'Never referenced.',
        ]);

        $conversation = AgentConversation::create([
            'user_id' => $user->id,
            'skill_ids' => [$selected->id],
        ]);

        $profile = AgentProfile::create([
            'user_id' => $user->id,
            'name' => 'My OpenAI',
            'base_url' => 'https://api.openai.com',
            'api_token' => 'sk-secret',
            'history_retention_days' => 30,
        ]);

        $service = (new ReflectionClass(AgentService::class))->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(AgentService::class, 'systemMessage');
        $method->setAccessible(true);

        $message = $method->invoke($service, $conversation, $profile);

        $this->assertSame('system', $message['role']);
        $this->assertStringContainsString('Strict selectors', $message['content']);
        $this->assertStringContainsString('Always use strict selectors.', $message['content']);
        $this->assertStringNotContainsString('Unselected skill', $message['content']);
    }

    public function test_another_users_skills_are_not_injected(): void
    {
        $user = User::factory()->create();
        $foreign = $this->createSkill(User::factory()->create(), ['name' => 'Foreign skill']);

        $conversation = AgentConversation::create([
            'user_id' => $user->id,
            'skill_ids' => [$foreign->id],
        ]);

        $profile = AgentProfile::create([
            'user_id' => $user->id,
            'name' => 'My OpenAI',
            'base_url' => 'https://api.openai.com',
            'api_token' => 'sk-secret',
            'history_retention_days' => 30,
        ]);

        $service = (new ReflectionClass(AgentService::class))->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(AgentService::class, 'systemMessage');
        $method->setAccessible(true);

        $message = $method->invoke($service, $conversation, $profile);

        $this->assertStringNotContainsString('Foreign skill', $message['content']);
    }

    // ── MCP tools ────────────────────────────────────────────────────────────

    public function test_mcp_skills_tools_crud_and_copy(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        SorifyServer::actingAs($alice)
            ->tool(CreateSkillTool::class, [
                'name' => 'Shared checklist',
                'description' => 'Checklist',
                'content' => '# Checklist',
                'is_public' => true,
            ])
            ->assertOk();

        $skill = Skill::query()->where('user_id', $alice->id)->firstOrFail();

        // Bob sees it among the public skills and can read it.
        SorifyServer::actingAs($bob)
            ->tool(ListPublicSkillsTool::class, [])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('meta.total', 1)
                ->where('data.0.id', $skill->id)
                ->where('data.0.name', 'Shared checklist')
                ->etc());

        SorifyServer::actingAs($bob)
            ->tool(GetSkillTool::class, ['skill_id' => $skill->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('skill.content', '# Checklist')
                ->etc());

        // Alice renames and edits it.
        SorifyServer::actingAs($alice)
            ->tool(UpdateSkillTool::class, [
                'skill_id' => $skill->id,
                'name' => 'Renamed checklist',
                'content' => '# Better checklist',
            ])
            ->assertOk();

        // Bob copies it — the original's counter increments.
        SorifyServer::actingAs($bob)
            ->tool(CopySkillTool::class, ['skill_id' => $skill->id])
            ->assertOk();

        $this->assertSame(1, $skill->refresh()->copies_count);

        $copy = Skill::query()->where('user_id', $bob->id)->firstOrFail();

        $this->assertSame('Renamed checklist', $copy->name);
        $this->assertSame('# Better checklist', $copy->content);
        $this->assertFalse((bool) $copy->is_public);

        // Bob cannot install it twice — the repeat call returns the
        // existing copy and does not bump the counter again.
        SorifyServer::actingAs($bob)
            ->tool(CopySkillTool::class, ['skill_id' => $skill->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('already_installed', true)
                ->where('skill.id', $copy->id)
                ->etc());

        $this->assertSame(1, $skill->refresh()->copies_count);

        // Bob's own list shows the copy; Alice's shows the original.
        SorifyServer::actingAs($bob)
            ->tool(ListSkillsTool::class, [])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('meta.total', 1)
                ->where('data.0.id', $copy->id)
                ->etc());

        // Alice deletes her original.
        SorifyServer::actingAs($alice)
            ->tool(DeleteSkillTool::class, ['skill_id' => $skill->id])
            ->assertOk();

        $this->assertDatabaseHas('skills', ['id' => $copy->id]);
        $this->assertDatabaseMissing('skills', ['id' => $skill->id]);
    }

    public function test_mcp_cannot_read_someone_elses_private_skill(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $skill = $this->createSkill($alice, ['is_public' => false]);

        SorifyServer::actingAs($bob)
            ->tool(GetSkillTool::class, ['skill_id' => $skill->id])
            ->assertHasErrors();
    }

    public function test_mcp_cannot_update_someone_elses_skill(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $skill = $this->createSkill($alice, ['is_public' => true]);

        SorifyServer::actingAs($bob)
            ->tool(UpdateSkillTool::class, [
                'skill_id' => $skill->id,
                'name' => 'Hijacked',
                'content' => '# No',
            ])
            ->assertHasErrors();

        $this->assertSame('QA review checklist', $skill->refresh()->name);
    }

    public function test_mcp_cannot_copy_a_private_skill(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $skill = $this->createSkill($alice, ['is_public' => false]);

        SorifyServer::actingAs($bob)
            ->tool(CopySkillTool::class, ['skill_id' => $skill->id])
            ->assertHasErrors();
        $this->assertSame(0, $skill->refresh()->copies_count);
        $this->assertDatabaseMissing('skills', ['user_id' => $bob->id]);
    }

    public function test_mcp_skills_tools_reject_the_51st_skill(): void
    {
        $user = User::factory()->create();
        $author = User::factory()->create();

        for ($i = 0; $i < Skill::MAX_PER_USER; $i++) {
            Skill::create(['user_id' => $user->id, 'name' => "Skill {$i}", 'content' => '# x']);
        }

        // Creating over the limit fails.
        SorifyServer::actingAs($user)
            ->tool(CreateSkillTool::class, ['name' => 'Over', 'content' => '# no room'])
            ->assertHasErrors();

        // Copying over the limit fails too, and the counter isn't bumped.
        $shared = $this->createSkill($author, ['name' => 'Shared', 'is_public' => true]);

        SorifyServer::actingAs($user)
            ->tool(CopySkillTool::class, ['skill_id' => $shared->id])
            ->assertHasErrors();

        $this->assertSame(0, $shared->refresh()->copies_count);
        $this->assertSame(Skill::MAX_PER_USER, Skill::query()->ownedBy($user->id)->count());
        $this->assertDatabaseMissing('skills', ['user_id' => $user->id, 'copied_from_id' => $shared->id]);
    }
}
