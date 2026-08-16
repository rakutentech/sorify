<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\TestSuite;
use App\Models\User;

trait ManagesSuiteMemberPrivileges
{
    protected function clampPrivilegesForViewOnlyUser(User $target, array $privileges): array
    {
        if ($target->is_view_only) {
            $privileges['can_edit'] = false;
            $privileges['can_delete'] = false;
            $privileges['can_run'] = false;
        }

        return $privileges;
    }

    protected function lastEditorViolation(TestSuite $suite, User $target, bool $keepsEdit): ?string
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
