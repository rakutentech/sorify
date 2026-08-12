<?php

namespace App\Mcp\Tools\Suites;

use App\Models\TestSuite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class DeleteSuiteTool extends Tool
{
    protected string $name = 'delete_suite';

    protected string $description = 'Delete a test suite and all of its tests and runs.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['suite_id' => 'required|integer|exists:test_suites,id']);

        TestSuite::findOrFail($data['suite_id'])->delete();

        return Response::structured(['deleted' => true, 'suite_id' => $data['suite_id']]);
    }
}
