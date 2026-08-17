<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
                'id'           => $user->id,
                'name'         => $user->name,
                'email'        => $user->email,
                'is_admin'     => $user->is_admin,
                'is_view_only' => $user->is_view_only,
            ],
        ]);
    }

    public function updateName(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user = auth()->user();

        $user->update(['name' => $request->name]);

        return back()->with('flash.success', 'Name updated successfully.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password'      => ['required'],
            'password'              => ['required', 'min:8', 'confirmed'],
            'password_confirmation' => ['required'],
        ]);

        $user = auth()->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        $user->update(['password' => $request->password]);

        return back()->with('flash.success', 'Password updated successfully.');
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
