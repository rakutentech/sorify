<?php

namespace App\Http\Controllers;

use App\Models\TestRun;
use App\Services\ReportingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly ReportingService $reporting) {}

    public function index(Request $request): Response
    {
        $runsQuery = TestRun::with([
            'testSuite:id,name,created_by',
            'testSuite.createdBy:id,name',
            'testSuite.members:id,name,email',
            'triggeredByUser:id,name',
        ])->latest();

        if (!$request->user()->is_admin) {
            $runsQuery->whereHas('testSuite.members', function ($q) use ($request) {
                $q->where('users.id', $request->user()->id)
                  ->where('test_suite_user.can_view', true);
            });
        }

        return Inertia::render('Dashboard/Index', [
            'stats'       => $this->reporting->dashboardStats(),
            'recent_runs' => $runsQuery
                ->limit(10)
                ->get()
                ->map(fn ($run) => [
                    'id'            => $run->id,
                    'suite_id'      => $run->testSuite->id,
                    'suite_name'    => $run->testSuite->name,
                    'members'       => $run->testSuite->members,
                    'status'        => $run->status,
                    'passed_count'  => $run->passed_count,
                    'failed_count'  => $run->failed_count,
                    'error_count'   => $run->error_count,
                    'total_tests'   => $run->total_tests,
                    'duration_ms'   => $run->duration_ms,
                    'completed_at'  => $run->completed_at,
                    'created_by'    => $run->testSuite->createdBy?->name,
                    'triggered_by'  => $run->triggeredByUser?->name,
                ]),
        ]);
    }
}
