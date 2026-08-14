<?php

namespace App\Mcp\Tools\Screenshots;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\Screenshot;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetScreenshotTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'get_screenshot';

    protected string $description = 'Get a screenshot as viewable inline image content.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'screenshot_id' => $schema->integer()->required()->description('The screenshot ID.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['screenshot_id' => 'required|integer|exists:screenshots,id']);

        $screenshot = Screenshot::findOrFail($data['screenshot_id']);
        $this->authorizeSuite('view', $screenshot->testResult->testRun->testSuite);

        if (! Storage::disk('screenshots')->exists($screenshot->path)) {
            return Response::error("Screenshot file not found at [{$screenshot->path}].");
        }

        return Response::fromStorage($screenshot->path, 'screenshots');
    }
}
