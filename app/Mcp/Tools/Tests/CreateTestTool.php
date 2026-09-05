<?php

namespace App\Mcp\Tools\Tests;

use App\Http\Requests\Api\StoreApiTestRequest;
use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\TestSuite;
use App\Services\PlaywrightCodeValidatorService;
use App\Services\ActivityLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateTestTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'create_test';

    protected string $description = 'Create a test (with its Playwright code) in a suite.';

    public function __construct(private readonly PlaywrightCodeValidatorService $validator) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
            'name' => $schema->string()->required()->description('Test name.'),
            'playwright_code' => $schema->string()->required()->description('The Playwright test code.'),
            'description' => $schema->string()->description('Test description.'),
            'uploaded_by' => $schema->string()->description('Who uploaded this test — must be an existing user\'s email address (users.email).'),
            'status' => $schema->string()->enum(['active', 'disabled'])->default('active')->description('Initial status.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $suite = TestSuite::findOrFail($request->validate(['suite_id' => 'required|integer|exists:test_suites,id'])['suite_id']);
        $this->authorizeSuite('edit', $suite);

        $data = $request->validate((new StoreApiTestRequest)->rules());

        $this->validator->validate($data['playwright_code']);

        $test = $suite->tests()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'uploaded_by' => $data['uploaded_by'] ?? null,
            'playwright_code' => $data['playwright_code'],
            'status' => $data['status'] ?? 'active',
        ]);

        ActivityLogger::log('test_created', Auth::user(), $suite, $test, ['name' => $test->name]);

        return Response::structured(['test' => $test->toArray()]);
    }
}
