<?php

namespace App\Mcp\Tools\Suites;

use App\Http\Requests\Api\StoreSuiteRequest;
use App\Models\TestSuite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateSuiteTool extends Tool
{
    protected string $name = 'create_suite';

    protected string $description = 'Create a new test suite.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Suite name.'),
            'description' => $schema->string()->description('Suite description.'),
            'base_url' => $schema->string()->description('Base URL the suite\'s tests run against.'),
            'browser' => $schema->string()->enum(['chromium', 'firefox', 'webkit'])->description('Playwright browser to use.'),
            'headless' => $schema->boolean()->description('Whether to run headless.'),
            'playwright_proxy' => $schema->string()->description('HTTP proxy server to use, if any (e.g. http://proxy:8080). Ignored when playwright_proxy_pac is set.'),
            'playwright_proxy_pac' => $schema->string()->description('Raw PAC (Proxy Auto-Config) script content. Takes priority over playwright_proxy when both are set.'),
            'history_retention' => $schema->integer()->enum([3, 5, 10])->description('Number of past runs to keep per test (3, 5, or 10). Older results and screenshots are pruned automatically. Defaults to 5.'),
            'timeout_ms' => $schema->integer()->enum([10000, 30000, 60000, 120000])->description('Per-action timeout in milliseconds (10000, 30000, 60000, or 120000). Defaults to 30000.'),
            'take_screenshot' => $schema->boolean()->description('Whether to capture screenshots during test runs. Disable for faster runs. Defaults to true.'),
            'teams_webhook_url' => $schema->string()->description('MS Teams incoming webhook URL to notify when runs complete.'),
            'teams_webhook_proxy' => $schema->string()->description('HTTP proxy to use when posting to the Teams webhook, if any.'),
            'teams_notify_on_success' => $schema->boolean()->description('Whether to notify Teams when a run succeeds.'),
            'teams_notify_on_failure' => $schema->boolean()->description('Whether to notify Teams when a run fails.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate((new StoreSuiteRequest)->rules());

        $suite = TestSuite::create([...$data, 'created_by' => Auth::id()]);

        if (Auth::id()) {
            $suite->members()->attach(Auth::id(), [
                'can_view' => true,
                'can_edit' => true,
                'can_delete' => true,
                'can_run' => true,
            ]);
        }

        return Response::structured(['suite' => $suite->toArray()]);
    }
}
