<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTestSuiteRequest;
use App\Jobs\PruneSuiteHistoryJob;
use App\Models\Test;
use App\Models\TestResult;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\ReportingService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TestSuiteController extends Controller
{
    private const RUN_STATUSES = ['passed', 'failed', 'error', 'timeout', 'running', 'pending', 'cancelled', 'skipped'];
    private const ERROR_STATUSES = ['failed', 'error', 'timeout'];

    public function __construct(private readonly ReportingService $reporting) {}

    public function index(Request $request): Response
    {
        $search   = request()->string('search')->toString();
        $perPage  = (int) request()->input('per_page', 30);
        $perPage  = in_array($perPage, [10, 30, 50, 100]) ? $perPage : 30;

        $query = TestSuite::withCount(['tests', 'testRuns', 'proxyRules'])->with(['schedule', 'members:id,name,email'])->latest();

        if (!$request->user()->is_admin) {
            $query->whereHas('members', function ($q) use ($request) {
                $q->where('users.id', $request->user()->id)
                  ->where('test_suite_user.can_view', true);
            });
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage)->withQueryString();

        $paginator->through(function ($s) {
            $data = array_merge($s->toArray(), $this->reporting->suiteStats($s));
            unset($data['webhook_token'], $data['teams_webhook_url']);
            $data['has_teams_webhook'] = (bool) $s->teams_webhook_url;

            return $data;
        });

        return Inertia::render('TestSuites/Index', [
            'suites'   => $paginator,
            'filters'  => ['search' => $search, 'per_page' => $perPage],
            'can'      => ['create' => $request->user()->can('create', TestSuite::class)],
        ]);
    }

    public function store(StoreTestSuiteRequest $request)
    {
        $this->authorize('create', TestSuite::class);

        $data = $request->validated();
        $proxyRules = $data['proxy_rules'] ?? null;
        unset($data['proxy_rules']);

        $suite = TestSuite::create([
            ...$data,
            'created_by' => $request->user()?->id,
        ]);

        if ($proxyRules) {
            $suite->proxyRules()->createMany($proxyRules);
        }

        $suite->members()->attach($request->user()->id, [
            'can_view'   => true,
            'can_edit'   => true,
            'can_delete' => true,
            'can_run'    => true,
        ]);

        return redirect(route('suites.show', $suite, absolute: false));
    }

    public function show(Request $request, TestSuite $suite): Response
    {
        $this->authorize('view', $suite);

        $suite->load('createdBy:id,name,email', 'schedule', 'members:id,name,email', 'proxyRules');

        $privileges = $suite->privilegesFor($request->user());
        $canManageUsers = $privileges['edit'];

        $search  = $request->string('search')->toString();
        $sort    = $request->string('sort')->toString();
        $perPage = (int) $request->input('per_page', 30);
        $perPage = in_array($perPage, [10, 30, 50, 100]) ? $perPage : 30;

        $members = [];
        $candidates = [];

        if ($canManageUsers) {
            $members = $suite->members()
                ->orderBy('users.name')
                ->get(['users.id', 'users.name', 'users.email', 'users.is_view_only'])
                ->map(fn (User $u) => [
                    'id'           => $u->id,
                    'name'         => $u->name,
                    'email'        => $u->email,
                    'is_view_only' => (bool) $u->is_view_only,
                    'can_view'     => (bool) $u->pivot->can_view,
                    'can_edit'     => (bool) $u->pivot->can_edit,
                    'can_delete'   => (bool) $u->pivot->can_delete,
                    'can_run'      => (bool) $u->pivot->can_run,
                ]);

            $candidates = User::whereNotIn('id', $suite->members()->pluck('users.id'))
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'is_view_only']);
        }

        $testsQuery = $suite->tests()
            ->with(['testResults' => fn ($query) => $query->latest()->limit(10)->with('screenshots')]);

        if ($search !== '') {
            $testsQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $this->applyTestSort($testsQuery, $sort);

        $tests = $testsQuery->paginate($perPage)->withQueryString();

        $tests->through(function (Test $test) {
            $data = $test->toArray();
            $data['recent_runs'] = $test->testResults->map(fn (TestResult $result) => [
                'run_id'      => $result->test_run_id,
                'status'      => $result->status,
                'created_at'  => $result->created_at,
                'duration_ms' => $result->duration_ms,
                'screenshots' => $result->screenshots->map(fn ($s) => [
                    'id'          => $s->id,
                    'filename'    => $s->filename,
                    'label'       => $s->label,
                    'taken_at_ms' => $s->taken_at_ms,
                    'url'         => $s->url,
                ]),
            ]);
            $data['current_status'] = $data['recent_runs'][0]['status'] ?? $test->last_run_status;
            unset($data['test_results']);

            return $data;
        });

        return Inertia::render('TestSuites/Show', [
            'suite'      => $suite,
            'stats'      => $this->reporting->suiteStats($suite),
            'tests'      => $tests,
            'filters'    => ['search' => $search, 'per_page' => $perPage, 'sort' => $sort],
            'recentRuns' => $suite->testRuns()
                ->with(['triggeredByUser:id,name,email', 'testResults.screenshots'])
                ->latest()
                ->limit(10)
                ->get()
                ->map(function ($run) {
                    $data = $run->toArray();
                    $data['screenshots'] = $run->testResults->flatMap(fn ($r) => $r->screenshots)->map(fn ($s) => [
                        'id'          => $s->id,
                        'filename'    => $s->filename,
                        'label'       => $s->label,
                        'taken_at_ms' => $s->taken_at_ms,
                        'url'         => $s->url,
                    ])->values();
                    unset($data['test_results']);

                    return $data;
                }),
            'webhookUrl' => $suite->webhookUrl(),
            'members'    => $members,
            'candidates' => $candidates,
            'users'      => User::orderBy('name')->get(['id', 'name', 'email']),
            'can'        => [
                'edit'            => $privileges['edit'],
                'delete'          => $privileges['delete'],
                'run'             => $privileges['run'],
                'manageUsers'     => $canManageUsers,
                'manageSchedule'  => $canManageUsers,
            ],
        ]);
    }

    private function applyTestSort(HasMany $query, string $sort): void
    {
        $latestStatus = "COALESCE((SELECT status FROM test_results WHERE test_results.test_id = tests.id ORDER BY created_at DESC LIMIT 1), tests.last_run_status)";
        $latestDuration = '(SELECT duration_ms FROM test_results WHERE test_results.test_id = tests.id ORDER BY created_at DESC LIMIT 1)';

        match (true) {
            $sort === 'errors' => $query
                ->orderByRaw("CASE WHEN {$latestStatus} IN ('failed', 'error', 'timeout') THEN 0 ELSE 1 END")
                ->orderByDesc('last_run_at'),
            in_array($sort, self::RUN_STATUSES, true) => $query
                ->orderByRaw("CASE WHEN {$latestStatus} = ? THEN 0 ELSE 1 END", [$sort])
                ->orderByDesc('last_run_at'),
            $sort === 'status_active' => $query
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderBy('name'),
            $sort === 'status_disabled' => $query
                ->orderByRaw("CASE WHEN status = 'disabled' THEN 0 ELSE 1 END")
                ->orderBy('name'),
            $sort === 'duration_long' => $query
                ->orderByRaw("({$latestDuration}) IS NULL")
                ->orderByRaw("{$latestDuration} DESC"),
            $sort === 'duration_short' => $query
                ->orderByRaw("({$latestDuration}) IS NULL")
                ->orderByRaw("{$latestDuration} ASC"),
            $sort === 'oldest' => $query
                ->orderByRaw('last_run_at IS NULL')
                ->orderBy('last_run_at'),
            default => $query
                ->orderByRaw('last_run_at IS NULL')
                ->orderByDesc('last_run_at'),
        };
    }

    public function update(StoreTestSuiteRequest $request, TestSuite $suite)
    {
        $this->authorize('edit', $suite);

        $data = $request->validated();
        $hasProxyRules = array_key_exists('proxy_rules', $data);
        $proxyRules = $data['proxy_rules'] ?? null;
        unset($data['proxy_rules']);

        $suite->update($data);

        if ($hasProxyRules) {
            $suite->proxyRules()->delete();
            if ($proxyRules) {
                $suite->proxyRules()->createMany($proxyRules);
            }
        }

        if ($suite->wasChanged('history_retention')) {
            PruneSuiteHistoryJob::dispatch($suite);
        }

        return back();
    }

    public function destroy(TestSuite $suite)
    {
        $this->authorize('delete', $suite);

        $suite->delete();
        return redirect(route('suites.index', absolute: false));
    }

    public function regenerateWebhook(TestSuite $suite)
    {
        $this->authorize('edit', $suite);

        $suite->regenerateWebhookToken();
        return back();
    }
}
