<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTestRequest;
use App\Models\Test;
use App\Models\TestCodeVersion;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\PlaywrightCodeValidatorService;
use App\Services\TestCodeVersionService;
use App\Services\TestSuiteDuplicationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TestController extends Controller
{
    public function store(StoreTestRequest $request, TestSuite $suite)
    {
        $this->authorize('edit', $suite);

        $suite->tests()->create([
            'name' => $request->name,
            'description' => $request->description,
            'uploaded_by' => $request->uploaded_by,
        ]);

        return redirect(route('suites.show', $suite, absolute: false));
    }

    public function show(TestSuite $suite, Test $test): Response
    {
        $this->authorize('view', $suite);

        $suite->load('variables', 'cookies');

        $codeVersions = $test->codeVersions()
            ->with('createdBy:id,name')
            ->paginate(10, ['*'], 'versions_page')
            ->withQueryString();

        $codeVersions->through(fn (TestCodeVersion $version) => [
            'id' => $version->id,
            'version_number' => $version->version_number,
            'playwright_code' => $version->playwright_code,
            'source' => $version->source,
            'created_by' => $version->createdBy?->name,
            'created_at' => $version->created_at,
        ]);

        $history = $test->testResults()
            ->with([
                'testRun:id,status,created_at,triggered_by,triggered_by_user_id,total_tests,ci_ip,ci_user_agent',
                'testRun.triggeredByUser:id,name,email,avatar',
                'screenshots',
            ])
            ->latest()
            ->paginate(10, ['*'], 'history_page')
            ->withQueryString();

        $history->through(fn ($r) => [
            'id' => $r->id,
            'status' => $r->status,
            'duration_ms' => $r->duration_ms,
            'created_at' => $r->created_at,
            'run_id' => $r->test_run_id,
            'run_total_tests' => $r->testRun?->total_tests,
            'triggered_by' => $r->testRun?->triggered_by,
            'triggered_by_user' => $r->testRun?->triggeredByUser,
            'ci_ip' => $r->testRun?->ci_ip,
            'ci_user_agent' => $r->testRun?->ci_user_agent,
            'error_message' => $r->error_message,
            'error_stack' => $r->error_stack,
            'stdout' => $r->stdout,
            'screenshots' => $r->screenshots->map(fn ($s) => [
                'id' => $s->id,
                'filename' => $s->filename,
                'label' => $s->label,
                'taken_at_ms' => $s->taken_at_ms,
                'url' => $s->url,
            ]),
        ]);

        return Inertia::render('Tests/Show', [
            'suite' => $suite,
            'test' => $test,
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'avatar']),
            'codeVersions' => $codeVersions,
            'history' => $history,
            'codeVersionRetention' => (int) config('sorify.test_code_version_retention'),
        ]);
    }

    public function update(StoreTestRequest $request, TestSuite $suite, Test $test)
    {
        $this->authorize('edit', $suite);

        $test->update([
            'name' => $request->name,
            'description' => $request->description,
            'uploaded_by' => $request->uploaded_by,
        ]);

        return back();
    }

    public function destroy(TestSuite $suite, Test $test)
    {
        $this->authorize('delete', $suite);

        $test->delete();

        return redirect(route('suites.show', $suite, absolute: false));
    }

    public function toggleStatus(TestSuite $suite, Test $test)
    {
        $this->authorize('edit', $suite);

        $test->update([
            'status' => $test->status === 'disabled' ? 'active' : 'disabled',
        ]);

        return back();
    }

    public function bulkDestroy(TestSuite $suite)
    {
        $this->authorize('delete', $suite);

        $ids = request()->input('test_ids', []);
        $suite->tests()->whereIn('id', $ids)->delete();

        return back();
    }

    public function bulkUpdateStatus(TestSuite $suite)
    {
        $this->authorize('edit', $suite);

        $status = request()->input('status');
        abort_unless(in_array($status, ['active', 'disabled'], true), 422);

        $ids = request()->input('test_ids', []);
        $suite->tests()->whereIn('id', $ids)->update(['status' => $status]);

        return back();
    }

    /**
     * Duplicate multiple tests at once. Each selected test is copied into
     * the same suite with an auto-generated "(copy)" name. Synchronous —
     * the number of tests is bounded by the current page size (max 100).
     */
    public function bulkDuplicate(Request $request, TestSuite $suite, TestSuiteDuplicationService $duplication)
    {
        $this->authorize('view', $suite);
        $this->authorize('edit', $suite);

        $ids = $request->validate([
            'test_ids' => 'required|array|min:1',
            'test_ids.*' => 'integer|exists:tests,id',
        ])['test_ids'];

        $suite->tests()
            ->whereIn('id', $ids)
            ->each(fn (Test $test) => $duplication->duplicateTest($test, $suite));

        return back();
    }

    public function updateCode(TestSuite $suite, Test $test)
    {
        $this->authorize('edit', $suite);

        $code = request('playwright_code');
        if ($code === null || $code === '') {
            return back();
        }

        try {
            app(PlaywrightCodeValidatorService::class)->validate((string) $code);
        } catch (ValidationException $e) {
            return back()->withErrors(['playwright_code' => $e->errors()['playwright_code'] ?? ['Invalid playwright_code.']]);
        }

        app(TestCodeVersionService::class)->updateCode($test, (string) $code, 'manual', auth()->id());

        return back();
    }

    public function restoreCodeVersion(TestSuite $suite, Test $test, TestCodeVersion $codeVersion)
    {
        $this->authorize('edit', $suite);

        abort_if($codeVersion->test_id !== $test->id, 404);

        app(TestCodeVersionService::class)->restore($test, $codeVersion, 'manual', auth()->id());

        return back();
    }

    /**
     * Duplicate a single test into the same suite (default) or a target
     * suite. Synchronous — one row, no need for a background job.
     */
    public function duplicate(Request $request, TestSuite $suite, Test $test, TestSuiteDuplicationService $duplication)
    {
        $this->authorize('view', $suite);

        $data = $request->validate([
            'target_suite_id' => 'nullable|integer|exists:test_suites,id',
            'name' => 'nullable|string|max:255',
        ]);

        $target = isset($data['target_suite_id'])
            ? TestSuite::findOrFail($data['target_suite_id'])
            : $suite;

        $this->authorize('edit', $target);

        $clone = $duplication->duplicateTest($test, $target, $data['name'] ?? null);

        return redirect(route('suites.tests.show', [$target, $clone], absolute: false));
    }
}
