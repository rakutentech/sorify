<?php

namespace Tests\Feature;

use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuiteBookmarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_view_access_can_bookmark_a_suite(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $suite->members()->attach($user->id, [
            'can_view' => true, 'can_edit' => false, 'can_delete' => false, 'can_run' => false,
        ]);

        $this->actingAs($user)
            ->post("/sorify/suites/{$suite->id}/bookmark")
            ->assertRedirect();

        $this->assertDatabaseHas('suite_bookmarks', ['test_suite_id' => $suite->id, 'user_id' => $user->id]);
    }

    public function test_user_without_view_access_cannot_bookmark_a_suite(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);

        $this->actingAs($user)
            ->post("/sorify/suites/{$suite->id}/bookmark")
            ->assertForbidden();

        $this->assertDatabaseMissing('suite_bookmarks', ['test_suite_id' => $suite->id, 'user_id' => $user->id]);
    }

    public function test_bookmarking_twice_does_not_error(): void
    {
        $user = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);

        $this->actingAs($user)->post("/sorify/suites/{$suite->id}/bookmark")->assertRedirect();
        $this->actingAs($user)->post("/sorify/suites/{$suite->id}/bookmark")->assertRedirect();

        $this->assertSame(1, $suite->bookmarkedBy()->where('users.id', $user->id)->count());
    }

    public function test_user_can_remove_a_bookmark(): void
    {
        $user = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $user->bookmarkedSuites()->attach($suite->id);

        $this->actingAs($user)
            ->delete("/sorify/suites/{$suite->id}/bookmark")
            ->assertRedirect();

        $this->assertDatabaseMissing('suite_bookmarks', ['test_suite_id' => $suite->id, 'user_id' => $user->id]);
    }

    public function test_bookmarks_index_only_lists_current_users_bookmarks(): void
    {
        $user = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        $mine = TestSuite::create(['name' => 'Mine', 'base_url' => 'https://a.example.com']);
        $theirs = TestSuite::create(['name' => 'Theirs', 'base_url' => 'https://b.example.com']);

        $user->bookmarkedSuites()->attach($mine->id);
        $other->bookmarkedSuites()->attach($theirs->id);

        $response = $this->actingAs($user)->get('/sorify/bookmarks');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Bookmarks/Index')
            ->has('suites.data', 1)
            ->where('suites.data.0.id', $mine->id));
    }

    public function test_bookmarks_index_hides_suites_the_user_lost_access_to(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $user->bookmarkedSuites()->attach($suite->id);

        $response = $this->actingAs($user)->get('/sorify/bookmarks');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Bookmarks/Index')
            ->has('suites.data', 0));
    }

    public function test_bookmarks_index_supports_search(): void
    {
        $user = User::factory()->admin()->create();
        $alpha = TestSuite::create(['name' => 'Alpha', 'base_url' => 'https://a.example.com']);
        $beta = TestSuite::create(['name' => 'Beta', 'base_url' => 'https://b.example.com']);
        $user->bookmarkedSuites()->attach([$alpha->id, $beta->id]);

        $response = $this->actingAs($user)->get('/sorify/bookmarks?search=Alpha');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('suites.data', 1)
            ->where('suites.data.0.id', $alpha->id));
    }
}
