<?php

namespace Tests\Unit;

use App\Models\Screenshot;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Services\TeamsNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TeamsNotificationScreenshotsTest extends TestCase
{
    use RefreshDatabase;

    private function makeRunWithScreenshots(int $maxScreenshots): TestRun
    {
        config(['sorify.teams_max_screenshots' => $maxScreenshots]);

        $suite = TestSuite::create([
            'name' => 'Suite',
            'base_url' => 'https://example.com',
            'teams_webhook_url' => 'https://outlook.office.com/webhook/fake',
            'teams_notify_on_success' => true,
            'teams_notify_on_failure' => true,
        ]);
        $run = $suite->testRuns()->create([
            'status' => 'completed',
            'triggered_by' => 'manual',
            'passed_count' => 1,
            'failed_count' => 1,
        ]);
        $passedTest = $suite->tests()->create(['name' => 'Passing', 'playwright_code' => 'code', 'status' => 'active']);
        $failedTest = $suite->tests()->create(['name' => 'Failing', 'playwright_code' => 'code', 'status' => 'active']);

        $passedResult = TestResult::create(['test_run_id' => $run->id, 'test_id' => $passedTest->id, 'status' => 'passed']);
        $failedResult = TestResult::create(['test_run_id' => $run->id, 'test_id' => $failedTest->id, 'status' => 'failed']);

        Storage::disk('screenshots')->put('passed.png', 'bytes');
        Storage::disk('screenshots')->put('failed.png', 'bytes');

        Screenshot::create(['test_result_id' => $passedResult->id, 'filename' => 'passed.png', 'path' => 'passed.png', 'taken_at_ms' => 1]);
        Screenshot::create(['test_result_id' => $failedResult->id, 'filename' => 'failed.png', 'path' => 'failed.png', 'taken_at_ms' => 1]);

        return $run;
    }

    public function test_it_includes_screenshots_from_passing_runs_too(): void
    {
        Storage::fake('screenshots');
        Http::fake();

        $run = $this->makeRunWithScreenshots(maxScreenshots: 3);

        app(TeamsNotificationService::class)->notifyRunCompleted($run);

        Http::assertSent(function ($request) {
            $body = $request['attachments'][0]['content']['body'];
            $actionSet = collect($body)->firstWhere('type', 'ActionSet');

            if (! $actionSet) {
                return false;
            }

            $urls = collect($actionSet['actions'])->pluck('url');

            return $urls->count() === 2
                && $urls->contains(fn ($url) => str_contains($url, 'screenshots'))
                && collect($actionSet['actions'])->every(fn ($action) => $action['type'] === 'Action.OpenUrl');
        });
    }

    public function test_it_prioritizes_failed_screenshots_when_capped(): void
    {
        Storage::fake('screenshots');
        Http::fake();

        $run = $this->makeRunWithScreenshots(maxScreenshots: 1);

        app(TeamsNotificationService::class)->notifyRunCompleted($run);

        Http::assertSent(function ($request) {
            $body = $request['attachments'][0]['content']['body'];
            $actionSet = collect($body)->firstWhere('type', 'ActionSet');

            if (! $actionSet) {
                return false;
            }

            return count($actionSet['actions']) === 1
                && $actionSet['actions'][0]['title'] === 'Failing';
        });
    }
}
