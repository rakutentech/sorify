<?php

namespace Tests\Feature;

use App\Mcp\Servers\SorifyServer;
use App\Mcp\Tools\Runs\TriggerRunTool;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\TestRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RunTriggerRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function suite(): TestSuite
    {
        return TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
    }

    public function test_dashboard_trigger_is_rate_limited_per_user(): void
    {
        Queue::fake();
        config(['sorify.run_trigger_rate_limit' => ['max_attempts' => 1, 'decay_seconds' => 60]]);

        $user = User::factory()->admin()->create();
        $suite = $this->suite();

        $this->actingAs($user)->post(route('suites.runs.store', $suite))->assertRedirect();
        $response = $this->actingAs($user)->post(route('suites.runs.store', $suite));

        $response->assertSessionHasErrors('run');
        $this->assertSame(1, $suite->testRuns()->count());
    }

    public function test_webhook_trigger_is_rate_limited_per_suite(): void
    {
        Queue::fake();
        config(['sorify.run_trigger_rate_limit' => ['max_attempts' => 1, 'decay_seconds' => 60]]);

        $suite = $this->suite();

        $this->postJson(route('webhooks.trigger', ['token' => $suite->webhook_token]))->assertStatus(202);
        $response = $this->postJson(route('webhooks.trigger', ['token' => $suite->webhook_token]));

        $response->assertStatus(429);
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertSame(1, $suite->testRuns()->count());
    }

    public function test_mcp_trigger_run_is_rate_limited_per_user(): void
    {
        Queue::fake();
        config(['sorify.run_trigger_rate_limit' => ['max_attempts' => 1, 'decay_seconds' => 60]]);

        $user = User::factory()->admin()->create();
        $suite = $this->suite();

        SorifyServer::actingAs($user)->tool(TriggerRunTool::class, ['suite_id' => $suite->id])->assertOk();

        SorifyServer::actingAs($user)
            ->tool(TriggerRunTool::class, ['suite_id' => $suite->id])
            ->assertHasErrors();

        $this->assertSame(1, $suite->testRuns()->count());
    }

    public function test_scheduled_trigger_bypasses_rate_limit(): void
    {
        Queue::fake();
        config(['sorify.run_trigger_rate_limit' => ['max_attempts' => 1, 'decay_seconds' => 60]]);

        $suite = $this->suite();
        $runs = app(TestRunService::class);

        $runs->triggerRun($suite, null, 'schedule');
        $runs->triggerRun($suite, null, 'schedule');

        $this->assertSame(2, $suite->testRuns()->count());
    }
}
