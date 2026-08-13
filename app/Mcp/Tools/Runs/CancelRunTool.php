<?php

namespace App\Mcp\Tools\Runs;

use App\Models\TestRun;
use App\Services\TestRunService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CancelRunTool extends Tool
{
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

        $run = $this->runs->cancel(TestRun::findOrFail($data['run_id']));

        return Response::structured(['run_id' => $run->id, 'status' => $run->status]);
    }
}
