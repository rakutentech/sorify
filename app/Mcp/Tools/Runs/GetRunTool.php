<?php

namespace App\Mcp\Tools\Runs;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\TestRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetRunTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'get_run';

    protected string $description = 'Get a test run with its results and screenshots.';

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

        $run->load('testSuite:id,name');

        $results = $run->testResults()
            ->with(['test:id,name,test_suite_id', 'screenshots'])
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'test_name' => $r->test->name,
                'status' => $r->status,
                'duration_ms' => $r->duration_ms,
                'error_message' => $r->error_message,
                'error_stack' => $r->error_stack,
                'stdout' => $r->stdout,
                'screenshot_count' => $r->screenshots->count(),
                'screenshots' => $r->screenshots->map(fn ($s) => [
                    'id' => $s->id,
                    'filename' => $s->filename,
                    'label' => $s->label,
                    'taken_at_ms' => $s->taken_at_ms,
                    'url' => $s->url,
                ]),
            ]);

        return Response::structured([
            'run' => [...$run->toArray(), 'suite' => $run->testSuite],
            'results' => $results->values()->all(),
        ]);
    }
}
