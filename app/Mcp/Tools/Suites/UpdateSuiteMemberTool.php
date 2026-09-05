<?php

namespace App\Mcp\Tools\Suites;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Mcp\Tools\Concerns\ManagesSuiteMemberPrivileges;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class UpdateSuiteMemberTool extends Tool
{
    use AuthorizesSuiteAccess;
    use ManagesSuiteMemberPrivileges;

    protected string $name = 'update_suite_member';

    protected string $description = "Update a suite member's privileges.";

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
            'user_id' => $schema->integer()->required()->description("The member's user ID."),
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

        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'can_view' => 'boolean',
            'can_edit' => 'boolean',
            'can_delete' => 'boolean',
            'can_run' => 'boolean',
        ]);

        $target = User::findOrFail($data['user_id']);

        if ($suite->members()->where('users.id', $target->id)->doesntExist()) {
            return Response::error('User is not a member of this suite.');
        }

        $privileges = $this->clampPrivilegesForViewOnlyUser($target, [
            'can_view' => $data['can_view'] ?? false,
            'can_edit' => $data['can_edit'] ?? false,
            'can_delete' => $data['can_delete'] ?? false,
            'can_run' => $data['can_run'] ?? false,
        ]);

        if ($error = $this->lastEditorViolation($suite, $target, $privileges['can_edit'])) {
            return Response::error($error);
        }

        $suite->members()->updateExistingPivot($target->id, $privileges);

        ActivityLogger::log('suite_members_changed', Auth::user(), $suite, null, [
            'action' => 'updated',
            'member_name' => $target->name,
        ]);

        return Response::structured(['suite_id' => $suite->id, 'user_id' => $target->id, 'privileges' => $privileges]);
    }
}
