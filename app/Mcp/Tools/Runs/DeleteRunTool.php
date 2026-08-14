<?php

namespace App\Mcp\Tools\Runs;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\TestRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class DeleteRunTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'delete_run';

    protected string $description = 'Delete a test run and its results.';

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
        $this->authorizeSuite('delete', $run->testSuite);

        $run->delete();

        return Response::structured(['deleted' => true, 'run_id' => $data['run_id']]);
    }
}
