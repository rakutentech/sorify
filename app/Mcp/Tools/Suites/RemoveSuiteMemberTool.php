<?php

namespace App\Mcp\Tools\Suites;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Mcp\Tools\Concerns\ManagesSuiteMemberPrivileges;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class RemoveSuiteMemberTool extends Tool
{
    use AuthorizesSuiteAccess;
    use ManagesSuiteMemberPrivileges;

    protected string $name = 'remove_suite_member';

    protected string $description = 'Remove a user from a suite.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
            'user_id' => $schema->integer()->required()->description("The member's user ID."),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $suite = TestSuite::findOrFail($request->validate(['suite_id' => 'required|integer|exists:test_suites,id'])['suite_id']);
        $this->authorizeSuite('manageUsers', $suite);

        $data = $request->validate(['user_id' => 'required|integer|exists:users,id']);
        $target = User::findOrFail($data['user_id']);

        if ($error = $this->lastEditorViolation($suite, $target, false)) {
            return Response::error($error);
        }

        $suite->members()->detach($target->id);

        return Response::structured(['deleted' => true, 'suite_id' => $suite->id, 'user_id' => $target->id]);
    }
}
