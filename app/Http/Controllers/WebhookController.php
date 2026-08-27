<?php

namespace App\Http\Controllers;

use App\Exceptions\RunRateLimitExceededException;
use App\Exceptions\WebhookRunInProgressException;
use App\Models\TestRun;
use App\Services\TestRunService;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(private readonly TestRunService $runs) {}

    public function trigger(Request $request, string $token)
    {
        $suite = $request->attributes->get('webhook_suite');

        $testIds = $this->parseTestIds($request);

        try {
            $run = $this->runs->triggerRun($suite, $testIds, 'ci', null, $request->ip(), $request->userAgent());
        } catch (WebhookRunInProgressException $e) {
            $existing = $e->run;

            return response()->json([
                'message' => $e->getMessage(),
                'run_id' => $existing->id,
                'run_url' => route('runs.show', $existing),
                'status_url' => route('webhooks.status', ['token' => $suite->webhook_token, 'run' => $existing->id]),
            ], 409);
        } catch (RunRateLimitExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 429)
                ->header('Retry-After', $e->retryAfterSeconds);
        }

        return response()->json([
            'run_id' => $run->id,
            'run_url' => route('runs.show', $run),
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

    /**
     * Parse the `test_ids` query parameter into a list of integer ids.
     *
     * The CI webhook now accepts test ids ONLY as a comma-separated query
     * parameter (e.g. `?test_ids=1,2,3`). The JSON body is no longer read.
     *
     * @return int[]|null Array of ids, or null when the parameter is omitted
     *                    (meaning "run every active test in the suite").
     */
    private function parseTestIds(Request $request): ?array
    {
        if (! $request->query->has('test_ids')) {
            return null;
        }

        $raw = $request->query('test_ids');

        if (is_array($raw)) {
            // Reject PHP array-style (?test_ids[]=1&test_ids[]=2) — the API
            // contract is comma-separated only. This avoids silent ambiguity.
            abort(422, 'Invalid test_ids query parameter.');
        }

        $raw = trim((string) $raw);

        if ($raw === '') {
            // Empty value (?test_ids=) is treated the same as omitting it.
            return null;
        }

        $ids = [];
        foreach (explode(',', $raw) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            if (! ctype_digit($segment)) {
                abort(422, 'Invalid test_ids query parameter.');
            }
            $id = (int) $segment;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
