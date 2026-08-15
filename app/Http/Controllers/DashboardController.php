<?php

namespace App\Http\Controllers;

use App\Models\DashboardNote;
use App\Services\ReportingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly ReportingService $reporting) {}

    public function index(Request $request): Response
    {
        $note = DashboardNote::current()->load('updatedBy:id,name');

        return Inertia::render('Dashboard/Index', [
            'stats' => $this->reporting->dashboardStats(),
            'dashboard_note' => [
                'content'    => $note->content,
                'updated_by' => $note->updatedBy?->name,
                'updated_at' => $note->updated_at,
            ],
            'can' => [
                'edit_dashboard_note' => $request->user()->is_admin,
            ],
        ]);
    }
}
