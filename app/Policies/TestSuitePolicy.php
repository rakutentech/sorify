<?php

namespace App\Policies;

use App\Models\TestSuite;
use App\Models\User;

class TestSuitePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->is_admin ? true : null;
    }

    public function create(User $user): bool
    {
        return !$user->is_view_only;
    }

    public function view(User $user, TestSuite $suite): bool
    {
        return $suite->privilegesFor($user)['view'];
    }

    public function edit(User $user, TestSuite $suite): bool
    {
        return $suite->privilegesFor($user)['edit'];
    }

    public function delete(User $user, TestSuite $suite): bool
    {
        return $suite->privilegesFor($user)['delete'];
    }

    public function run(User $user, TestSuite $suite): bool
    {
        return $suite->privilegesFor($user)['run'];
    }

    public function manageUsers(User $user, TestSuite $suite): bool
    {
        return $this->edit($user, $suite);
    }

    public function manageSchedule(User $user, TestSuite $suite): bool
    {
        return $this->edit($user, $suite);
    }

    public function manageIntegrations(User $user, TestSuite $suite): bool
    {
        return $this->edit($user, $suite);
    }
}
