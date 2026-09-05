<?php

namespace App\Mcp\Tools\Runs;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\TestRun;
use App\Services\TestRunService;
use App\Services\ActivityLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CancelRunTool extends Tool
{
    use AuthorizesSuiteAccess;

    public function __construct(private readonly TestRunService $runs) {}

    protected string $name = 'cancel_run';

    protected string $description = 'Cancel a pending or running test run.';

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
        $this->authorizeSuite('run', $run->testSuite);

        $run = $this->runs->cancel($run, Auth::user());

        return Response::structured(['run_id' => $run->id, 'status' => $run->status]);
    }
}
