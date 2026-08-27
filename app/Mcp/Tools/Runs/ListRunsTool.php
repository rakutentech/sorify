<?php

namespace App\Mcp\Tools\Runs;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Support\RunSort;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListRunsTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'list_runs';

    protected string $description = 'List test runs, optionally filtered by suite, test, or trigger source, with sort and pagination.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->description('Only include runs for this test suite.'),
            'test_id' => $schema->integer()->description('Only include runs that executed this test.'),
            'triggered_by' => $schema->string()->enum(['manual', 'mcp', 'ci', 'schedule'])->description('Only include runs triggered by this source.'),
            'sort' => $schema->string()->enum(RunSort::SORT_KEYS)->description('Sort field: '.implode(', ', RunSort::SORT_KEYS).' (default: run_date).'),
            'sort_dir' => $schema->string()->enum(['asc', 'desc'])->description('Sort direction (default: desc).'),
            'per_page' => $schema->integer()->enum([10, 30, 50, 100])->default(30)->description('Results per page.'),
            'page' => $schema->integer()->min(1)->default(1)->description('Page number.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'suite_id' => 'nullable|integer|exists:test_suites,id',
            'test_id' => 'nullable|integer|exists:tests,id',
            'triggered_by' => 'nullable|string|in:manual,mcp,ci,schedule',
            'sort' => 'nullable|string|in:'.implode(',', RunSort::SORT_KEYS),
            'sort_dir' => 'nullable|string|in:asc,desc',
            'per_page' => 'nullable|integer|in:10,30,50,100',
            'page' => 'nullable|integer|min:1',
        ]);

        $perPage = $data['per_page'] ?? 30;

        $query = TestRun::with([
            'testSuite:id,name',
            'triggeredByUser:id,name,email,avatar',
        ])->withCount('screenshots as screenshot_count');

        RunSort::apply($query, $data['sort'] ?? '', $data['sort_dir'] ?? 'desc');

        // Visibility: non-admins only see runs from suites they're a member of
        // with can_view=true. Mirrors the dashboard Runs index behavior.
        $user = Auth::user();
        if (! $user->is_admin) {
            $query->whereHas('testSuite.members', function ($q) use ($user) {
                $q->where('users.id', $user->id)
                    ->where('test_suite_user.can_view', true);
            });
        }

        if (isset($data['suite_id'])) {
            // Re-authorize view access to the filtered suite.
            $suite = TestSuite::findOrFail($data['suite_id']);
            $this->authorizeSuite('view', $suite);
            $query->where('test_suite_id', $data['suite_id']);
        }

        if (isset($data['test_id'])) {
            $query->whereHas('testResults', fn ($q) => $q->where('test_id', $data['test_id']));
        }

        if (isset($data['triggered_by'])) {
            $query->where('triggered_by', $data['triggered_by']);
        }

        $paginator = $query->paginate($perPage, page: $data['page'] ?? 1);

        $runs = collect($paginator->items())
            ->map(fn (TestRun $run) => [
                'id' => $run->id,
                'suite_id' => $run->test_suite_id,
                'suite_name' => $run->testSuite?->name,
                'status' => $run->status,
                'passed_count' => $run->passed_count,
                'failed_count' => $run->failed_count,
                'error_count' => $run->error_count,
                'total_tests' => $run->total_tests,
                'duration_ms' => $run->duration_ms,
                'screenshot_count' => $run->screenshot_count,
                'triggered_by' => $run->triggered_by,
                'triggered_by_user' => $run->triggeredByUser,
                'created_at' => $run->created_at->toIso8601String(),
                'completed_at' => $run->completed_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Response::structured([
            'data' => $runs,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
