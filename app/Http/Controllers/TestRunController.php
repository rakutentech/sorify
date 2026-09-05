<?php

namespace App\Http\Controllers;

use App\Exceptions\RunRateLimitExceededException;
use App\Models\Test;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Services\TestRunService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TestRunController extends Controller
{
    public function __construct(private readonly TestRunService $runs) {}

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

        $run->load(['testSuite:id,name', 'triggeredByUser:id,name,email,avatar']);

        $perPage = (int) request()->input('per_page', 50);
        $perPage = in_array($perPage, [25, 50, 100, 200]) ? $perPage : 50;

        $search = (string) request()->input('search', '');
        $status = array_values(array_filter((array) request()->input('status', [])));
        $testId = request()->input('filter.test_id');
        $filteredTest = null;
        if ($testId !== null) {
            $testId = (int) $testId;
            $filteredTest = Test::find($testId, ['id', 'name']);
        }

        $results = $run->testResults()
            ->with(['test:id,name,test_suite_id', 'screenshots'])
            ->when($search !== '', function ($q) use ($search) {
                $q->whereHas('test', function ($t) use ($search) {
                    $t->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when(! empty($status), fn ($q) => $q->whereIn('status', $status))
            ->when($testId !== null, fn ($q) => $q->where('test_id', $testId))
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        $results->through(fn ($r) => [
            'id' => $r->id,
            'test_id' => $r->test->id,
            'test_name' => $r->test->name,
            'status' => $r->status,
            'duration_ms' => $r->duration_ms,
            'error_message' => $r->error_message,
            'error_stack' => $r->error_stack,
            'stdout' => $r->stdout,
            'screenshot_count' => $r->screenshots->count(),
            'screenshots' => $r->screenshots->map(fn ($s) => [
                'id' => $s->id,
                'filename' => $s->filename,
                'label' => $s->label,
                'taken_at_ms' => $s->taken_at_ms,
                'url' => $s->url,
            ]),
        ]);

        return Inertia::render('TestRuns/Show', [
            'run' => array_merge($run->toArray(), ['suite' => $run->testSuite]),
            'results' => $results,
            'resultTestIds' => $run->testResults()->pluck('test_id')->all(),
            'filters' => [
                'search' => $search,
                'per_page' => $perPage,
                'status' => $status,
                'test_id' => $testId,
            ],
            'filteredTest' => $filteredTest ? ['id' => $filteredTest->id, 'name' => $filteredTest->name] : null,
        ]);
    }

    public function status(TestRun $run)
    {
        $this->authorize('view', $run->testSuite);

        return response()->json($this->runs->statusPayload($run));
    }

    public function cancel(Request $request, TestRun $run)
    {
        $this->authorize('run', $run->testSuite);

        $this->runs->cancel($run, $request->user());

        return back();
    }

    public function destroy(TestRun $run)
    {
        $this->authorize('delete', $run->testSuite);

        $run->delete();

        return back();
    }
}
