<?php

namespace App\Mcp\Tools\Runs;

use App\Models\TestSuite;
use App\Services\TestRunService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class TriggerRunTool extends Tool
{
    public function __construct(private readonly TestRunService $runs) {}

    protected string $name = 'trigger_run';

    protected string $description = 'Queue a run of a test suite, optionally limited to specific tests.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
            'test_ids' => $schema->array()->items($schema->integer())->description('Limit the run to these test IDs; omit to run all active tests.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'suite_id' => 'required|integer|exists:test_suites,id',
            'test_ids' => 'nullable|array',
            'test_ids.*' => 'exists:tests,id',
        ]);

        $suite = TestSuite::findOrFail($data['suite_id']);

        $run = $this->runs->triggerRun($suite, $data['test_ids'] ?? null, 'mcp', Auth::id());

        return Response::structured(['run_id' => $run->id, 'status' => $run->status]);
    }
}
