<?php

namespace App\Mcp\Tools\Tests;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\Test;
use App\Models\TestSuite;
use App\Services\ActivityLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class DeleteTestTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'delete_test';

    protected string $description = 'Delete a single test.';

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

        $this->authorizeSuite('delete', TestSuite::findOrFail($data['suite_id']));

        $test = Test::where('test_suite_id', $data['suite_id'])->findOrFail($data['test_id']);

        ActivityLogger::log('test_deleted', Auth::user(), $test->testSuite, null, ['name' => $test->name]);

        $test->delete();

        return Response::structured(['deleted' => true, 'test_id' => $data['test_id']]);
    }
}
