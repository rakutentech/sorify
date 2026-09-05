<?php

namespace Tests\Feature;

use App\Models\TestSuite;
use App\Services\TestRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TeamsNotifyOnStartTest extends TestCase
{
    use RefreshDatabase;

    private function suiteWithTest(array $attrs = []): TestSuite
    {
        $suite = TestSuite::create(array_merge([
            'name' => 'Suite',
            'base_url' => 'https://example.com',
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_start' => true,
        ], $attrs));

        $suite->tests()->create(['name' => 'Test', 'playwright_code' => '// noop', 'status' => 'active']);

        return $suite;
    }

    public function test_run_start_posts_a_teams_notification(): void
    {
        Queue::fake();
        Http::fake(['outlook.webhook.example/*' => Http::response([], 200)]);

        $suite = $this->suiteWithTest();

        app(TestRunService::class)->triggerRun($suite, null, 'manual');

        Http::assertSent(function ($request) {
            $payload = json_encode($request->data());

            return str_starts_with($request->url(), 'https://outlook.webhook.example')
                && str_contains($payload, 'Run started')
                // Same actions as the completion card: suite link first, then run.
                && str_contains($payload, '"title":"View Test Suite"')
                && str_contains($payload, '"title":"View Run"');
        });
    }

    public function test_no_start_notification_when_disabled(): void
    {
        Queue::fake();
        Http::fake();

        $suite = $this->suiteWithTest(['teams_notify_on_start' => false]);

        app(TestRunService::class)->triggerRun($suite, null, 'manual');

        Http::assertNothingSent();
    }

    public function test_no_start_notification_without_webhook_url(): void
    {
        Queue::fake();
        Http::fake();

        $suite = $this->suiteWithTest(['teams_webhook_url' => null]);

        app(TestRunService::class)->triggerRun($suite, null, 'manual');

        Http::assertNothingSent();
    }

    public function test_suite_without_tests_does_not_notify_on_start(): void
    {
        Queue::fake();
        Http::fake();

        $suite = TestSuite::create([
            'name' => 'Empty',
            'base_url' => 'https://example.com',
            'teams_webhook_url' => 'https://outlook.webhook.example/abc',
            'teams_notify_on_start' => true,
        ]);

        app(TestRunService::class)->triggerRun($suite, null, 'manual');

        Http::assertNothingSent();
    }
}
