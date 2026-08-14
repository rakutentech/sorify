<?php

namespace App\Mcp\Tools\Suites;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\TestSuite;
use App\Services\ReportingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListSuitesTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'list_suites';

    protected string $description = 'List test suites, optionally filtered by a search term, with pass-rate stats for each.';

    public function __construct(private readonly ReportingService $reporting) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Filter suites whose name or description contains this text.'),
            'per_page' => $schema->integer()->enum([10, 50, 100])->default(10)->description('Results per page.'),
            'page' => $schema->integer()->min(1)->default(1)->description('Page number.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'search' => 'nullable|string',
            'per_page' => 'nullable|integer|in:10,50,100',
            'page' => 'nullable|integer|min:1',
        ]);

        $perPage = $data['per_page'] ?? 10;
        $search = $data['search'] ?? '';

        $query = $this->visibleSuitesQuery()->withCount(['tests', 'testRuns'])->latest();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage, page: $data['page'] ?? 1);

        $suites = collect($paginator->items())
            ->map(fn (TestSuite $suite) => [
                ...$suite->toArray(),
                ...$this->reporting->suiteStats($suite),
            ])
            ->values()
            ->all();

        return Response::structured([
            'data' => $suites,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
