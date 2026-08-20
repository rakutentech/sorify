<?php

namespace Tests\Unit\Mcp\Tools;

use App\Mcp\Servers\SorifyServer;
use App\Mcp\Tools\Suites\BookmarkSuiteTool;
use App\Mcp\Tools\Suites\ListBookmarkedSuitesTool;
use App\Mcp\Tools\Suites\UnbookmarkSuiteTool;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookmarkToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bookmark_suite_requires_view_access(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $suite = TestSuite::create(['name' => 'Alpha', 'base_url' => 'https://a.example.com']);

        SorifyServer::actingAs($user)
            ->tool(BookmarkSuiteTool::class, ['suite_id' => $suite->id])
            ->assertHasErrors();

        $this->assertDatabaseMissing('suite_bookmarks', ['test_suite_id' => $suite->id, 'user_id' => $user->id]);
    }

    public function test_bookmark_suite_creates_a_bookmark(): void
    {
        $user = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Alpha', 'base_url' => 'https://a.example.com']);

        SorifyServer::actingAs($user)
            ->tool(BookmarkSuiteTool::class, ['suite_id' => $suite->id])
            ->assertOk();

        $this->assertDatabaseHas('suite_bookmarks', ['test_suite_id' => $suite->id, 'user_id' => $user->id]);
    }

    public function test_unbookmark_suite_removes_the_bookmark(): void
    {
        $user = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Alpha', 'base_url' => 'https://a.example.com']);
        $user->bookmarkedSuites()->attach($suite->id);

        SorifyServer::actingAs($user)
            ->tool(UnbookmarkSuiteTool::class, ['suite_id' => $suite->id])
            ->assertOk();

        $this->assertDatabaseMissing('suite_bookmarks', ['test_suite_id' => $suite->id, 'user_id' => $user->id]);
    }

    public function test_list_bookmarked_suites_returns_only_the_current_users_bookmarks(): void
    {
        $user = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        $mine = TestSuite::create(['name' => 'Mine', 'base_url' => 'https://a.example.com']);
        $theirs = TestSuite::create(['name' => 'Theirs', 'base_url' => 'https://b.example.com']);

        $user->bookmarkedSuites()->attach($mine->id);
        $other->bookmarkedSuites()->attach($theirs->id);

        SorifyServer::actingAs($user)
            ->tool(ListBookmarkedSuitesTool::class, [])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('meta.total', 1)
                ->where('data.0.id', $mine->id)
                ->etc());
    }
}
