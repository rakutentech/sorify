<?php

namespace App\Mcp\Tools\Suites;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\TestSuite;
use App\Services\ReportingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetSuiteTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'get_suite';

    protected string $description = 'Get a test suite with its stats, tests, and recent runs.';

    public function __construct(private readonly ReportingService $reporting) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'suite_id' => 'required|integer|exists:test_suites,id',
        ]);

        $suite = TestSuite::findOrFail($data['suite_id']);
        $this->authorizeSuite('view', $suite);

        $suite->load(['proxyRules', 'variables', 'cookies']);

        return Response::structured([
            'suite' => $suite->toArray(),
            'stats' => $this->reporting->suiteStats($suite),
            'tests' => $suite->tests()->latest()->get()->toArray(),
            'recent_runs' => $suite->testRuns()->latest()->limit(10)->get()->toArray(),
        ]);
    }
}
