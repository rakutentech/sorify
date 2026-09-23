<?php

namespace Tests\Feature;

use App\Models\AgentConversation;
use App\Models\Skill;
use App\Models\Test;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    private function suite(array $attributes = []): TestSuite
    {
        return TestSuite::create(array_merge([
            'name' => 'Suite',
            'base_url' => 'https://example.com',
        ], $attributes));
    }

    private function searchAs(User $user, string $query): array
    {
        return $this->actingAs($user)
            ->getJson('/sorify/search?q='.urlencode($query))
            ->assertOk()
            ->json();
    }

    public function test_search_requires_authentication(): void
    {
        $this->get('/sorify/search?q=anything')->assertRedirect(route('login'));
    }

    public function test_short_queries_return_empty_groups(): void
    {
        $admin = User::factory()->admin()->create();
        $this->suite(['name' => 'Checkout suite']);

        $results = $this->searchAs($admin, 'c');

        $this->assertSame([], $results['suites']);
        $this->assertSame([], $results['tests']);
        $this->assertSame([], $results['runs']);
        $this->assertSame([], $results['conversations']);
        $this->assertSame([], $results['skills']);
    }

    public function test_admin_finds_suites_by_name(): void
    {
        $admin = User::factory()->admin()->create();
        $this->suite(['name' => 'Checkout suite']);
        $this->suite(['name' => 'Unrelated']);

        $results = $this->searchAs($admin, 'checkout');

        $this->assertCount(1, $results['suites']);
        $this->assertSame('Checkout suite', $results['suites'][0]['name']);
    }

    public function test_non_members_cannot_find_suites_they_cannot_view(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $hidden = $this->suite(['name' => 'Hidden suite']);
        $visible = $this->suite(['name' => 'Visible suite']);

        $visible->members()->attach($user->id, ['can_view' => true]);
        $hidden->members()->attach($user->id, ['can_view' => false]);

        $results = $this->searchAs($user, 'suite');

        $this->assertSame(['Visible suite'], array_column($results['suites'], 'name'));
    }

    public function test_tests_are_scoped_to_visible_suites(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $visible = $this->suite(['name' => 'Visible suite']);
        $hidden = $this->suite(['name' => 'Hidden suite']);

        $visible->members()->attach($user->id, ['can_view' => true]);

        Test::create(['test_suite_id' => $visible->id, 'name' => 'Login flow works']);
        Test::create(['test_suite_id' => $hidden->id, 'name' => 'Login flow broken']);

        $results = $this->searchAs($user, 'login flow');

        $this->assertCount(1, $results['tests']);
        $this->assertSame('Login flow works', $results['tests'][0]['name']);
        $this->assertSame('Visible suite', $results['tests'][0]['suite_name']);
    }

    public function test_runs_match_by_suite_name_and_id(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = $this->suite(['name' => 'Payments suite']);
        $filler = $this->suite(['name' => 'Filler suite']);

        // Pad the run sequence so the target id is two digits — the search
        // endpoint ignores queries shorter than two characters, and a
        // single-digit id would be unsearchable by design.
        for ($i = 0; $i < 9; $i++) {
            TestRun::create(['test_suite_id' => $filler->id, 'status' => 'completed']);
        }

        $run = TestRun::create([
            'test_suite_id' => $suite->id,
            'status' => 'completed',
            'total_tests' => 1,
            'passed_count' => 1,
            'failed_count' => 0,
        ]);

        $bySuiteName = $this->searchAs($admin, 'payments');
        $this->assertCount(1, $bySuiteName['runs']);
        $this->assertSame($run->id, $bySuiteName['runs'][0]['id']);

        $byId = $this->searchAs($admin, (string) $run->id);
        $this->assertCount(1, $byId['runs']);
        $this->assertSame($run->id, $byId['runs'][0]['id']);
    }

    public function test_runs_are_scoped_to_visible_suites(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $visible = $this->suite(['name' => 'Visible suite']);
        $hidden = $this->suite(['name' => 'Hidden suite']);

        $visible->members()->attach($user->id, ['can_view' => true]);

        TestRun::create(['test_suite_id' => $visible->id, 'status' => 'completed']);
        TestRun::create(['test_suite_id' => $hidden->id, 'status' => 'completed']);

        $results = $this->searchAs($user, 'suite');

        $this->assertCount(1, $results['runs']);
        $this->assertSame($visible->id, $results['runs'][0]['suite_id']);
    }

    public function test_conversations_are_only_the_users_own(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $other = User::factory()->create(['is_admin' => false]);

        AgentConversation::create(['user_id' => $user->id, 'title' => 'My regression chat']);
        AgentConversation::create(['user_id' => $other->id, 'title' => 'Their regression chat']);

        $results = $this->searchAs($user, 'regression');

        $this->assertCount(1, $results['conversations']);
        $this->assertSame('My regression chat', $results['conversations'][0]['title']);
    }

    public function test_skills_find_own_and_public_but_not_others_private(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $other = User::factory()->create(['is_admin' => false, 'name' => 'Other Author']);

        $own = Skill::create(['user_id' => $user->id, 'name' => 'My playwright skill', 'content' => '# x']);
        $public = Skill::create(['user_id' => $other->id, 'name' => 'Shared playwright skill', 'content' => '# x', 'is_public' => true]);
        Skill::create(['user_id' => $other->id, 'name' => 'Hidden playwright skill', 'content' => '# x', 'is_public' => false]);
        // A copy the other user installed and later made public — a shared
        // copy must not shadow the original.
        Skill::create(['user_id' => $other->id, 'name' => 'Installed playwright copy', 'content' => '# x', 'is_public' => true, 'copied_from_id' => $public->id]);

        $results = $this->searchAs($user, 'playwright skill');

        $this->assertSame(['My playwright skill', 'Shared playwright skill'], array_column($results['skills'], 'name'));

        $ownResult = collect($results['skills'])->firstWhere('id', $own->id);
        $this->assertTrue($ownResult['owner']);
        $this->assertNull($ownResult['author_name']);

        $publicResult = collect($results['skills'])->firstWhere('id', $public->id);
        $this->assertFalse($publicResult['owner']);
        $this->assertSame('Other Author', $publicResult['author_name']);
    }
}
