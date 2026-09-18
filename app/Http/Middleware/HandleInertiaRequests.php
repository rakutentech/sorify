<?php

namespace App\Http\Middleware;

use App\Models\AgentProfile;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'is_admin' => $user->is_admin,
                    // AI-agent availability: whether the user has at least
                    // one agent profile configured, and whether an admin has
                    // disabled agents for them (drives the AI buttons).
                    'has_agent_profile' => AgentProfile::query()->where('user_id', $user->id)->exists(),
                    'agent_disabled' => (bool) $user->agent_disabled,
                ] : null,
            ],
            'locale' => app()->getLocale(),
            'flash' => [
                'success' => $request->session()->get('flash.success'),
                'error' => $request->session()->get('flash.error'),
                'new_token' => $request->session()->get('flash.new_token'),
                'reset_link' => $request->session()->get('flash.reset_link'),
            ],
        ];
    }
}
