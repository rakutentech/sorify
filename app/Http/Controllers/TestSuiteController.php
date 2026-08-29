<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTestSuiteRequest;
use App\Jobs\PruneSuiteHistoryJob;
use App\Models\Test;
use App\Models\TestResult;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\ReportingService;
use App\Services\TestSuiteDuplicationService;
use App\Support\SuiteSort;
use App\Support\TestSort;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TestSuiteController extends Controller
{
    private const ERROR_STATUSES = ['failed', 'error', 'timeout'];

    public function __construct(private readonly ReportingService $reporting) {}

    public function index(Request $request): Response
    {
        $search = request()->string('search')->toString();
        $perPage = (int) request()->input('per_page', 30);
        $perPage = in_array($perPage, [10, 30, 50, 100]) ? $perPage : 30;

        $sort = $request->string('sort')->toString();
        $sortDir = $request->string('sort_dir')->toString();

        $query = TestSuite::withCount(['tests', 'testRuns', 'proxyRules', 'variables', 'cookies'])
            ->with(['schedule', 'members:id,name,email,avatar', 'bookmarkedBy' => fn ($q) => $q->where('users.id', $request->user()->id)]);

        SuiteSort::apply($query, $sort, $sortDir);

        if (! $request->user()->is_admin) {
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
            unset($data['webhook_token'], $data['teams_webhook_url'], $data['bookmarked_by']);
            $data['has_teams_webhook'] = (bool) $s->teams_webhook_url;
            $data['is_bookmarked'] = $s->bookmarkedBy->isNotEmpty();

            return $data;
        });

        return Inertia::render('TestSuites/Index', [
            'suites' => $paginator,
            'filters' => [
                'search' => $search,
                'per_page' => $perPage,
                'sort' => $sort,
                'sort_dir' => strtolower($sortDir) === 'asc' ? 'asc' : 'desc',
            ],
            'can' => ['create' => $request->user()->can('create', TestSuite::class)],
        ]);
    }

    public function store(StoreTestSuiteRequest $request)
    {
        $this->authorize('create', TestSuite::class);

        $data = $request->validated();
        $proxyRules = $data['proxy_rules'] ?? null;
        unset($data['proxy_rules']);
        $variables = $data['variables'] ?? null;
        unset($data['variables']);
        $cookies = $data['cookies'] ?? null;
        unset($data['cookies']);

        $suite = TestSuite::create([
            ...$data,
            'created_by' => $request->user()?->id,
        ]);

        if ($proxyRules) {
            $suite->proxyRules()->createMany($proxyRules);
        }

        if ($variables) {
            $this->syncVariables($suite, $variables);
        }

        if ($cookies) {
            $this->syncCookies($suite, $cookies);
        }

        $suite->members()->attach($request->user()->id, [
            'can_view' => true,
            'can_edit' => true,
            'can_delete' => true,
            'can_run' => true,
        ]);

        return redirect(route('suites.show', $suite, absolute: false));
    }

    /**
     * Pull-request-style "review all tests in one go" view: lists every test
     * in the suite with its Playwright code alongside its metadata, recent
     * runs, and screenshots. Lets developers review the full test code
     * without having to click into each test one by one.
     */
    public function review(Request $request, TestSuite $suite): Response
    {
        $this->authorize('view', $suite);

        $suite->load('createdBy:id,name,email,avatar', 'schedule', 'members:id,name,email,avatar', 'variables', 'cookies');

        $privileges = $suite->privilegesFor($request->user());

        $search = $request->string('search')->toString();
        $perPage = (int) $request->input('per_page', 100);
        $perPage = in_array($perPage, [10, 30, 50, 100, 200, 300]) ? $perPage : 100;

        $testsQuery = $suite->tests()
            ->with(['testResults' => fn ($query) => $query->latest()->limit(5)->with('screenshots')]);

        if ($search !== '') {
            $testsQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $testsQuery->orderBy('name');

        $tests = $testsQuery->paginate($perPage)->withQueryString();

        $tests->through(function (Test $test) {
            $data = $test->toArray();
            $data['recent_runs'] = $test->testResults->map(fn (TestResult $result) => [
                'run_id' => $result->test_run_id,
                'status' => $result->status,
                'created_at' => $result->created_at,
                'duration_ms' => $result->duration_ms,
                'error_message' => $result->error_message,
                'screenshots' => $result->screenshots->map(fn ($s) => [
                    'id' => $s->id,
                    'filename' => $s->filename,
                    'label' => $s->label,
                    'taken_at_ms' => $s->taken_at_ms,
                    'url' => $s->url,
                ]),
            ]);
            $data['current_status'] = $data['recent_runs'][0]['status'] ?? $test->last_run_status;
            unset($data['test_results']);

            return $data;
        });

        return Inertia::render('TestSuites/Review', [
            'suite' => $suite,
            'tests' => $tests,
            'filters' => ['search' => $search, 'per_page' => $perPage],
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'avatar']),
            'can' => [
                'edit' => $privileges['edit'],
                'run' => $privileges['run'],
            ],
        ]);
    }

    public function show(Request $request, TestSuite $suite): Response
    {
        $this->authorize('view', $suite);

        $suite->load('createdBy:id,name,email,avatar', 'schedule', 'members:id,name,email,avatar', 'proxyRules', 'variables', 'cookies');

        $privileges = $suite->privilegesFor($request->user());
        $canManageUsers = $privileges['edit'];

        $search = $request->string('search')->toString();
        $sort = $request->string('sort')->toString();
        $sortDir = $request->string('sort_dir')->toString();
        $status = array_values(array_filter((array) $request->input('status', [])));
        $perPage = (int) $request->input('per_page', 50);
        $perPage = in_array($perPage, [10, 30, 50, 100]) ? $perPage : 50;

        $members = [];
        $candidates = [];

        if ($canManageUsers) {
            $members = $suite->members()
                ->orderBy('users.name')
                ->get(['users.id', 'users.name', 'users.email', 'users.avatar', 'users.is_view_only'])
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'avatar_url' => $u->avatar_url,
                    'is_view_only' => (bool) $u->is_view_only,
                    'can_view' => (bool) $u->pivot->can_view,
                    'can_edit' => (bool) $u->pivot->can_edit,
                    'can_delete' => (bool) $u->pivot->can_delete,
                    'can_run' => (bool) $u->pivot->can_run,
                ]);

            $candidates = User::whereNotIn('id', $suite->members()->pluck('users.id'))
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'avatar', 'is_view_only']);
        }

        $testsQuery = $suite->tests()
            ->with(['testResults' => fn ($query) => $query->latest()->limit(10)->with('screenshots')]);

        if ($search !== '') {
            $testsQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        TestSort::filter($testsQuery, $status);
        TestSort::apply($testsQuery, $sort, $sortDir);

        $tests = $testsQuery->paginate($perPage)->withQueryString();

        $tests->through(function (Test $test) {
            $data = $test->toArray();
            $data['recent_runs'] = $test->testResults->map(fn (TestResult $result) => [
                'run_id' => $result->test_run_id,
                'status' => $result->status,
                'created_at' => $result->created_at,
                'duration_ms' => $result->duration_ms,
                'screenshots' => $result->screenshots->map(fn ($s) => [
                    'id' => $s->id,
                    'filename' => $s->filename,
                    'label' => $s->label,
                    'taken_at_ms' => $s->taken_at_ms,
                    'url' => $s->url,
                ]),
            ]);
            $data['current_status'] = $data['recent_runs'][0]['status'] ?? $test->last_run_status;
            unset($data['test_results']);

            return $data;
        });

        return Inertia::render('TestSuites/Show', [
            'suite' => $suite,
            'isBookmarked' => $suite->isBookmarkedBy($request->user()),
            'stats' => $this->reporting->suiteStats($suite),
            'tests' => $tests,
            'filters' => ['search' => $search, 'per_page' => $perPage, 'sort' => $sort, 'sort_dir' => $sortDir, 'status' => $status],
            'recentRuns' => $suite->testRuns()
                ->with(['triggeredByUser:id,name,email,avatar', 'testResults.screenshots', 'testResults.test:id,name'])
                ->latest()
                ->limit(10)
                ->get()
                ->map(function ($run) {
                    $data = $run->toArray();
                    $data['screenshots'] = $run->testResults->flatMap(fn ($r) => $r->screenshots)->map(fn ($s) => [
                        'id' => $s->id,
                        'filename' => $s->filename,
                        'label' => $s->label,
                        'taken_at_ms' => $s->taken_at_ms,
                        'url' => $s->url,
                    ])->values();
                    $data['test_names'] = $run->testResults
                        ->map(fn ($r) => $r->test?->name)
                        ->filter()
                        ->unique()
                        ->values()
                        ->toArray();
                    unset($data['test_results']);

                    return $data;
                }),
            'webhookUrl' => $suite->webhookUrl(),
            'members' => $members,
            'candidates' => $candidates,
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'avatar']),
            'can' => [
                'edit' => $privileges['edit'],
                'delete' => $privileges['delete'],
                'run' => $privileges['run'],
                'manageUsers' => $canManageUsers,
                'manageSchedule' => $canManageUsers,
            ],
        ]);
    }

    public function update(StoreTestSuiteRequest $request, TestSuite $suite)
    {
        $this->authorize('edit', $suite);

        $data = $request->validated();
        $hasProxyRules = array_key_exists('proxy_rules', $data);
        $proxyRules = $data['proxy_rules'] ?? null;
        unset($data['proxy_rules']);
        $hasVariables = array_key_exists('variables', $data);
        $variables = $data['variables'] ?? null;
        unset($data['variables']);
        $hasCookies = array_key_exists('cookies', $data);
        $cookies = $data['cookies'] ?? null;
        unset($data['cookies']);

        $suite->update($data);

        if ($hasProxyRules) {
            $suite->proxyRules()->delete();
            if ($proxyRules) {
                $suite->proxyRules()->createMany($proxyRules);
            }
        }

        if ($hasVariables) {
            $this->syncVariables($suite, $variables);
        }

        if ($hasCookies) {
            $this->syncCookies($suite, $cookies);
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
        $this->authorize('delete', $suite);

        $suite->regenerateWebhookToken();

        return back();
    }

    /**
     * Duplicate a suite: creates a new suite shell with copied settings +
     * proxy rules + membership, then dispatches a background job to copy
     * all of the source suite's tests. Returns immediately so the user
     * lands on the new suite and watches tests stream in.
     */
    public function duplicate(Request $request, TestSuite $suite, TestSuiteDuplicationService $duplication)
    {
        $this->authorize('view', $suite);
        $this->authorize('create', TestSuite::class);

        $data = $request->validate([
            'name' => 'nullable|string|max:255',
        ]);

        $clone = $duplication->duplicate($suite, $request->user(), $data['name'] ?? null);

        return redirect(route('suites.show', $clone, absolute: false));
    }

    /**
     * Replace a suite's variables with the given set. Duplicate keys collapse
     * to the last value (validation already restricts keys to valid JS
     * identifiers, but the unique DB index guards against races too).
     *
     * @param  array<int, array{key: string, value?: string|null}>  $variables
     */
    private function syncVariables(TestSuite $suite, ?array $variables): void
    {
        $suite->variables()->delete();

        if (! $variables) {
            return;
        }

        $rows = [];
        foreach ($variables as $variable) {
            $key = $variable['key'] ?? null;
            if ($key === null || $key === '') {
                continue;
            }
            // Last write wins for duplicate keys within the same payload.
            $rows[$key] = [
                'key' => $key,
                'value' => $variable['value'] ?? null,
            ];
        }

        if ($rows) {
            $suite->variables()->createMany(array_values($rows));
        }
    }

    /**
     * Replace a suite's cookies with the given set. Duplicate cookies
     * (same name + domain + path) collapse to the last value.
     *
     * @param  array<int, array{name: string, value?: string|null, domain?: string|null, path?: string|null, url?: string|null, expires?: int|null, http_only?: bool|null, secure?: bool|null, same_site?: string|null}>  $cookies
     */
    private function syncCookies(TestSuite $suite, ?array $cookies): void
    {
        $suite->cookies()->delete();

        if (! $cookies) {
            return;
        }

        $rows = [];
        foreach ($cookies as $cookie) {
            $name = $cookie['name'] ?? null;
            if ($name === null || $name === '') {
                continue;
            }
            $domain = $this->presentString($cookie['domain'] ?? null);
            $path = $this->presentString($cookie['path'] ?? null);
            $url = $this->presentString($cookie['url'] ?? null);
            // Playwright requires either url or domain; validation enforces this
            // for the web/API path, but be defensive for MCP callers too.
            if ($domain === null && $url === null) {
                continue;
            }
            $key = $name.'|'.$domain.'|'.$path;
            $rows[$key] = [
                'name' => $name,
                'value' => $this->presentString($cookie['value'] ?? null),
                'domain' => $domain,
                'path' => $path,
                'url' => $url,
                'expires' => isset($cookie['expires']) ? (int) $cookie['expires'] : null,
                'http_only' => (bool) ($cookie['http_only'] ?? false),
                'secure' => (bool) ($cookie['secure'] ?? false),
                'same_site' => $this->presentString($cookie['same_site'] ?? null),
            ];
        }

        if ($rows) {
            $suite->cookies()->createMany(array_values($rows));
        }
    }

    private function presentString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = is_string($value) ? trim($value) : (string) $value;

        return $value === '' ? null : $value;
    }
}
