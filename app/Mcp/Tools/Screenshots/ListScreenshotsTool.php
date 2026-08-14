<?php

namespace App\Mcp\Tools\Screenshots;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\TestResult;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListScreenshotsTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'list_screenshots';

    protected string $description = 'List the screenshots captured for a test result.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'result_id' => $schema->integer()->required()->description('The test result ID.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['result_id' => 'required|integer|exists:test_results,id']);

        $result = TestResult::findOrFail($data['result_id']);
        $this->authorizeSuite('view', $result->testRun->testSuite);

        $screenshots = $result->screenshots->map(fn ($s) => [
            'id' => $s->id,
            'filename' => $s->filename,
            'label' => $s->label,
            'taken_at_ms' => $s->taken_at_ms,
            'url' => $s->url,
        ]);

        return Response::structured(['data' => $screenshots->values()->all()]);
    }
}
