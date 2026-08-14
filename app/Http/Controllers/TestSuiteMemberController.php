<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddSuiteMemberRequest;
use App\Http\Requests\UpdateSuiteMemberRequest;
use App\Models\TestSuite;
use App\Models\User;

class TestSuiteMemberController extends Controller
{
    public function store(AddSuiteMemberRequest $request, TestSuite $suite)
    {
        $this->authorize('manageUsers', $suite);

        $target = User::findOrFail($request->integer('user_id'));

        $privileges = $this->clampPrivilegesForViewOnlyUser($target, [
            'can_view'   => $request->boolean('can_view'),
            'can_edit'   => $request->boolean('can_edit'),
            'can_delete' => $request->boolean('can_delete'),
            'can_run'    => $request->boolean('can_run'),
        ]);

        $suite->members()->attach($target->id, $privileges);

        return back()->with('flash.success', 'Member added.');
    }

    public function update(UpdateSuiteMemberRequest $request, TestSuite $suite, User $user)
    {
        $this->authorize('manageUsers', $suite);

        $privileges = $this->clampPrivilegesForViewOnlyUser($user, [
            'can_view'   => $request->boolean('can_view'),
            'can_edit'   => $request->boolean('can_edit'),
            'can_delete' => $request->boolean('can_delete'),
            'can_run'    => $request->boolean('can_run'),
        ]);

        if ($error = $this->lastEditorViolation($suite, $user, $privileges['can_edit'])) {
            return back()->withErrors(['member' => $error]);
        }

        $suite->members()->updateExistingPivot($user->id, $privileges);

        return back()->with('flash.success', 'Privileges updated.');
    }

    public function destroy(TestSuite $suite, User $user)
    {
        $this->authorize('manageUsers', $suite);

        if ($error = $this->lastEditorViolation($suite, $user, false)) {
            return back()->withErrors(['member' => $error]);
        }

        $suite->members()->detach($user->id);

        return back()->with('flash.success', 'Member removed.');
    }

    private function clampPrivilegesForViewOnlyUser(User $target, array $privileges): array
    {
        if ($target->is_view_only) {
            $privileges['can_edit'] = false;
            $privileges['can_delete'] = false;
            $privileges['can_run'] = false;
        }

        return $privileges;
    }

    private function lastEditorViolation(TestSuite $suite, User $target, bool $keepsEdit): ?string
    {
        if ($keepsEdit) {
            return null;
        }

        $current = $suite->members()->where('users.id', $target->id)->first();

        if (!$current || !$current->pivot->can_edit) {
            return null;
        }

        $editorCount = $suite->members()->wherePivot('can_edit', true)->count();

        if ($editorCount <= 1) {
            return 'Cannot remove edit access from the last editor of this suite.';
        }

        return null;
    }
}
