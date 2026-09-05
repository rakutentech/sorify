<?php

namespace App\Mcp\Tools\Suites;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Mcp\Tools\Concerns\ManagesSuiteMemberPrivileges;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class AddSuiteMemberTool extends Tool
{
    use AuthorizesSuiteAccess;
    use ManagesSuiteMemberPrivileges;

    protected string $name = 'add_suite_member';

    protected string $description = 'Add a user to a suite with the given privileges.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
            'user_id' => $schema->integer()->required()->description('The user ID to add. Must not already be a member.'),
            'can_view' => $schema->boolean()->description('Defaults to false if omitted.'),
            'can_edit' => $schema->boolean()->description('Defaults to false if omitted. Forced false if the user is view-only.'),
            'can_delete' => $schema->boolean()->description('Defaults to false if omitted. Forced false if the user is view-only.'),
            'can_run' => $schema->boolean()->description('Defaults to false if omitted. Forced false if the user is view-only.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $suite = TestSuite::findOrFail($request->validate(['suite_id' => 'required|integer|exists:test_suites,id'])['suite_id']);
        $this->authorizeSuite('manageUsers', $suite);

        $existingMemberIds = $suite->members()->pluck('users.id')->all();

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id', Rule::notIn($existingMemberIds)],
            'can_view' => 'boolean',
            'can_edit' => 'boolean',
            'can_delete' => 'boolean',
            'can_run' => 'boolean',
        ]);

        $target = User::findOrFail($data['user_id']);

        $privileges = $this->clampPrivilegesForViewOnlyUser($target, [
            'can_view' => $data['can_view'] ?? false,
            'can_edit' => $data['can_edit'] ?? false,
            'can_delete' => $data['can_delete'] ?? false,
            'can_run' => $data['can_run'] ?? false,
        ]);

        $suite->members()->attach($target->id, $privileges);

        ActivityLogger::log('suite_members_changed', Auth::user(), $suite, null, [
            'action' => 'added',
            'member_name' => $target->name,
        ]);

        return Response::structured(['suite_id' => $suite->id, 'user_id' => $target->id, 'privileges' => $privileges]);
    }
}
