<?php

namespace App\Mcp\Tools\Suites;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\TestSuite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class BookmarkSuiteTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'bookmark_suite';

    protected string $description = 'Bookmark a test suite for the current user.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $suite = TestSuite::findOrFail($request->validate(['suite_id' => 'required|integer|exists:test_suites,id'])['suite_id']);
        $this->authorizeSuite('view', $suite);

        Auth::user()->bookmarkedSuites()->syncWithoutDetaching([$suite->id]);

        return Response::structured(['suite_id' => $suite->id, 'bookmarked' => true]);
    }
}
