<?php

namespace App\Http\Controllers;

use App\Exceptions\RunRateLimitExceededException;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Services\TestRunService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TestRunController extends Controller
{
    public function __construct(private readonly TestRunService $runs) {}

    public function index(Request $request): Response
    {
        $query = TestRun::with([
            'testSuite:id,name,created_by',
            'testSuite.createdBy:id,name',
            'testSuite.members:id,name,email',
            'triggeredByUser:id,name,email',
            'testResults.screenshots',
        ])->latest();

        if (!$request->user()->is_admin) {
            $query->whereHas('testSuite.members', function ($q) use ($request) {
                $q->where('users.id', $request->user()->id)
                  ->where('test_suite_user.can_view', true);
            });
        }

        $perPage = (int) $request->input('per_page', 30);
        $perPage = in_array($perPage, [10, 30, 50, 100]) ? $perPage : 30;

        $paginator = $query->paginate($perPage)->withQueryString();

        $paginator->through(fn ($run) => [
            'id'                => $run->id,
            'suite_id'          => $run->testSuite->id,
            'suite_name'        => $run->testSuite->name,
            'members'           => $run->testSuite->members,
            'status'            => $run->status,
            'passed_count'      => $run->passed_count,
            'failed_count'      => $run->failed_count,
            'error_count'       => $run->error_count,
            'total_tests'       => $run->total_tests,
            'duration_ms'       => $run->duration_ms,
            'completed_at'      => $run->completed_at,
            'created_by'        => $run->testSuite->createdBy?->name,
            'triggered_by'      => $run->triggered_by,
            'triggered_by_user' => $run->triggeredByUser,
            'screenshots'       => $run->testResults->flatMap(fn ($r) => $r->screenshots)->map(fn ($s) => [
                'id'          => $s->id,
                'filename'    => $s->filename,
                'label'       => $s->label,
                'taken_at_ms' => $s->taken_at_ms,
                'url'         => $s->url,
            ])->values(),
        ]);

        return Inertia::render('Runs/Index', [
            'runs'    => $paginator,
            'filters' => ['per_page' => $perPage],
        ]);
    }

    public function store(TestSuite $suite)
    {
        $this->authorize('run', $suite);

        $testIds = request()->input('test_ids');

        try {
            $run = $this->runs->triggerRun($suite, $testIds, 'manual', request()->user()?->id);
        } catch (RunRateLimitExceededException $e) {
            return back()->withErrors(['run' => $e->getMessage()]);
        }

        return redirect(route('runs.show', $run, absolute: false));
    }

    public function show(TestRun $run): Response
    {
        $this->authorize('view', $run->testSuite);

        $run->load(['testSuite:id,name', 'triggeredByUser:id,name,email']);

        $perPage = (int) request()->input('per_page', 50);
        $perPage = in_array($perPage, [25, 50, 100, 200]) ? $perPage : 50;

        $results = $run->testResults()
            ->with(['test:id,name,test_suite_id', 'screenshots'])
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        $results->through(fn ($r) => [
            'id'               => $r->id,
            'test_id'          => $r->test->id,
            'test_name'        => $r->test->name,
            'status'           => $r->status,
            'duration_ms'      => $r->duration_ms,
            'error_message'    => $r->error_message,
            'error_stack'      => $r->error_stack,
            'stdout'           => $r->stdout,
            'screenshot_count' => $r->screenshots->count(),
            'screenshots'      => $r->screenshots->map(fn ($s) => [
                'id'          => $s->id,
                'filename'    => $s->filename,
                'label'       => $s->label,
                'taken_at_ms' => $s->taken_at_ms,
                'url'         => $s->url,
            ]),
        ]);

        return Inertia::render('TestRuns/Show', [
            'run'            => array_merge($run->toArray(), ['suite' => $run->testSuite]),
            'results'        => $results,
            'resultTestIds'  => $run->testResults()->pluck('test_id')->all(),
        ]);
    }

    public function status(TestRun $run)
    {
        $this->authorize('view', $run->testSuite);

        return response()->json($this->runs->statusPayload($run));
    }

    public function cancel(TestRun $run)
    {
        $this->authorize('run', $run->testSuite);

        $this->runs->cancel($run);
        return back();
    }

    public function destroy(TestRun $run)
    {
        $this->authorize('delete', $run->testSuite);

        $run->delete();
        return back();
    }
}
