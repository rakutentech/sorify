<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function showLogin(): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/sorify/');
        }

        return Inertia::render('Auth/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials)) {
            return back()->withErrors(['email' => 'Invalid email or password.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        $user = Auth::user();
        $attributes = ['last_login_at' => now()];

        if ($user->locale === null) {
            $attributes['locale'] = $request->getPreferredLanguage(array_keys(config('app.supported_locales')));
        }

        $user->forceFill($attributes)->save();

        // Send the user back to the page they originally requested before being
        // bounced to login (stored by the auth middleware via redirect()->guest()).
        // Falls back to the dashboard when no intended URL was stored.
        return redirect()->intended('/sorify/');
    }

    public function showRegister(): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/sorify/');
        }

        return Inertia::render('Auth/Register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'is_view_only' => true,
            'locale' => $request->getPreferredLanguage(array_keys(config('app.supported_locales'))),
        ]);

        Auth::login($user);

        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return redirect('/sorify/');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/sorify/login');
    }

    public function showForgotPassword(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        if ($status !== Password::RESET_LINK_SENT) {
            return back()->withErrors(['email' => __($status)])->onlyInput('email');
        }

        return back()->with('flash.success', __($status));
    }

    public function showResetPassword(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill(['password' => $password])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => __($status)]);
        }

        return redirect()->route('login')->with('flash.success', __($status));
    }

    public function redirectToGithub(): RedirectResponse
    {
        if (! config('services.github.client_id')) {
            return redirect()->route('login')
                ->withErrors(['github' => 'GitHub sign-in is not configured.']);
        }

        return Socialite::driver('github')->redirect();
    }

    public function handleGithubCallback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            return redirect()->route('login')
                ->withErrors(['github' => $request->get('error_description', 'GitHub authentication was cancelled.')]);
        }

        try {
            $githubUser = Socialite::driver('github')->user();
        } catch (\Throwable $e) {
            return redirect()->route('login')
                ->withErrors(['github' => 'Unable to authenticate with GitHub. Please try again.']);
        }

        $email = $githubUser->getEmail();
        $githubId = $githubUser->getId();

        if (! $email) {
            return redirect()->route('login')
                ->withErrors(['github' => 'Your GitHub account has no verified primary email.']);
        }

        $user = User::where('github_id', $githubId)
            ->orWhere('email', $email)
            ->first();

        // Prefer GitHub's avatar URL, but never overwrite a user-uploaded one.
        $githubAvatar = $githubUser->getAvatar();

        if ($user) {
            $user->forceFill([
                'github_id' => $githubId,
                'github_token' => $githubUser->token,
                'github_refresh_token' => $githubUser->refreshToken ?? $user->github_refresh_token,
                'last_login_at' => now(),
            ])->save();

            // Only adopt the GitHub avatar if the user has none yet.
            if (blank($user->avatar) && filled($githubAvatar)) {
                $user->forceFill(['avatar' => $githubAvatar])->save();
            }
        } else {
            $user = User::create([
                'name' => $githubUser->getName() ?? $githubUser->getNickname() ?? $email,
                'avatar' => filled($githubAvatar) ? $githubAvatar : null,
                'email' => $email,
                'password' => null,
                'github_id' => $githubId,
                'github_token' => $githubUser->token,
                'github_refresh_token' => $githubUser->refreshToken,
                'is_view_only' => true,
                'locale' => $request->getPreferredLanguage(array_keys(config('app.supported_locales'))),
            ]);

            // GitHub has verified the email ownership, so mark it verified.
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended('/sorify/');
    }
}
