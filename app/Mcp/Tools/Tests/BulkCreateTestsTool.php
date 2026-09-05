<?php

namespace App\Mcp\Tools\Tests;

use App\Http\Requests\Api\BulkStoreTestRequest;
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

class BulkCreateTestsTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'bulk_create_tests';

    protected string $description = 'Create up to 100 tests (with Playwright code) in a suite in one call.';

    public function __construct(private readonly PlaywrightCodeValidatorService $validator) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
            'tests' => $schema->array()
                ->items($schema->object([
                    'name' => $schema->string()->required(),
                    'playwright_code' => $schema->string()->required(),
                    'description' => $schema->string(),
                    'uploaded_by' => $schema->string()->description('Must be an existing user\'s email address (users.email).'),
                    'status' => $schema->string()->enum(['active', 'disabled']),
                ]))
                ->min(1)
                ->max(100)
                ->required()
                ->description('The tests to create.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $suite = TestSuite::findOrFail($request->validate(['suite_id' => 'required|integer|exists:test_suites,id'])['suite_id']);
        $this->authorizeSuite('edit', $suite);

        $data = $request->validate((new BulkStoreTestRequest)->rules());

        $created = [];

        foreach ($data['tests'] as $item) {
            $this->validator->validate($item['playwright_code']);

            $test = $suite->tests()->create([
                'name' => $item['name'],
                'description' => $item['description'] ?? null,
                'uploaded_by' => $item['uploaded_by'] ?? null,
                'playwright_code' => $item['playwright_code'],
                'status' => $item['status'] ?? 'active',
            ]);

            $created[] = $test->toArray();
        }

        ActivityLogger::log('test_created', Auth::user(), $suite, null, ['count' => count($created)]);

        return Response::structured(['created' => count($created), 'tests' => $created]);
    }
}
