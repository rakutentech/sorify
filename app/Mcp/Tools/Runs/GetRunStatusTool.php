<?php

namespace App\Mcp\Tools\Runs;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\TestRun;
use App\Services\TestRunService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetRunStatusTool extends Tool
{
    use AuthorizesSuiteAccess;

    public function __construct(private readonly TestRunService $runs) {}

    protected string $name = 'get_run_status';

    protected string $description = 'Lightweight poll for a test run\'s current status and counts. Once the run is completed, failed or cancelled, a `response` block lists every test result with screenshot links.';

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

        // Shared payload: status, counts, coverage, plus a `response` block
        // with per-test results and screenshot links once the run is
        // terminal. No webhook token here, so screenshot links point at the
        // dashboard's session-authenticated route.
        return Response::structured($this->runs->statusPayload($run));
    }
}
