<?php

namespace App\Mcp\Tools\Suites;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\TestSuite;
use App\Services\ActivityLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class DeleteSuiteScheduleTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'delete_suite_schedule';

    protected string $description = 'Remove the cron schedule from a test suite.';

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
        $this->authorizeSuite('edit', $suite);

        $suite->schedule()->delete();

        ActivityLogger::log('schedule_updated', Auth::user(), $suite, null, ['action' => 'removed']);

        return Response::structured(['deleted' => true, 'suite_id' => $data['suite_id']]);
    }
}
