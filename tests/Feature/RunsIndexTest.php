<?php

namespace Tests\Feature;

use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunsIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/sorify/runs')->assertRedirect('/sorify/login');
    }

    public function test_admin_sees_runs_from_every_suite(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $run = TestRun::create(['test_suite_id' => $suite->id, 'status' => 'completed']);

        $this->actingAs($admin)
            ->get('/sorify/runs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Runs/Index')
                ->where('runs.data.0.id', $run->id)
            );
    }

    public function test_non_member_does_not_see_runs_from_suites_they_cannot_view(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        TestRun::create(['test_suite_id' => $suite->id, 'status' => 'completed']);

        $this->actingAs($user)
            ->get('/sorify/runs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('runs.data', []));
    }
}
