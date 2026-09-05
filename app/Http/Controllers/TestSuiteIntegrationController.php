<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTestSuiteIntegrationRequest;
use App\Models\TestSuite;
use App\Models\TestSuiteIntegration;
use App\Services\ActivityLogger;
use App\Support\IntegrationPayload;
use Illuminate\Http\JsonResponse;

/**
 * Per-card CRUD for a suite's integrations (pre/post run hooks). The page
 * saves each card via plain JSON requests (see TestSuiteIntegrations.vue) so
 * an in-flight save never clobbers fields the user is still editing — hence
 * JSON responses instead of Inertia redirects. The MCP suite tools use the
 * array `integrations` payload on the suite endpoints instead.
 */
class TestSuiteIntegrationController extends Controller
{
    public function store(StoreTestSuiteIntegrationRequest $request, TestSuite $suite): JsonResponse
    {
        $this->authorize('manageIntegrations', $suite);

        $integration = $suite->integrations()->create(
            IntegrationPayload::normalize($request->validated())
        );

        ActivityLogger::log('integration_updated', $request->user(), $suite, null, [
            'action' => 'added',
            'type' => $integration->type,
        ]);

        return response()->json($integration->refresh(), 201);
    }

    public function update(StoreTestSuiteIntegrationRequest $request, TestSuite $suite, TestSuiteIntegration $integration): JsonResponse
    {
        $this->authorize('manageIntegrations', $suite);

        abort_unless($integration->test_suite_id === $suite->id, 404);

        $integration->update(IntegrationPayload::normalize($request->validated()));

        // An integration fixed by hand (re-enabled with a valid GitHub App)
        // clears the force-disable note set when its app was deleted.
        if ($integration->enabled && $integration->github_app_id !== null) {
            $integration->forceFill(['disabled_note' => null])->save();
        }

        ActivityLogger::log('integration_updated', $request->user(), $suite, null, [
            'action' => 'updated',
            'type' => $integration->type,
        ]);

        return response()->json($integration->refresh());
    }

    public function destroy(TestSuite $suite, TestSuiteIntegration $integration): JsonResponse
    {
        $this->authorize('manageIntegrations', $suite);

        abort_unless($integration->test_suite_id === $suite->id, 404);

        $integration->delete();

        ActivityLogger::log('integration_updated', request()->user(), $suite, null, [
            'action' => 'removed',
            'type' => $integration->type,
        ]);

        return response()->json(['deleted' => true]);
    }
}
