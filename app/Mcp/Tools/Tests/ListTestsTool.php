<?php

namespace App\Mcp\Tools\Tests;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\TestSuite;
use App\Support\TestSort;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListTestsTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'list_tests';

    protected string $description = 'List the tests in a test suite (without their Playwright code).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
            'search' => $schema->string()->description('Filter tests whose name or description contains this text.'),
            'sort' => $schema->string()->description('Sort order: latest (default), oldest, errors, status_active, status_disabled, duration_long, duration_short, or a run status (passed/failed/error/timeout/running/pending/cancelled/skipped).'),
            'status' => $schema->array()->items($schema->string()->enum(TestSort::RUN_STATUSES))->description('Only include tests whose latest run status is one of these (passed/failed/error/timeout/running/pending/cancelled/skipped).'),
            'per_page' => $schema->integer()->enum([10, 30, 50, 100])->default(50)->description('Results per page.'),
            'page' => $schema->integer()->min(1)->default(1)->description('Page number.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'suite_id' => 'required|integer|exists:test_suites,id',
            'search' => 'nullable|string',
            'sort' => 'nullable|string',
            'status' => 'nullable|array',
            'status.*' => 'string|in:'.implode(',', TestSort::RUN_STATUSES),
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:10,30,50,100',
        ]);

        $suite = TestSuite::findOrFail($data['suite_id']);
        $this->authorizeSuite('view', $suite);

        $search = $data['search'] ?? '';
        $perPage = $data['per_page'] ?? 50;

        $testsQuery = $suite->tests();

        if ($search !== '') {
            $testsQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        TestSort::filter($testsQuery, $data['status'] ?? []);
        TestSort::apply($testsQuery, $data['sort'] ?? '');

        $paginator = $testsQuery->paginate($perPage, page: $data['page'] ?? 1);

        $tests = collect($paginator->items())
            ->map(fn ($test) => $this->format($test))
            ->values()
            ->all();

        return Response::structured([
            'data' => $tests,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    private function format($test): array
    {
        return [
            'id' => $test->id,
            'test_suite_id' => $test->test_suite_id,
            'name' => $test->name,
            'description' => $test->description,
            'uploaded_by' => $test->uploaded_by, // validated as an existing user's email at write time
            'status' => $test->status,
            'last_run_at' => $test->last_run_at?->toIso8601String(),
            'last_run_status' => $test->last_run_status,
            'created_at' => $test->created_at->toIso8601String(),
            'updated_at' => $test->updated_at->toIso8601String(),
        ];
    }
}
