<?php

namespace App\Http\Controllers;

use App\Models\TestRun;
use App\Services\TestRunService;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(private readonly TestRunService $runs) {}

    public function trigger(Request $request, string $token)
    {
        $suite = $request->attributes->get('webhook_suite');

        $testIds = $request->input('test_ids');

        $run = $this->runs->triggerRun($suite, $testIds, 'ci');

        return response()->json([
            'run_id' => $run->id,
            'status' => $run->status,
            'status_url' => route('webhooks.status', ['token' => $suite->webhook_token, 'run' => $run->id]),
        ], 202);
    }

    public function status(Request $request, string $token, TestRun $run)
    {
        $suite = $request->attributes->get('webhook_suite');

        if ($run->test_suite_id !== $suite->id) {
            abort(404);
        }

        return response()->json($this->runs->statusPayload($run));
    }
}
