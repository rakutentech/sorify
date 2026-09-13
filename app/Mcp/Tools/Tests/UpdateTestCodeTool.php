<?php

namespace App\Mcp\Tools\Tests;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Mcp\Tools\Concerns\ResolvesAiModel;
use App\Models\Test;
use App\Models\TestSuite;
use App\Services\ActivityLogger;
use App\Services\PlaywrightCodeValidatorService;
use App\Services\TestCodeVersionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class UpdateTestCodeTool extends Tool
{
    use AuthorizesSuiteAccess;
    use ResolvesAiModel;

    protected string $name = 'update_test_code';

    protected string $description = 'Replace a test\'s Playwright code. Reactivates the test (sets status back to active) on success. The previous code is kept as a restorable version.';

    public function __construct(
        private readonly PlaywrightCodeValidatorService $validator,
        private readonly TestCodeVersionService $versions,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
            'test_id' => $schema->integer()->required()->description('The test ID.'),
            'playwright_code' => $schema->string()->required()->description('The new Playwright test code.'),
            'ai_model' => $schema->string()->description('If you are an AI writing this code, your model name (e.g. "claude-sonnet-4-5"). Recorded as the author of the code.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'suite_id' => 'required|integer|exists:test_suites,id',
            'test_id' => 'required|integer|exists:tests,id',
            'playwright_code' => 'required|string|min:10',
            'ai_model' => 'nullable|string|max:255',
        ]);

        $this->authorizeSuite('edit', TestSuite::findOrFail($data['suite_id']));

        $test = Test::where('test_suite_id', $data['suite_id'])->findOrFail($data['test_id']);

        $this->validator->validate($data['playwright_code']);

        $this->versions->updateCode(
            $test,
            $data['playwright_code'],
            $this->codeSourceOf($request),
            Auth::id(),
            $this->aiModelOf($request, $data['ai_model'] ?? null),
        );

        ActivityLogger::log('test_code_updated', Auth::user(), $test->testSuite, $test, [
            'name' => $test->name,
            'code_source' => $test->code_source,
            'ai_model' => $test->code_ai_model,
        ]);

        return Response::structured(['test' => $test->toArray()]);
    }
}
