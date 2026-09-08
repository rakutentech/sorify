<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\EphemeralReadinessChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_access_system_routes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/sorify/admin/system')->assertForbidden();
        $this->actingAs($user)->get('/sorify/admin/system/readiness')->assertForbidden();
        $this->actingAs($user)->put('/sorify/admin/system/mode', ['execution_mode' => 'local'])->assertForbidden();
        $this->actingAs($user)->post('/sorify/admin/system/build-image')->assertForbidden();
    }

    public function test_admin_can_view_system_page(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(EphemeralReadinessChecker::class, function ($mock) {
            $mock->shouldReceive('check')->andReturn([
                ['name' => 'docker_cli', 'status' => 'pass', 'message' => 'ok', 'blocking' => false],
            ]);
        });

        $this->actingAs($admin)
            ->get('/sorify/admin/system')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('mode', 'local')
                ->where('readiness.0.name', 'docker_cli')
                ->etc());
    }

    public function test_readiness_endpoint_returns_checks_json(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(EphemeralReadinessChecker::class, function ($mock) {
            $mock->shouldReceive('check')->with(false)->andReturn([
                ['name' => 'docker_cli', 'status' => 'pass', 'message' => 'ok', 'blocking' => false],
            ]);
        });

        $this->actingAs($admin)
            ->get('/sorify/admin/system/readiness')
            ->assertOk()
            ->assertJsonPath('checks.0.name', 'docker_cli');
    }

    public function test_readiness_fresh_param_forces_recheck(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(EphemeralReadinessChecker::class, function ($mock) {
            $mock->shouldReceive('check')->with(true)->andReturn([]);
        });

        $this->actingAs($admin)
            ->get('/sorify/admin/system/readiness?fresh=1')
            ->assertOk()
            ->assertJsonPath('checks', []);
    }

    public function test_admin_can_switch_to_local_mode(): void
    {
        $admin = User::factory()->admin()->create();
        Setting::set('execution_mode', 'ephemeral');

        $this->actingAs($admin)
            ->put('/sorify/admin/system/mode', ['execution_mode' => 'local'])
            ->assertOk()
            ->assertJsonPath('mode', 'local');

        $this->assertSame('local', Setting::get('execution_mode'));
    }

    public function test_switching_to_ephemeral_is_rejected_when_not_ready(): void
    {
        $admin = User::factory()->admin()->create();

        config()->set('sorify.execution.docker_binary', '/nonexistent/docker');

        $response = $this->actingAs($admin)
            ->put('/sorify/admin/system/mode', ['execution_mode' => 'ephemeral'])
            ->assertStatus(422);

        $checks = $response->json('checks');
        $this->assertNotEmpty($checks);
        $this->assertSame('fail', $checks[0]['status']);
        $this->assertNull(Setting::get('execution_mode'));
    }

    public function test_switching_to_ephemeral_succeeds_when_ready(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(EphemeralReadinessChecker::class, function ($mock) {
            $mock->shouldReceive('check')->with(true)->andReturn([
                ['name' => 'docker_cli', 'status' => 'pass', 'message' => 'ok', 'blocking' => false],
                ['name' => 'smoke', 'status' => 'pass', 'message' => 'ok', 'blocking' => false],
            ]);
            $mock->shouldReceive('isReady')->andReturn(true);
        });

        $this->actingAs($admin)
            ->put('/sorify/admin/system/mode', ['execution_mode' => 'ephemeral'])
            ->assertOk()
            ->assertJsonPath('mode', 'ephemeral');

        $this->assertSame('ephemeral', Setting::get('execution_mode'));
    }

    public function test_invalid_mode_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put('/sorify/admin/system/mode', ['execution_mode' => 'weird'])
            ->assertSessionHasErrors('execution_mode');
    }

    public function test_build_image_dispatches_job(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/sorify/admin/system/build-image')
            ->assertOk();

        Queue::assertPushed(\App\Jobs\BuildRunnerImageJob::class);
    }
}
