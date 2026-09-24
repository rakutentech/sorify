<?php

namespace Tests\Feature;

use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RunRedirectTest extends TestCase
{
    use RefreshDatabase;

    private function suiteWithTest(): array
    {
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $test = $suite->tests()->create(['name' => 'Test', 'playwright_code' => 'code', 'status' => 'active']);

        return [$suite, $test];
    }

    public function test_running_from_a_test_page_redirects_to_the_new_run(): void
    {
        Queue::fake();
        $user = User::factory()->admin()->create();
        [$suite, $test] = $this->suiteWithTest();

        $response = $this->actingAs($user)
            ->from("/sorify/suites/{$suite->id}/tests/{$test->id}")
            ->post(route('suites.runs.store', $suite), ['test_ids' => [$test->id]]);

        $run = $suite->testRuns()->first();
        $this->assertNotNull($run);
        $response->assertRedirect("/sorify/runs/{$run->id}");
    }

    public function test_running_from_a_test_page_with_query_string_redirects_to_the_new_run(): void
    {
        Queue::fake();
        $user = User::factory()->admin()->create();
        [$suite, $test] = $this->suiteWithTest();

        $response = $this->actingAs($user)
            ->from("/sorify/suites/{$suite->id}/tests/{$test->id}?tab=history")
            ->post(route('suites.runs.store', $suite), ['test_ids' => [$test->id]]);

        $run = $suite->testRuns()->first();
        $this->assertNotNull($run);
        $response->assertRedirect("/sorify/runs/{$run->id}");
    }

    public function test_running_from_the_suite_page_stays_in_place(): void
    {
        Queue::fake();
        $user = User::factory()->admin()->create();
        [$suite] = $this->suiteWithTest();

        $response = $this->actingAs($user)
            ->from("/sorify/suites/{$suite->id}")
            ->post(route('suites.runs.store', $suite));

        $this->assertNotNull($suite->testRuns()->first());
        $response->assertRedirect("/sorify/suites/{$suite->id}");
    }

    public function test_running_from_a_run_page_redirects_to_the_new_run(): void
    {
        Queue::fake();
        $user = User::factory()->admin()->create();
        [$suite, $test] = $this->suiteWithTest();

        $response = $this->actingAs($user)
            ->from('/sorify/runs/123')
            ->post(route('suites.runs.store', $suite), ['test_ids' => [$test->id]]);

        $run = $suite->testRuns()->first();
        $this->assertNotNull($run);
        $response->assertRedirect("/sorify/runs/{$run->id}");
    }
}
