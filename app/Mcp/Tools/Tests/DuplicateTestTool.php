<?php

namespace App\Mcp\Tools\Tests;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\Test;
use App\Models\TestSuite;
use App\Services\TestSuiteDuplicationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class DuplicateTestTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'duplicate_test';

    protected string $description = 'Duplicate a single test — creates a copy in the same suite (or a target suite) with the same Playwright code, description, uploader and status. The new test\'s name defaults to "<original name> (copy)". Synchronous.';

    public function __construct(private readonly TestSuiteDuplicationService $duplication) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID the source test belongs to.'),
            'test_id' => $schema->integer()->required()->description('The test ID to duplicate.'),
            'target_suite_id' => $schema->integer()->description('Optional target suite ID. Defaults to the source test\'s suite.'),
            'name' => $schema->string()->description('Name for the new test. Defaults to "<original name> (copy)".'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'suite_id' => 'required|integer|exists:test_suites,id',
            'test_id' => 'required|integer|exists:tests,id',
            'target_suite_id' => 'nullable|integer|exists:test_suites,id',
            'name' => 'nullable|string|max:255',
        ]);

        $source = TestSuite::findOrFail($data['suite_id']);
        $this->authorizeSuite('view', $source);

        $target = isset($data['target_suite_id'])
            ? TestSuite::findOrFail($data['target_suite_id'])
            : $source;

        $this->authorizeSuite('edit', $target);

        $test = Test::where('test_suite_id', $source->id)->findOrFail($data['test_id']);

        $clone = $this->duplication->duplicateTest($test, $target, $data['name'] ?? null);

        return Response::structured([
            'test' => $clone->toArray(),
            'source_test_id' => $test->id,
            'source_suite_id' => $source->id,
            'target_suite_id' => $target->id,
        ]);
    }
}
