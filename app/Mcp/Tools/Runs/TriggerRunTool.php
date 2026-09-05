<?php

namespace App\Mcp\Tools\Runs;

use App\Exceptions\RunRateLimitExceededException;
use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\TestSuite;
use App\Services\TestRunService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class TriggerRunTool extends Tool
{
    use AuthorizesSuiteAccess;

    public function __construct(private readonly TestRunService $runs) {}

    protected string $name = 'trigger_run';

    protected string $description = 'Queue a run of a test suite, optionally limited to specific tests. If the suite has enabled "before run" integrations (GitHub Action / HTTP request), the run stays pending until they succeed — poll get_run_status (status_note explains why a run is pending or failed).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
            'test_ids' => $schema->array()->items($schema->integer())->description('Limit the run to these test IDs; omit to run all active tests.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'suite_id' => 'required|integer|exists:test_suites,id',
            'test_ids' => 'nullable|array',
            'test_ids.*' => 'exists:tests,id',
        ]);

        $suite = TestSuite::findOrFail($data['suite_id']);
        $this->authorizeSuite('run', $suite);

        try {
            $run = $this->runs->triggerRun($suite, $data['test_ids'] ?? null, 'mcp', Auth::id());
        } catch (RunRateLimitExceededException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured(['run_id' => $run->id, 'status' => $run->status]);
    }
}
