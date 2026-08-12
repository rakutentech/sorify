<?php

namespace App\Mcp\Tools\Tests;

use App\Models\TestSuite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class BulkDeleteTestsTool extends Tool
{
    protected string $name = 'bulk_delete_tests';

    protected string $description = 'Delete multiple tests from a suite at once.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
            'test_ids' => $schema->array()->items($schema->integer())->min(1)->required()->description('IDs of the tests to delete.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'suite_id' => 'required|integer|exists:test_suites,id',
            'test_ids' => 'required|array|min:1',
            'test_ids.*' => 'exists:tests,id',
        ]);

        $suite = TestSuite::findOrFail($data['suite_id']);

        $deletedCount = $suite->tests()->whereIn('id', $data['test_ids'])->delete();

        return Response::structured(['deleted_count' => $deletedCount]);
    }
}
