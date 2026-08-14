<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTestSuiteRequest;
use App\Jobs\PruneSuiteHistoryJob;
use App\Models\Test;
use App\Models\TestResult;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\ReportingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TestSuiteController extends Controller
{
    public function __construct(private readonly ReportingService $reporting) {}

    public function index(Request $request): Response
    {
        $search   = request()->string('search')->toString();
        $perPage  = (int) request()->input('per_page', 10);
        $perPage  = in_array($perPage, [10, 50, 100]) ? $perPage : 10;

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
        ]);
    }

    public function store(StoreTestSuiteRequest $request)
    {
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

        $suite->load('createdBy:id,name', 'schedule', 'members:id,name,email', 'proxyRules');

        $privileges = $suite->privilegesFor($request->user());
        $canManageUsers = $privileges['edit'];

        $members = [];
        $candidates = [];

        if ($canManageUsers) {
            $members = $suite->members()
                ->orderBy('users.name')
                ->get(['users.id', 'users.name', 'users.email'])
                ->map(fn (User $u) => [
                    'id'         => $u->id,
                    'name'       => $u->name,
                    'email'      => $u->email,
                    'can_view'   => (bool) $u->pivot->can_view,
                    'can_edit'   => (bool) $u->pivot->can_edit,
                    'can_delete' => (bool) $u->pivot->can_delete,
                    'can_run'    => (bool) $u->pivot->can_run,
                ]);

            $candidates = User::whereNotIn('id', $suite->members()->pluck('users.id'))
                ->orderBy('name')
                ->get(['id', 'name', 'email']);
        }

        return Inertia::render('TestSuites/Show', [
            'suite'      => $suite,
            'stats'      => $this->reporting->suiteStats($suite),
            'tests'      => $suite->tests()
                ->with(['testResults' => fn ($query) => $query->latest()->limit(3)])
                ->latest()
                ->get()
                ->map(function (Test $test) {
                    $data = $test->toArray();
                    $data['recent_runs'] = $test->testResults->map(fn (TestResult $result) => [
                        'run_id'     => $result->test_run_id,
                        'status'     => $result->status,
                        'created_at' => $result->created_at,
                    ]);
                    $data['current_status'] = $data['recent_runs'][0]['status'] ?? $test->last_run_status;
                    unset($data['test_results']);

                    return $data;
                }),
            'recentRuns' => $suite->testRuns()->with('triggeredByUser:id,name')->latest()->limit(10)->get(),
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
