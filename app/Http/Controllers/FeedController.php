<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Test;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeedController extends Controller
{
    /**
     * The activity feed. Admins see everything; everyone else sees global
     * events (suite_id NULL) plus events of suites they are a member of
     * with can_view — the same permission model as the suites/runs lists.
     *
     * Also answers plain JSON (Accept: application/json, no X-Inertia
     * header) for the feed page's infinite scroll, which appends further
     * pages client-side without a full Inertia visit.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $filters = $this->filters($request);

        $query = Activity::query()
            ->with(['actor:id,name,email,avatar', 'suite:id,name'])
            ->latest('id');
        $this->scopeToVisibleSuites($query, $request->user());
        $this->applyFilters($query, $filters);

        $paginator = $query->paginate(25)->withQueryString();

        $runs = $this->loadRunSubjects(collect($paginator->items()));
        $tests = $this->loadTestSubjects(collect($paginator->items()));
        $paginator->through(fn (Activity $activity) => $this->serialize($activity, $runs, $tests));

        if (! $request->hasHeader('X-Inertia') && $request->wantsJson()) {
            return response()->json($paginator);
        }

        return Inertia::render('Feed/Index', [
            'activities' => $paginator,
            'filters' => $filters,
            'filterOptions' => [
                'types' => array_keys(ActivityLogger::PAYLOAD_KEYS),
                // Capped at the most recent 50 so huge accounts neither
                // bloat the Inertia payload nor slow the feed's queries.
                'suites' => $this->visibleSuites($request->user()),
                'users' => User::latest('id')->limit(50)->get(['id', 'name', 'email', 'avatar']),
            ],
        ]);
    }

    /**
     * Lightweight JSON endpoint polled by the feed page while any visible
     * run is pending/running: live counters for those runs plus the newest
     * activity id (so the page can flag/reload when new items arrive).
     */
    public function poll(Request $request): JsonResponse
    {
        $user = $request->user();

        $runs = TestRun::whereIn('status', ['pending', 'running'])
            ->when(! $user->is_admin, fn (Builder $q) => $q->whereHas('testSuite.members', fn ($m) => $m
                ->where('users.id', $user->id)
                ->where('test_suite_user.can_view', true)))
            ->get([
                'id', 'status', 'total_tests', 'passed_count', 'failed_count',
                'error_count', 'duration_ms', 'status_note', 'created_at', 'started_at',
            ]);

        $latest = Activity::query();
        $this->scopeToVisibleSuites($latest, $user);

        return response()->json([
            'active_runs' => $runs,
            'latest_activity_id' => (int) $latest->max('id'),
        ]);
    }

    /**
     * Admins see all activities; everyone else only global events plus
     * those of suites where they are a can_view member.
     */
    private function scopeToVisibleSuites(Builder $query, User $user): Builder
    {
        if ($user->is_admin) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->whereNull('activities.suite_id')
                ->orWhereHas('suite.members', fn ($m) => $m
                    ->where('users.id', $user->id)
                    ->where('test_suite_user.can_view', true));
        });
    }

    private function filters(Request $request): array
    {
        $types = array_values(array_intersect(
            (array) $request->input('type', []),
            array_keys(ActivityLogger::PAYLOAD_KEYS)
        ));

        return [
            'type' => $types,
            'suite_id' => $request->integer('suite_id') ?: null,
            'actor_id' => $request->integer('actor_id') ?: null,
            'from' => $request->input('from') ?: null,
            'to' => $request->input('to') ?: null,
        ];
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when(! empty($filters['type']), fn (Builder $q) => $q->whereIn('type', $filters['type']))
            ->when($filters['suite_id'] !== null, fn (Builder $q) => $q->where('suite_id', $filters['suite_id']))
            ->when($filters['actor_id'] !== null, fn (Builder $q) => $q->where('actor_id', $filters['actor_id']))
            ->when($filters['from'] !== null, fn (Builder $q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when($filters['to'] !== null, fn (Builder $q) => $q->whereDate('created_at', '<=', $filters['to']));
    }

    /**
     * The suite filter's autocomplete options — capped at the 50 most
     * recently created suites the user can view.
     */
    private function visibleSuites(User $user)
    {
        return TestSuite::orderByDesc('id')
            ->limit(50)
            ->when(! $user->is_admin, fn (Builder $q) => $q->whereHas('members', fn ($m) => $m
                ->where('users.id', $user->id)
                ->where('test_suite_user.can_view', true)))
            ->get(['id', 'name']);
    }

    /**
     * Run-subject cards need live counters, failed-test names and screenshot
     * thumbnails — none of which is on the activities row. Load them in one
     * query keyed by run id (instead of a morphTo eager load, which would
     * drag heavy columns like tests.playwright_code along for every subject).
     *
     * @param  \Illuminate\Support\Collection<Activity>  $activities
     */
    private function loadRunSubjects($activities)
    {
        $runMorph = (new TestRun)->getMorphClass();

        $runIds = $activities
            ->filter(fn (Activity $a) => $a->subject_type === $runMorph && $a->subject_id !== null)
            ->pluck('subject_id')
            ->unique()
            ->values();

        if ($runIds->isEmpty()) {
            return collect();
        }

        return TestRun::with(['triggeredByUser:id,name,email,avatar', 'testResults.screenshots', 'testResults.test:id,name'])
            ->whereIn('id', $runIds)
            ->get()
            ->keyBy('id');
    }

    /**
     * Test subjects only need their ids for deep links — loaded in one
     * query instead of a morphTo eager load (which would pull the heavy
     * playwright_code column of every subject row).
     *
     * @param  \Illuminate\Support\Collection<Activity>  $activities
     */
    private function loadTestSubjects($activities)
    {
        $testMorph = (new Test)->getMorphClass();

        $testIds = $activities
            ->filter(fn (Activity $a) => $a->subject_type === $testMorph && $a->subject_id !== null)
            ->pluck('subject_id')
            ->unique()
            ->values();

        if ($testIds->isEmpty()) {
            return collect();
        }

        return Test::whereIn('id', $testIds)->get(['id', 'test_suite_id'])->keyBy('id');
    }

    private function serialize(Activity $activity, $runs, $tests): array
    {
        $subject = null;
        $runMorph = (new TestRun)->getMorphClass();
        $testMorph = (new Test)->getMorphClass();
        $suiteMorph = (new TestSuite)->getMorphClass();

        $run = $activity->subject_type === $runMorph ? $runs->get($activity->subject_id) : null;
        $test = $activity->subject_type === $testMorph ? $tests->get($activity->subject_id) : null;

        if ($run !== null) {
            $failedTests = $run->testResults
                ->filter(fn ($r) => in_array($r->status, ['failed', 'error', 'timeout'], true))
                ->map(fn ($r) => $r->test?->name)
                ->filter()
                ->unique()
                ->take(3)
                ->values()
                ->all();

            $subject = [
                'id' => $run->id,
                'status' => $run->status,
                'total_tests' => $run->total_tests,
                'passed_count' => $run->passed_count,
                'failed_count' => $run->failed_count,
                'error_count' => $run->error_count,
                'duration_ms' => $run->duration_ms,
                'triggered_by' => $run->triggered_by,
                'triggered_by_user' => $run->triggeredByUser ? [
                    'id' => $run->triggeredByUser->id,
                    'name' => $run->triggeredByUser->name,
                    'email' => $run->triggeredByUser->email,
                    'avatar_url' => $run->triggeredByUser->avatar_url,
                ] : null,
                'failed_tests' => $failedTests,
                'screenshots' => $run->testResults
                    ->flatMap(fn ($r) => $r->screenshots)
                    ->take(4)
                    ->map(fn ($s) => [
                        'id' => $s->id,
                        'filename' => $s->filename,
                        'label' => $s->label,
                        'taken_at_ms' => $s->taken_at_ms,
                        'url' => $s->url,
                    ])
                    ->values()
                    ->all(),
            ];
        } elseif ($test !== null) {
            $subject = ['id' => $test->id, 'suite_id' => $test->test_suite_id];
        } elseif ($activity->subject_type === $suiteMorph && $activity->suite_id !== null) {
            // Subject suites are always the activity's own suite.
            $subject = ['id' => $activity->suite_id];
        }

        return [
            'id' => $activity->id,
            'type' => $activity->type,
            'actor' => $activity->actor ? [
                'id' => $activity->actor->id,
                'name' => $activity->actor->name,
                'email' => $activity->actor->email,
                'avatar_url' => $activity->actor->avatar_url,
            ] : null,
            'suite' => $activity->suite ? [
                'id' => $activity->suite->id,
                'name' => $activity->suite->name,
            ] : null,
            'payload' => $activity->payload,
            'subject' => $subject,
            'created_at' => $activity->created_at?->toISOString(),
        ];
    }
}
