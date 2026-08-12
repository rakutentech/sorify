<?php

namespace Tests\Unit\Mcp\Tools;

use App\Mcp\Servers\SorifyServer;
use App\Mcp\Tools\Screenshots\GetScreenshotTool;
use App\Mcp\Tools\Screenshots\ListScreenshotsTool;
use App\Models\Screenshot;
use App\Models\TestResult;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScreenshotsToolsTest extends TestCase
{
    use RefreshDatabase;

    private function makeTestResult(): TestResult
    {
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $run = $suite->testRuns()->create(['status' => 'completed', 'triggered_by' => 'mcp']);
        $test = $suite->tests()->create(['name' => 'A', 'playwright_code' => 'code', 'status' => 'active']);

        return TestResult::create([
            'test_run_id' => $run->id,
            'test_id' => $test->id,
            'status' => 'passed',
        ]);
    }

    public function test_list_screenshots_returns_screenshots_for_a_result(): void
    {
        $user = User::factory()->create();
        $result = $this->makeTestResult();
        Screenshot::create([
            'test_result_id' => $result->id,
            'filename' => 'shot.png',
            'path' => 'shot.png',
            'label' => 'final',
            'taken_at_ms' => 100,
        ]);

        SorifyServer::actingAs($user)
            ->tool(ListScreenshotsTool::class, ['result_id' => $result->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->has('data', 1)->etc());
    }

    public function test_get_screenshot_returns_inline_image_content(): void
    {
        Storage::fake('screenshots');
        Storage::disk('screenshots')->put('shot.png', 'fake-image-bytes');

        $user = User::factory()->create();
        $result = $this->makeTestResult();
        $screenshot = Screenshot::create([
            'test_result_id' => $result->id,
            'filename' => 'shot.png',
            'path' => 'shot.png',
            'taken_at_ms' => 100,
        ]);

        SorifyServer::actingAs($user)
            ->tool(GetScreenshotTool::class, ['screenshot_id' => $screenshot->id])
            ->assertOk();
    }

    public function test_get_screenshot_errors_when_file_missing(): void
    {
        Storage::fake('screenshots');

        $user = User::factory()->create();
        $result = $this->makeTestResult();
        $screenshot = Screenshot::create([
            'test_result_id' => $result->id,
            'filename' => 'missing.png',
            'path' => 'missing.png',
            'taken_at_ms' => 100,
        ]);

        SorifyServer::actingAs($user)
            ->tool(GetScreenshotTool::class, ['screenshot_id' => $screenshot->id])
            ->assertHasErrors(['not found']);
    }
}
