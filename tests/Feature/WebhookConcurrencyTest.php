<?php

namespace Tests\Feature;

use App\Mcp\Servers\SorifyServer;
use App\Mcp\Tools\Runs\TriggerRunTool;
use App\Models\Screenshot;
use App\Models\TestResult;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebhookConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function suiteWithTests(): TestSuite
    {
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $suite->tests()->create(['name' => 'Test A', 'playwright_code' => '// noop', 'status' => 'active']);
        $suite->tests()->create(['name' => 'Test B', 'playwright_code' => '// noop', 'status' => 'active']);

        return $suite;
    }

    private function webhookUrl(TestSuite $suite, ?string $queryParams = null): string
    {
        $url = route('webhooks.trigger', ['token' => $suite->webhook_token]);

        return $queryParams !== null ? "{$url}?{$queryParams}" : $url;
    }

    // --- Concurrency: 409 while a CI run is in progress ---

    public function test_first_webhook_trigger_returns_202(): void
    {
        Queue::fake();
        config(['sorify.run_trigger_rate_limit' => ['max_attempts' => 100, 'decay_seconds' => 60]]);

        $suite = $this->suiteWithTests();

        $this->postJson($this->webhookUrl($suite))
            ->assertStatus(202)
            ->assertJsonStructure(['run_id', 'run_url', 'status', 'status_url']);

        $this->assertSame(1, $suite->testRuns()->count());
        $this->assertSame('ci', $suite->testRuns()->first()->triggered_by);
    }

    public function test_second_webhook_while_first_is_running_returns_409(): void
    {
        Queue::fake();
        config(['sorify.run_trigger_rate_limit' => ['max_attempts' => 100, 'decay_seconds' => 60]]);

        $suite = $this->suiteWithTests();

        $first = $this->postJson($this->webhookUrl($suite));
        $first->assertStatus(202);
        $firstRunId = $first->json('run_id');

        // With Queue::fake(), the first run stays in "running" status.
        $this->assertSame('running', $suite->testRuns()->find($firstRunId)->status);

        $second = $this->postJson($this->webhookUrl($suite));
        $second->assertStatus(409)
            ->assertJsonPath('run_id', $firstRunId)
            ->assertJsonStructure(['message', 'run_id', 'run_url', 'status_url']);

        // The status_url in the 409 must point at the FIRST run, so CI can poll it.
        $expectedStatusUrl = route('webhooks.status', ['token' => $suite->webhook_token, 'run' => $firstRunId]);
        $this->assertSame($expectedStatusUrl, $second->json('status_url'));

        // The run_url in the 409 must point at the FIRST run's dashboard page.
        $expectedRunUrl = route('runs.show', $firstRunId);
        $this->assertSame($expectedRunUrl, $second->json('run_url'));

        // No second run was created.
        $this->assertSame(1, $suite->testRuns()->count());
    }

    public function test_webhook_accepts_new_run_after_previous_ci_run_completes(): void
    {
        Queue::fake();
        config(['sorify.run_trigger_rate_limit' => ['max_attempts' => 100, 'decay_seconds' => 60]]);

        $suite = $this->suiteWithTests();

        $first = $this->postJson($this->webhookUrl($suite));
        $first->assertStatus(202);

        // Move the first run to a terminal state.
        $suite->testRuns()->find($first->json('run_id'))->update(['status' => 'completed', 'completed_at' => now()]);

        $second = $this->postJson($this->webhookUrl($suite));
        $second->assertStatus(202);

        $this->assertSame(2, $suite->testRuns()->count());
    }

    public function test_webhook_accepts_new_run_after_previous_ci_run_is_cancelled(): void
    {
        Queue::fake();
        config(['sorify.run_trigger_rate_limit' => ['max_attempts' => 100, 'decay_seconds' => 60]]);

        $suite = $this->suiteWithTests();

        $first = $this->postJson($this->webhookUrl($suite));
        $first->assertStatus(202);

        $suite->testRuns()->find($first->json('run_id'))->update(['status' => 'cancelled', 'completed_at' => now()]);

        $second = $this->postJson($this->webhookUrl($suite));
        $second->assertStatus(202);

        $this->assertSame(2, $suite->testRuns()->count());
    }

    // --- Manual / MCP triggers are NOT subject to CI concurrency ---

    public function test_dashboard_run_button_works_while_ci_run_is_in_progress(): void
    {
        Queue::fake();
        config(['sorify.run_trigger_rate_limit' => ['max_attempts' => 100, 'decay_seconds' => 60]]);

        $user = User::factory()->admin()->create();
        $suite = $this->suiteWithTests();

        // Start a CI run (stays "running" because Queue is faked).
        $this->postJson($this->webhookUrl($suite))->assertStatus(202);

        // Dashboard "Run all" should still work.
        $this->actingAs($user)
            ->post(route('suites.runs.store', $suite))
            ->assertRedirect();

        $this->assertSame(2, $suite->testRuns()->count());
        $this->assertSame('ci', $suite->testRuns()->orderBy('id')->first()->triggered_by);
        $this->assertSame('manual', $suite->testRuns()->latest('id')->first()->triggered_by);
    }

    public function test_mcp_trigger_run_works_while_ci_run_is_in_progress(): void
    {
        Queue::fake();
        config(['sorify.run_trigger_rate_limit' => ['max_attempts' => 100, 'decay_seconds' => 60]]);

        $user = User::factory()->admin()->create();
        $suite = $this->suiteWithTests();

        // Start a CI run.
        $this->postJson($this->webhookUrl($suite))->assertStatus(202);

        // MCP trigger_run should still work.
        SorifyServer::actingAs($user)
            ->tool(TriggerRunTool::class, ['suite_id' => $suite->id])
            ->assertOk();

        $this->assertSame(2, $suite->testRuns()->count());
        $this->assertSame('mcp', $suite->testRuns()->latest('id')->first()->triggered_by);
    }

    // --- Query param parsing (Workstream A) ---

    public function test_omitting_test_ids_runs_all_active_tests(): void
    {
        Queue::fake();
        config(['sorify.run_trigger_rate_limit' => ['max_attempts' => 100, 'decay_seconds' => 60]]);

        $suite = $this->suiteWithTests();

        $this->postJson($this->webhookUrl($suite))->assertStatus(202);

        $this->assertSame(2, $suite->testRuns()->first()->total_tests);
    }

    public function test_comma_separated_test_ids_runs_only_specified_tests(): void
    {
        Queue::fake();
        config(['sorify.run_trigger_rate_limit' => ['max_attempts' => 100, 'decay_seconds' => 60]]);

        $suite = $this->suiteWithTests();
        $firstTestId = $suite->tests()->first()->id;

        $this->postJson($this->webhookUrl($suite, "test_ids={$firstTestId}"))
            ->assertStatus(202);

        $this->assertSame(1, $suite->testRuns()->first()->total_tests);
    }

    public function test_single_test_id_query_param_works(): void
    {
        Queue::fake();
        config(['sorify.run_trigger_rate_limit' => ['max_attempts' => 100, 'decay_seconds' => 60]]);

        $suite = $this->suiteWithTests();
        $secondTestId = $suite->tests()->orderBy('id')->skip(1)->first()->id;

        $this->postJson($this->webhookUrl($suite, "test_ids={$secondTestId}"))
            ->assertStatus(202);

        $this->assertSame(1, $suite->testRuns()->first()->total_tests);
    }

    public function test_invalid_test_ids_query_param_returns_422(): void
    {
        config(['sorify.run_trigger_rate_limit' => ['max_attempts' => 100, 'decay_seconds' => 60]]);

        $suite = $this->suiteWithTests();

        $this->postJson($this->webhookUrl($suite, 'test_ids=abc'))
            ->assertStatus(422);

        $this->postJson($this->webhookUrl($suite, 'test_ids=1,abc,3'))
            ->assertStatus(422);

        $this->assertSame(0, $suite->testRuns()->count());
    }

    public function test_empty_test_ids_query_param_runs_all_active_tests(): void
    {
        Queue::fake();
        config(['sorify.run_trigger_rate_limit' => ['max_attempts' => 100, 'decay_seconds' => 60]]);

        $suite = $this->suiteWithTests();

        $this->postJson($this->webhookUrl($suite, 'test_ids='))
            ->assertStatus(202);

        $this->assertSame(2, $suite->testRuns()->first()->total_tests);
    }

    public function test_json_body_test_ids_is_ignored_in_favor_of_query_params(): void
    {
        Queue::fake();
        config(['sorify.run_trigger_rate_limit' => ['max_attempts' => 100, 'decay_seconds' => 60]]);

        $suite = $this->suiteWithTests();
        $firstTestId = $suite->tests()->first()->id;

        // JSON body test_ids is no longer read — only query params.
        // Here we send JSON body AND a query param; only the query param should be honored.
        $this->postJson(
            $this->webhookUrl($suite, "test_ids={$firstTestId}"),
            ['test_ids' => [999, 998]]
        )->assertStatus(202);

        $this->assertSame(1, $suite->testRuns()->first()->total_tests);
    }

    // --- Status endpoint still works during a 409 ---

    public function test_status_endpoint_works_for_in_progress_run(): void
    {
        Queue::fake();
        config(['sorify.run_trigger_rate_limit' => ['max_attempts' => 100, 'decay_seconds' => 60]]);

        $suite = $this->suiteWithTests();

        $first = $this->postJson($this->webhookUrl($suite));
        $first->assertStatus(202);
        $runId = $first->json('run_id');

        // Second request gets 409, but CI can still poll the first run's status.
        $this->postJson($this->webhookUrl($suite))->assertStatus(409);

        $this->getJson(route('webhooks.status', ['token' => $suite->webhook_token, 'run' => $runId]))
            ->assertOk()
            ->assertJsonPath('status', 'running');
    }

    // --- Terminal runs return a `response` block with test results ---

    private function terminalRunWithResults(TestSuite $suite, string $status): array
    {
        $run = $suite->testRuns()->create([
            'status' => $status,
            'triggered_by' => 'ci',
            'completed_at' => now(),
        ]);

        $test = $suite->tests()->create(['name' => 'Checkout flow', 'playwright_code' => '// noop', 'status' => 'active']);
        $result = TestResult::create([
            'test_run_id' => $run->id,
            'test_id' => $test->id,
            'status' => 'failed',
            'duration_ms' => 2500,
            'error_message' => 'expect(locator).toBeVisible() failed',
        ]);
        $screenshot = Screenshot::create([
            'test_result_id' => $result->id,
            'filename' => 'shot.png',
            'path' => 'shot.png',
            'label' => 'after-click',
            'taken_at_ms' => 100,
        ]);

        return [$run, $test, $result, $screenshot];
    }

    public function test_status_endpoint_includes_response_block_for_completed_run(): void
    {
        $suite = $this->suiteWithTests();
        [$run, $test, $result, $screenshot] = $this->terminalRunWithResults($suite, 'completed');

        $this->getJson(route('webhooks.status', ['token' => $suite->webhook_token, 'run' => $run->id]))
            ->assertOk()
            ->assertJsonPath('response.tests.0.test_id', $test->id)
            ->assertJsonPath('response.tests.0.name', 'Checkout flow')
            ->assertJsonPath('response.tests.0.status', 'failed')
            ->assertJsonPath('response.tests.0.duration_ms', 2500)
            ->assertJsonPath('response.tests.0.error_message', 'expect(locator).toBeVisible() failed')
            ->assertJsonPath('response.tests.0.screenshots.0.id', $screenshot->id)
            ->assertJsonPath('response.tests.0.screenshots.0.label', 'after-click')
            // CI-facing screenshot links use the token-guarded webhook route.
            ->assertJsonPath(
                'response.tests.0.screenshots.0.url',
                route('webhooks.screenshot', ['token' => $suite->webhook_token, 'screenshot' => $screenshot->id]),
            );
    }

    public function test_status_endpoint_includes_response_block_for_failed_run(): void
    {
        $suite = $this->suiteWithTests();
        [$run] = $this->terminalRunWithResults($suite, 'failed');

        $this->getJson(route('webhooks.status', ['token' => $suite->webhook_token, 'run' => $run->id]))
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonStructure(['response' => ['tests' => [['test_id', 'name', 'status', 'screenshots']]]]);
    }

    public function test_status_endpoint_omits_response_block_while_running(): void
    {
        $suite = $this->suiteWithTests();
        $run = $suite->testRuns()->create(['status' => 'running', 'triggered_by' => 'ci']);

        $this->getJson(route('webhooks.status', ['token' => $suite->webhook_token, 'run' => $run->id]))
            ->assertOk()
            ->assertJsonMissing(['response']);
    }

    // --- Token-guarded screenshot download ---

    public function test_screenshot_endpoint_serves_image_for_same_suite_token(): void
    {
        Storage::fake('screenshots');
        Storage::disk('screenshots')->put('shot.png', 'fake-image-bytes');

        $suite = $this->suiteWithTests();
        [, , , $screenshot] = $this->terminalRunWithResults($suite, 'completed');

        // BinaryFileResponse content is not readable in tests; OK plus the
        // 404 guards below (missing file, other suite) cover the endpoint.
        $this->get(route('webhooks.screenshot', ['token' => $suite->webhook_token, 'screenshot' => $screenshot->id]))
            ->assertOk();
    }

    public function test_screenshot_endpoint_serves_image_for_previous_suite_token(): void
    {
        Storage::fake('screenshots');
        Storage::disk('screenshots')->put('shot.png', 'fake-image-bytes');

        $suite = $this->suiteWithTests();
        [, , , $screenshot] = $this->terminalRunWithResults($suite, 'completed');

        // Superseded tokens stay active until explicitly deleted.
        $oldToken = $suite->webhook_token;
        $suite->regenerateWebhookToken();

        $this->get(route('webhooks.screenshot', ['token' => $oldToken, 'screenshot' => $screenshot->id]))
            ->assertOk();
    }

    public function test_screenshot_endpoint_404s_for_other_suite_screenshot(): void
    {
        Storage::fake('screenshots');
        Storage::disk('screenshots')->put('shot.png', 'fake-image-bytes');

        $suite = $this->suiteWithTests();
        [, , , $screenshot] = $this->terminalRunWithResults($suite, 'completed');

        $other = TestSuite::create(['name' => 'Other', 'base_url' => 'https://other.example.com']);

        $this->get(route('webhooks.screenshot', ['token' => $other->webhook_token, 'screenshot' => $screenshot->id]))
            ->assertNotFound();
    }

    public function test_screenshot_endpoint_404s_for_missing_file(): void
    {
        Storage::fake('screenshots');

        $suite = $this->suiteWithTests();
        [, , , $screenshot] = $this->terminalRunWithResults($suite, 'completed');

        $this->get(route('webhooks.screenshot', ['token' => $suite->webhook_token, 'screenshot' => $screenshot->id]))
            ->assertNotFound();
    }
}
