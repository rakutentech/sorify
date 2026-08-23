<?php

namespace App\Mcp\Tools\Suites;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\TestSuite;
use App\Services\TestSuiteDuplicationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class DuplicateSuiteTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'duplicate_suite';

    protected string $description = 'Duplicate a test suite — creates a new suite with all settings and tests copied. The new suite is created immediately; tests are copied in the background. Returns the new suite right away.';

    public function __construct(private readonly TestSuiteDuplicationService $duplication) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The source test suite ID.'),
            'name' => $schema->string()->description('Name for the new suite. Defaults to "<original name> (copy)". If the source name already ends with " (copy)" or " (copy N)", the suffix number is bumped.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'suite_id' => 'required|integer|exists:test_suites,id',
            'name' => 'nullable|string|max:255',
        ]);

        $source = TestSuite::findOrFail($data['suite_id']);
        $this->authorizeSuite('view', $source);
        $this->authorizeSuite('create', TestSuite::class);

        $clone = $this->duplication->duplicate($source, Auth::user(), $data['name'] ?? null);

        return Response::structured([
            'suite' => $clone->fresh(['proxyRules', 'cookies'])->toArray(),
            'source_suite_id' => $source->id,
            'duplication_status' => $clone->duplication_status,
            'message' => 'Suite created. Tests are being copied in the background — poll `get_suite` on the new suite_id to watch progress. `duplication_status` will move from "pending" to "complete" (or "failed").',
        ]);
    }
}
