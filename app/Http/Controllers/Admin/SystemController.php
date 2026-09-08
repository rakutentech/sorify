<?php

namespace App\Http\Controllers\Admin;

use App\Jobs\BuildRunnerImageJob;
use App\Models\Setting;
use App\Services\EphemeralReadinessChecker;
use App\Services\ExecutionMode;
use App\Http\Controllers\Controller;
use Inertia\Inertia;

class SystemController extends Controller
{
    public function index(EphemeralReadinessChecker $checker)
    {
        return Inertia::render('Admin/System', [
            'mode' => ExecutionMode::current(),
            'readiness' => $checker->check(),
            'image' => config('sorify.execution.runner_image'),
            'lastBuild' => cache('sorify.execution.runner_image_last_build'),
        ]);
    }

    public function readiness(EphemeralReadinessChecker $checker)
    {
        return response()->json([
            'checks' => $checker->check(fresh: request()->boolean('fresh')),
        ]);
    }

    public function updateMode(EphemeralReadinessChecker $checker)
    {
        $data = request()->validate([
            'execution_mode' => ['required', 'in:local,ephemeral'],
        ]);

        if ($data['execution_mode'] === 'ephemeral') {
            $checks = $checker->check(fresh: true);
            if (! $checker->isReady($checks)) {
                return response()->json([
                    'message' => 'The environment is not ready for ephemeral execution. Fix the failing checks below.',
                    'checks' => $checks,
                ], 422);
            }
        }

        Setting::set('execution_mode', $data['execution_mode']);

        return response()->json(['mode' => $data['execution_mode']]);
    }

    public function buildImage()
    {
        BuildRunnerImageJob::dispatch();

        return response()->json(['message' => 'Runner image build started.']);
    }
}
