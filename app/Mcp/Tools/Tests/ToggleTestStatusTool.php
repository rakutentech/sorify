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

class ToggleTestStatusTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'toggle_test_status';

    protected string $description = 'Flip a test between active and disabled.';

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

        $this->authorizeSuite('edit', TestSuite::findOrFail($data['suite_id']));

        $test = Test::where('test_suite_id', $data['suite_id'])->findOrFail($data['test_id']);

        $test->update([
            'status' => $test->status === 'disabled' ? 'active' : 'disabled',
        ]);

        return Response::structured(['test_id' => $test->id, 'status' => $test->status]);
    }
}
