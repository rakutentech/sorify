<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTestRequest;
use App\Models\Test;
use App\Models\TestCodeVersion;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\PlaywrightCodeValidatorService;
use App\Services\TestCodeVersionService;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TestController extends Controller
{
    public function store(StoreTestRequest $request, TestSuite $suite)
    {
        $this->authorize('edit', $suite);

        $suite->tests()->create([
            'name'        => $request->name,
            'description' => $request->description,
            'uploaded_by' => $request->uploaded_by,
        ]);

        return redirect(route('suites.show', $suite, absolute: false));
    }

    public function show(TestSuite $suite, Test $test): Response
    {
        $this->authorize('view', $suite);

        return Inertia::render('Tests/Show', [
            'suite'        => $suite,
            'test'         => $test,
            'users'        => User::orderBy('name')->get(['id', 'name', 'email']),
            'codeVersions' => $test->codeVersions()
                ->with('createdBy:id,name')
                ->get()
                ->map(fn (TestCodeVersion $version) => [
                    'id'              => $version->id,
                    'version_number'  => $version->version_number,
                    'playwright_code' => $version->playwright_code,
                    'source'          => $version->source,
                    'created_by'      => $version->createdBy?->name,
                    'created_at'      => $version->created_at,
                ]),
            'history' => $test->testResults()
                ->with([
                    'testRun:id,status,created_at,triggered_by,triggered_by_user_id',
                    'testRun.triggeredByUser:id,name',
                    'screenshots',
                ])
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn ($r) => [
                    'id'                => $r->id,
                    'status'            => $r->status,
                    'duration_ms'       => $r->duration_ms,
                    'created_at'        => $r->created_at,
                    'run_id'            => $r->test_run_id,
                    'triggered_by'      => $r->testRun?->triggered_by,
                    'triggered_by_user' => $r->testRun?->triggeredByUser,
                    'error_message' => $r->error_message,
                    'error_stack'   => $r->error_stack,
                    'stdout'        => $r->stdout,
                    'screenshots'   => $r->screenshots->map(fn ($s) => [
                        'id'          => $s->id,
                        'filename'    => $s->filename,
                        'label'       => $s->label,
                        'taken_at_ms' => $s->taken_at_ms,
                        'url'         => $s->url,
                    ]),
                ]),
        ]);
    }

    public function update(StoreTestRequest $request, TestSuite $suite, Test $test)
    {
        $this->authorize('edit', $suite);

        $test->update([
            'name'        => $request->name,
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
}
