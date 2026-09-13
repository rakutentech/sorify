<?php

namespace App\Mcp\Tools\Agent;

use App\Models\TestSuite;
use App\Services\Agent\AgentBrowserService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class BrowserMapTool extends Tool
{
    protected string $name = 'browser_map';

    protected string $description = 'Load a URL in a real browser (with the suite\'s cookies, proxy, and browser settings) and return the page title plus a Playwright accessibility snapshot (aria snapshot) of all interactive elements. Use this to understand page structure before writing Playwright regression tests.';

    public function __construct(private readonly AgentBrowserService $browser) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID whose cookies/proxy/browser settings to use.'),
            'url' => $schema->string()->required()->description('The absolute http(s) URL to load and map.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'suite_id' => ['required', 'integer', 'exists:test_suites,id'],
            'url' => ['required', 'string', 'max:2048'],
        ]);

        $suite = TestSuite::findOrFail($data['suite_id']);

        try {
            return Response::structured($this->browser->map($suite, $data['url']));
        } catch (\InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        } catch (\RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }
}
