<?php

namespace App\Mcp\Tools\Tests;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\TestSuite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListTestsTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'list_tests';

    protected string $description = 'List the tests in a test suite (without their Playwright code).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['suite_id' => 'required|integer|exists:test_suites,id']);

        $suite = TestSuite::findOrFail($data['suite_id']);
        $this->authorizeSuite('view', $suite);

        $tests = $suite->tests()->latest()->get()
            ->map(fn ($test) => $this->format($test))
            ->values()
            ->all();

        return Response::structured(['data' => $tests]);
    }

    private function format($test): array
    {
        return [
            'id' => $test->id,
            'test_suite_id' => $test->test_suite_id,
            'name' => $test->name,
            'description' => $test->description,
            'uploaded_by' => $test->uploaded_by, // validated as an existing user's email at write time
            'status' => $test->status,
            'last_run_at' => $test->last_run_at?->toIso8601String(),
            'last_run_status' => $test->last_run_status,
            'created_at' => $test->created_at->toIso8601String(),
            'updated_at' => $test->updated_at->toIso8601String(),
        ];
    }
}
