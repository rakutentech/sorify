<?php

namespace App\Mcp\Tools\Suites;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListSuiteMembersTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'list_suite_members';

    protected string $description = 'List the users with access to a suite and their privileges, plus candidate users who could be added.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['suite_id' => 'required|integer|exists:test_suites,id']);

        $suite = TestSuite::findOrFail($data['suite_id']);
        $this->authorizeSuite('manageUsers', $suite);

        $members = $suite->members()
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email', 'users.is_view_only'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_view_only' => (bool) $u->is_view_only,
                'can_view' => (bool) $u->pivot->can_view,
                'can_edit' => (bool) $u->pivot->can_edit,
                'can_delete' => (bool) $u->pivot->can_delete,
                'can_run' => (bool) $u->pivot->can_run,
            ]);

        $candidates = User::whereNotIn('id', $suite->members()->pluck('users.id'))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'is_view_only']);

        return Response::structured([
            'members' => $members->all(),
            'candidates' => $candidates->toArray(),
        ]);
    }
}
