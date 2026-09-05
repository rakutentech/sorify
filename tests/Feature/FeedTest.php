<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/sorify/feed')->assertRedirect('/sorify/login');
    }

    public function test_legacy_runs_url_redirects_to_feed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/sorify/runs')
            ->assertRedirect('/sorify/feed');
    }

    public function test_admin_sees_activities_from_every_suite(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com', 'created_by' => $owner->id]);

        ActivityLogger::log('suite_created', $owner, $suite, $suite, ['name' => $suite->name]);

        $this->actingAs($admin)
            ->get('/sorify/feed')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Feed/Index')
                ->where('activities.data.0.type', 'suite_created')
                ->where('activities.data.0.suite.id', $suite->id)
            );
    }

    public function test_non_member_only_sees_global_activities(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $owner = User::factory()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com', 'created_by' => $owner->id]);

        ActivityLogger::log('suite_created', $owner, $suite, $suite, ['name' => $suite->name]);

        $newcomer = User::factory()->create();
        ActivityLogger::log('user_registered', $newcomer, null, $newcomer);

        $this->actingAs($user)
            ->get('/sorify/feed')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('activities.data', 1)
                ->where('activities.data.0.type', 'user_registered')
            );
    }

    public function test_member_with_view_access_sees_suite_activities(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $owner = User::factory()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com', 'created_by' => $owner->id]);

        $suite->members()->attach($user->id, ['can_view' => true, 'can_edit' => false, 'can_delete' => false, 'can_run' => false]);

        ActivityLogger::log('suite_updated', $owner, $suite, $suite, ['name' => $suite->name]);

        $this->actingAs($user)
            ->get('/sorify/feed')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('activities.data', 1)
                ->where('activities.data.0.type', 'suite_updated')
            );
    }

    public function test_view_only_user_sees_suite_activities(): void
    {
        $viewer = User::factory()->viewOnly()->create();
        $owner = User::factory()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com', 'created_by' => $owner->id]);

        $suite->members()->attach($viewer->id, ['can_view' => true, 'can_edit' => false, 'can_delete' => false, 'can_run' => false]);

        ActivityLogger::log('suite_updated', $owner, $suite, $suite, ['name' => $suite->name]);

        $this->actingAs($viewer)
            ->get('/sorify/feed')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('activities.data', 1));
    }

    public function test_member_without_view_privilege_does_not_see_suite_activities(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $owner = User::factory()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com', 'created_by' => $owner->id]);

        // Attached but can_view = false.
        $suite->members()->attach($user->id, ['can_view' => false, 'can_edit' => true, 'can_delete' => false, 'can_run' => false]);

        ActivityLogger::log('suite_updated', $owner, $suite, $suite, ['name' => $suite->name]);

        $this->actingAs($user)
            ->get('/sorify/feed')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('activities.data', []));
    }

    public function test_filters_by_type_suite_and_actor(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com', 'created_by' => $owner->id]);
        $otherSuite = TestSuite::create(['name' => 'Other', 'base_url' => 'https://example.com', 'created_by' => $other->id]);

        ActivityLogger::log('suite_created', $owner, $suite, $suite, ['name' => $suite->name]);
        ActivityLogger::log('suite_updated', $other, $otherSuite, $otherSuite, ['name' => $otherSuite->name]);

        // by type (no match)
        $this->actingAs($admin)
            ->get('/sorify/feed?type[]=user_registered')
            ->assertInertia(fn ($page) => $page->where('activities.data', []));

        // by suite
        $this->actingAs($admin)
            ->get("/sorify/feed?suite_id={$suite->id}")
            ->assertInertia(fn ($page) => $page
                ->has('activities.data', 1)
                ->where('activities.data.0.suite.id', $suite->id)
            );

        // by actor
        $this->actingAs($admin)
            ->get("/sorify/feed?actor_id={$owner->id}")
            ->assertInertia(fn ($page) => $page
                ->has('activities.data', 1)
                ->where('activities.data.0.actor.id', $owner->id)
            );
    }

    public function test_json_feed_endpoint_returns_paginated_activities(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);

        ActivityLogger::log('suite_created', $admin, $suite, $suite, ['name' => $suite->name]);

        $this->actingAs($admin)
            ->get('/sorify/feed', ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.0.type', 'suite_created')
            ->assertJsonPath('current_page', 1);
    }

    public function test_poll_returns_scoped_active_runs_and_latest_activity_id(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $hiddenSuite = TestSuite::create(['name' => 'Hidden', 'base_url' => 'https://example.com']);

        $suite->members()->attach($member->id, ['can_view' => true, 'can_edit' => false, 'can_delete' => false, 'can_run' => false]);

        $activeRun = $suite->testRuns()->create(['status' => 'running', 'triggered_by' => 'manual', 'total_tests' => 2, 'started_at' => now()]);
        $hiddenRun = $hiddenSuite->testRuns()->create(['status' => 'running', 'triggered_by' => 'manual', 'started_at' => now()]);

        $activity = ActivityLogger::log('suite_created', $admin, $suite, $suite, ['name' => $suite->name]);

        $activeRunIds = fn ($response) => collect($response->json('active_runs'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$activeRun->id, $hiddenRun->id], $activeRunIds($this->actingAs($admin)->getJson('/sorify/feed/poll')->assertOk()));
        $this->assertSame([$activeRun->id], $activeRunIds($this->actingAs($member)->getJson('/sorify/feed/poll')->assertOk()));
        $this->assertSame([], $activeRunIds($this->actingAs($outsider)->getJson('/sorify/feed/poll')->assertOk()));

        $this->actingAs($admin)
            ->getJson('/sorify/feed/poll')
            ->assertOk()
            ->assertJsonPath('latest_activity_id', $activity->id);
    }

    public function test_run_activity_subject_exposes_run_card_data(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com', 'created_by' => $admin->id]);
        $test = $suite->tests()->create(['name' => 'Failing test', 'playwright_code' => 'code', 'status' => 'active']);

        $run = $suite->testRuns()->create([
            'status' => 'completed',
            'triggered_by' => 'manual',
            'triggered_by_user_id' => $admin->id,
            'total_tests' => 2,
            'passed_count' => 1,
            'failed_count' => 1,
            'duration_ms' => 5000,
        ]);
        $run->testResults()->create(['test_id' => $test->id, 'status' => 'failed']);

        ActivityLogger::log('run_completed', $admin, $suite, $run, [
            'status' => 'completed',
            'triggered_by' => 'manual',
            'total_tests' => 2,
            'passed_count' => 1,
            'failed_count' => 1,
            'error_count' => 0,
            'duration_ms' => 5000,
        ]);

        $this->actingAs($admin)
            ->get('/sorify/feed')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('activities.data.0.type', 'run_completed')
                ->where('activities.data.0.subject.id', $run->id)
                ->where('activities.data.0.subject.status', 'completed')
                ->where('activities.data.0.subject.passed_count', 1)
                ->where('activities.data.0.subject.failed_tests.0', 'Failing test')
            );
    }

    public function test_secret_values_never_reach_the_activity_payload(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);

        // Attempt to smuggle secret-looking keys through the logger.
        $activity = ActivityLogger::log('variables_updated', $admin, $suite, null, [
            'count' => 3,
            'value' => 'super-secret-token',
            'key' => 'PASSWORD',
            'webhook_token' => 'whk_abc',
            'teams_webhook_url' => 'https://outlook.office.com/webhook/secret',
        ]);

        $this->assertSame(['count' => 3], $activity->payload);

        $this->actingAs($admin)
            ->get('/sorify/feed')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('activities.data.0.payload', ['count' => 3])
            );
    }

    public function test_activities_are_deleted_when_suite_is_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);

        ActivityLogger::log('suite_created', $admin, $suite, $suite, ['name' => $suite->name]);
        $this->assertSame(1, Activity::count());

        $suite->delete();

        $this->assertSame(0, Activity::count());
    }

    public function test_triggered_run_creates_activity(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com', 'created_by' => $user->id]);

        $run = app(\App\Services\TestRunService::class)->triggerRun($suite, null, 'manual', $user->id);

        $activity = Activity::where('type', 'run_triggered')->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->id, $activity->actor_id);
        $this->assertSame($suite->id, $activity->suite_id);
        $this->assertSame($run->id, $activity->subject_id);
        $this->assertSame(['triggered_by' => 'manual'], $activity->payload);
    }
}
