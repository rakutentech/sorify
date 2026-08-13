<?php

namespace App\Mcp\Tools\Suites;

use App\Http\Requests\Api\StoreSuiteRequest;
use App\Jobs\PruneSuiteHistoryJob;
use App\Models\TestSuite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class UpdateSuiteTool extends Tool
{
    protected string $name = 'update_suite';

    protected string $description = 'Update an existing test suite.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
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
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $suite = TestSuite::findOrFail($request->validate(['suite_id' => 'required|integer|exists:test_suites,id'])['suite_id']);

        $data = $request->validate((new StoreSuiteRequest)->rules());

        $suite->update($data);

        if ($suite->wasChanged('history_retention')) {
            PruneSuiteHistoryJob::dispatch($suite);
        }

        return Response::structured(['suite' => $suite->toArray()]);
    }
}
