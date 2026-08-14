<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        $users = User::orderBy('name')->get()->map(fn (User $u) => [
            'id'            => $u->id,
            'name'          => $u->name,
            'email'         => $u->email,
            'is_admin'      => $u->is_admin,
            'created_at'    => $u->created_at,
            'last_login_at' => $u->last_login_at,
        ]);

        return Inertia::render('Admin/Users/Index', ['users' => $users]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:8'],
            'is_admin' => ['boolean'],
        ]);

        User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => $data['password'],
            'is_admin' => $data['is_admin'] ?? false,
        ]);

        return back()->with('flash.success', 'User created successfully.');
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['user' => 'You cannot delete your own account.']);
        }

        $user->delete();

        return back()->with('flash.success', 'User deleted.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['user' => 'You cannot change your own role.']);
        }

        $data = $request->validate([
            'is_admin' => ['required', 'boolean'],
        ]);

        $user->update(['is_admin' => $data['is_admin']]);

        return back()->with('flash.success', 'Role updated.');
    }

    public function resetPassword(User $user): RedirectResponse
    {
        $token = Password::createToken($user);

        $user->sendPasswordResetNotification($token);

        $link = route('password.reset', ['token' => $token, 'email' => $user->email]);

        return back()
            ->with('flash.success', "Reset link generated for {$user->email}.")
            ->with('flash.reset_link', ['email' => $user->email, 'link' => $link]);
    }
}
