<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentProfile;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AdminNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(private readonly AdminNotificationService $adminNotifications) {}

    public function index(): Response
    {
        // Users that have at least one agent profile configured (one query).
        $usersWithProfiles = AgentProfile::query()->pluck('user_id')->unique()->flip();

        $users = User::orderBy('name')->get()->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'avatar_url' => $u->avatar_url,
            'is_admin' => $u->is_admin,
            'is_view_only' => $u->is_view_only,
            'created_at' => $u->created_at,
            'last_login_at' => $u->last_login_at,
            'agent_disabled' => (bool) $u->agent_disabled,
            'has_agent_profile' => $usersWithProfiles->has($u->id),
        ]);

        return Inertia::render('Admin/Users/Index', ['users' => $users]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:8'],
            'role' => ['required', Rule::in(['admin', 'member', 'viewer'])],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'is_admin' => $data['role'] === 'admin',
            'is_view_only' => $data['role'] === 'viewer',
        ]);

        ActivityLogger::log('user_created', $request->user(), null, $user, ['user_name' => $user->name]);

        $this->adminNotifications->notifyNewUser($user, 'admin', $request->user()->name);

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

        // Agent allow/deny toggle from the users table's switch.
        if ($request->has('agent_disabled')) {
            $data = $request->validate([
                'agent_disabled' => ['required', 'boolean'],
            ]);

            $user->update(['agent_disabled' => $data['agent_disabled']]);

            return back()->with('flash.success', $data['agent_disabled']
                ? 'AI agent disabled for this user.'
                : 'AI agent enabled for this user.');
        }

        $data = $request->validate([
            'role' => ['required', Rule::in(['admin', 'member', 'viewer'])],
        ]);

        $user->update([
            'is_admin' => $data['role'] === 'admin',
            'is_view_only' => $data['role'] === 'viewer',
        ]);

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
