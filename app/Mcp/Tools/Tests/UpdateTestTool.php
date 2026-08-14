<?php

namespace App\Mcp\Tools\Tests;

use App\Http\Requests\StoreTestRequest;
use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\Test;
use App\Models\TestSuite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class UpdateTestTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'update_test';

    protected string $description = 'Update a test\'s metadata (name, description, uploaded_by). Use update_test_code to change its Playwright code, and toggle_test_status to enable/disable it.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
            'test_id' => $schema->integer()->required()->description('The test ID.'),
            'name' => $schema->string()->required()->description('Test name.'),
            'description' => $schema->string()->min(10)->required()->description('Test description (minimum 10 characters).'),
            'uploaded_by' => $schema->string()->description('Who uploaded this test — must be an existing user\'s email address (users.email).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $ids = $request->validate([
            'suite_id' => 'required|integer|exists:test_suites,id',
            'test_id' => 'required|integer|exists:tests,id',
        ]);

        $this->authorizeSuite('edit', TestSuite::findOrFail($ids['suite_id']));

        $test = Test::where('test_suite_id', $ids['suite_id'])->findOrFail($ids['test_id']);

        $data = $request->validate((new StoreTestRequest)->rules());

        $test->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'uploaded_by' => $data['uploaded_by'] ?? null,
        ]);

        return Response::structured(['test' => $test->toArray()]);
    }
}
