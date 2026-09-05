<?php

namespace Tests\Feature;

use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookRegenerationTest extends TestCase
{
    use RefreshDatabase;

    private function suite(): TestSuite
    {
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $suite->tests()->create(['name' => 'Test A', 'playwright_code' => '// noop', 'status' => 'active']);

        return $suite;
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    // --- Regenerate keeps the old URL active ---

    public function test_regenerate_keeps_old_token_active_until_deleted(): void
    {
        Queue::fake();
        config(['sorify.run_trigger_rate_limit' => ['max_attempts' => 100, 'decay_seconds' => 60]]);

        $suite = $this->suite();
        $oldToken = $suite->webhook_token;

        $this->actingAs($this->admin())
            ->post("/sorify/suites/{$suite->id}/webhook/regenerate")
            ->assertRedirect();

        $suite->refresh();
        $this->assertNotSame($oldToken, $suite->webhook_token);
        $this->assertTrue($suite->hasPreviousWebhookToken($oldToken));

        // The old URL still triggers runs.
        $this->postJson(route('webhooks.trigger', ['token' => $oldToken]))->assertStatus(202);
        $this->assertSame(1, $suite->testRuns()->count());

        // Move the first run to a terminal state so a second can start.
        $suite->testRuns()->first()->update(['status' => 'completed', 'completed_at' => now()]);

        // The new URL works too, and the response's status_url uses the
        // token the caller actually used.
        $response = $this->postJson(route('webhooks.trigger', ['token' => $suite->webhook_token]));
        $response->assertStatus(202);
        $this->assertSame(
            route('webhooks.status', ['token' => $suite->webhook_token, 'run' => $response->json('run_id')]),
            $response->json('status_url'),
        );
    }

    public function test_deleted_old_token_stops_working(): void
    {
        Queue::fake();
        config(['sorify.run_trigger_rate_limit' => ['max_attempts' => 100, 'decay_seconds' => 60]]);

        $suite = $this->suite();
        $oldToken = $suite->webhook_token;

        $this->actingAs($this->admin())->post("/sorify/suites/{$suite->id}/webhook/regenerate");

        $this->actingAs($this->admin())
            ->delete("/sorify/suites/{$suite->id}/webhook/{$oldToken}")
            ->assertRedirect();

        $suite->refresh();
        $this->assertFalse($suite->hasPreviousWebhookToken($oldToken));
        $this->assertSame(1, $suite->webhookTokenCount());

        $this->postJson(route('webhooks.trigger', ['token' => $oldToken]))->assertStatus(404);
    }

    // --- Cap of MAX_WEBHOOK_TOKENS ---

    public function test_regenerate_is_refused_at_the_webhook_limit(): void
    {
        $suite = $this->suite();
        $admin = $this->admin();

        // 1 current + 4 regenerations = 5 tokens total: at the cap.
        for ($i = 0; $i < TestSuite::MAX_WEBHOOK_TOKENS - 1; $i++) {
            $this->actingAs($admin)->post("/sorify/suites/{$suite->id}/webhook/regenerate")->assertRedirect();
        }

        $suite->refresh();
        $this->assertSame(TestSuite::MAX_WEBHOOK_TOKENS, $suite->webhookTokenCount());
        $currentAtCap = $suite->webhook_token;
        $previousAtCap = $suite->previous_webhook_tokens;

        $this->actingAs($admin)
            ->post("/sorify/suites/{$suite->id}/webhook/regenerate")
            ->assertRedirect()
            ->assertSessionHas('flash.error');

        $suite->refresh();
        $this->assertSame($currentAtCap, $suite->webhook_token);
        $this->assertSame($previousAtCap, $suite->previous_webhook_tokens);

        // Deleting one old URL frees a slot again.
        $this->actingAs($admin)->delete("/sorify/suites/{$suite->id}/webhook/{$previousAtCap[0]}")->assertRedirect();

        $this->actingAs($admin)->post("/sorify/suites/{$suite->id}/webhook/regenerate")->assertRedirect();

        $suite->refresh();
        $this->assertSame(TestSuite::MAX_WEBHOOK_TOKENS, $suite->webhookTokenCount());
        $this->assertNotSame($currentAtCap, $suite->webhook_token);
    }

    // --- Show page exposes the old URLs + limit flag ---

    public function test_show_page_lists_previous_webhooks(): void
    {
        $suite = $this->suite();
        $oldToken = $suite->webhook_token;

        $this->actingAs($this->admin())->post("/sorify/suites/{$suite->id}/webhook/regenerate");

        $response = $this->actingAs($this->admin())->get("/sorify/suites/{$suite->id}");
        $response->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('previousWebhooks.0.token', $oldToken)
            ->where('previousWebhooks.0.url', route('webhooks.trigger', ['token' => $oldToken]))
            ->where('webhookLimitReached', false)
            ->etc());
    }

    public function test_current_token_cannot_be_deleted_via_the_webhook_delete_route(): void
    {
        $suite = $this->suite();
        $current = $suite->webhook_token;

        $this->actingAs($this->admin())
            ->delete("/sorify/suites/{$suite->id}/webhook/{$current}")
            ->assertRedirect()
            ->assertSessionHas('flash.error');

        $suite->refresh();
        $this->assertSame($current, $suite->webhook_token);
    }
}
