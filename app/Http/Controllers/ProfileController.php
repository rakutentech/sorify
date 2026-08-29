<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function show(): Response
    {
        $user = auth()->user();

        return Inertia::render('Profile/Show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->getRawOriginal('name'),
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'is_admin' => $user->is_admin,
                'is_view_only' => $user->is_view_only,
                'has_password' => $user->has_password,
                'github_id' => $user->github_id,
            ],
        ]);
    }

    public function updateName(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:64'],
        ]);

        $user = auth()->user();

        $user->update(['name' => $request->name]);

        return back()->with('flash.success', 'Name updated successfully.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Users who signed up via OAuth have no password yet. They use this
        // endpoint to *set* an initial password; everyone else must supply
        // their current password to *change* it.
        if ($user->has_password) {
            $request->validate([
                'current_password' => ['required'],
                'password' => ['required', 'min:8', 'confirmed'],
                'password_confirmation' => ['required'],
            ]);

            if (! Hash::check($request->current_password, $user->password)) {
                return back()->withErrors(['current_password' => 'Current password is incorrect.']);
            }
        } else {
            $request->validate([
                'password' => ['required', 'min:8', 'confirmed'],
                'password_confirmation' => ['required'],
            ]);
        }

        $user->update(['password' => $request->password]);

        return back()->with('flash.success', 'Password updated successfully.');
    }

    public function updateAvatar(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:2048'],
        ]);

        $user = $request->user();

        // Free the previously uploaded file (but never a remote GitHub URL).
        if (filled($user->avatar) && ! str_starts_with($user->avatar, 'http')) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $request->file('avatar')->store('avatars', 'public');

        $user->forceFill(['avatar' => $path])->save();

        return back()->with('flash.success', 'Avatar updated successfully.');
    }

    public function removeAvatar(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (filled($user->avatar) && ! str_starts_with($user->avatar, 'http')) {
            Storage::disk('public')->delete($user->avatar);
        }

        $user->forceFill(['avatar' => null])->save();

        return back()->with('flash.success', 'Avatar removed.');
    }

    public function updateLocale(Request $request): RedirectResponse
    {
        $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(config('app.supported_locales')))],
        ]);

        auth()->user()->update(['locale' => $request->locale]);

        return back();
    }
}
