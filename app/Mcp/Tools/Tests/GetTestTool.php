<?php

namespace App\Mcp\Tools\Tests;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\Test;
use App\Models\TestSuite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetTestTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'get_test';

    protected string $description = 'Get a single test, including its Playwright code and recent run history.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
            'test_id' => $schema->integer()->required()->description('The test ID.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'suite_id' => 'required|integer|exists:test_suites,id',
            'test_id' => 'required|integer|exists:tests,id',
        ]);

        $this->authorizeSuite('view', TestSuite::findOrFail($data['suite_id']));

        $test = Test::where('test_suite_id', $data['suite_id'])->findOrFail($data['test_id']);

        $history = $test->testResults()
            ->with(['testRun:id,status,created_at', 'screenshots'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'duration_ms' => $r->duration_ms,
                'created_at' => $r->created_at,
                'run_id' => $r->test_run_id,
                'error_message' => $r->error_message,
                'error_stack' => $r->error_stack,
                'stdout' => $r->stdout,
                'screenshots' => $r->screenshots->map(fn ($s) => [
                    'id' => $s->id,
                    'filename' => $s->filename,
                    'label' => $s->label,
                    'taken_at_ms' => $s->taken_at_ms,
                    'url' => $s->url,
                ]),
            ]);

        return Response::structured([
            'test' => [
                'id' => $test->id,
                'test_suite_id' => $test->test_suite_id,
                'name' => $test->name,
                'description' => $test->description,
                'uploaded_by' => $test->uploaded_by, // validated as an existing user's email at write time
                'status' => $test->status,
                'playwright_code' => $test->playwright_code,
                'last_run_at' => $test->last_run_at?->toIso8601String(),
                'last_run_status' => $test->last_run_status,
                'created_at' => $test->created_at->toIso8601String(),
                'updated_at' => $test->updated_at->toIso8601String(),
            ],
            'history' => $history->values()->all(),
        ]);
    }
}
