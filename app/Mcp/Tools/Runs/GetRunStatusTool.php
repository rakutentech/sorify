<?php

namespace App\Mcp\Tools\Runs;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\TestRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetRunStatusTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'get_run_status';

    protected string $description = 'Lightweight poll for a test run\'s current status and counts. Prefer this over get_run for polling.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->integer()->required()->description('The test run ID.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['run_id' => 'required|integer|exists:test_runs,id']);

        $run = TestRun::findOrFail($data['run_id']);
        $this->authorizeSuite('view', $run->testSuite);

        return Response::structured([
            'status' => $run->status,
            'passed_count' => $run->passed_count,
            'failed_count' => $run->failed_count,
            'error_count' => $run->error_count,
            'total_tests' => $run->total_tests,
            'duration_ms' => $run->duration_ms,
        ]);
    }
}
