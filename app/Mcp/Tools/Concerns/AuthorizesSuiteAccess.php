<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\TestSuite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

trait AuthorizesSuiteAccess
{
    protected function authorizeSuite(string $ability, TestSuite|string $suite): void
    {
        Gate::forUser(Auth::user())->authorize($ability, $suite);
    }

    protected function visibleSuitesQuery(): Builder
    {
        $user = Auth::user();
        $query = TestSuite::query();

        if (!$user->is_admin) {
            $query->whereHas('members', function ($q) use ($user) {
                $q->where('users.id', $user->id)->where('test_suite_user.can_view', true);
            });
        }

        return $query;
    }
}
