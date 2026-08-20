<?php

namespace App\Mcp\Tools\Suites;

use App\Models\TestSuite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class UnbookmarkSuiteTool extends Tool
{
    protected string $name = 'unbookmark_suite';

    protected string $description = 'Remove a bookmark on a test suite for the current user.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $suite = TestSuite::findOrFail($request->validate(['suite_id' => 'required|integer|exists:test_suites,id'])['suite_id']);

        Auth::user()->bookmarkedSuites()->detach($suite->id);

        return Response::structured(['suite_id' => $suite->id, 'bookmarked' => false]);
    }
}
