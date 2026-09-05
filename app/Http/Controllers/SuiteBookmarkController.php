<?php

namespace App\Http\Controllers;

use App\Models\TestSuite;
use App\Services\ReportingService;
use App\Support\SuiteSort;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SuiteBookmarkController extends Controller
{
    public function __construct(private readonly ReportingService $reporting) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $search = $request->string('search')->toString();
        $perPage = (int) $request->input('per_page', 30);
        $perPage = in_array($perPage, [10, 30, 50, 100]) ? $perPage : 30;

        $sort = $request->string('sort')->toString();
        $sortDir = $request->string('sort_dir')->toString();

        $query = $user->bookmarkedSuites()
            ->withCount(['tests', 'testRuns', 'proxyRules', 'variables', 'cookies'])
            ->with([
                'schedule',
                'members:id,name,email,avatar',
                // Only what the chip row needs: which integration types exist.
                'integrations:id,test_suite_id,type,enabled',
            ]);

        SuiteSort::apply($query, $sort, $sortDir);

        if (! $user->is_admin) {
            $query->whereHas('members', function ($q) use ($user) {
                $q->where('users.id', $user->id)
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

        $paginator->through(function (TestSuite $s) {
            $data = array_merge($s->toArray(), $this->reporting->suiteStats($s));
            unset($data['webhook_token'], $data['teams_webhook_url'], $data['pivot'], $data['integrations']);
            $data['has_teams_webhook'] = (bool) $s->teams_webhook_url;
            $data['has_github_integration'] = $s->integrations->contains(fn ($i) => $i->type === 'github_action' && $i->enabled);
            $data['has_http_integration'] = $s->integrations->contains(fn ($i) => $i->type === 'http_request' && $i->enabled);

            return $data;
        });

        return Inertia::render('Bookmarks/Index', [
            'suites' => $paginator,
            'filters' => [
                'search' => $search,
                'per_page' => $perPage,
                'sort' => $sort,
                'sort_dir' => strtolower($sortDir) === 'asc' ? 'asc' : 'desc',
            ],
        ]);
    }

    public function store(Request $request, TestSuite $suite)
    {
        $this->authorize('view', $suite);

        $request->user()->bookmarkedSuites()->syncWithoutDetaching([$suite->id]);

        return back()->with('flash.success', 'Suite bookmarked.');
    }

    public function destroy(Request $request, TestSuite $suite)
    {
        $request->user()->bookmarkedSuites()->detach($suite->id);

        return back()->with('flash.success', 'Bookmark removed.');
    }
}
