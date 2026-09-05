<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GithubApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin CRUD for the configured GitHub Apps (public github.com and/or
 * GitHub Enterprise instances). Each app carries two purpose switches —
 * sign-in and Actions dispatch — and secrets (client secret, private key)
 * are never echoed back: submitting them blank keeps the stored value.
 *
 * Deleting an app force-disables every active integration that dispatches
 * as it (with an explanatory note shown on the suite page) instead of
 * silently re-routing them to another app.
 */
class GithubAppController extends Controller
{
    public function index(): Response
    {
        $apps = GithubApp::withCount([
            'users',
            'integrations' => fn ($query) => $query->where('enabled', true),
        ])
            ->orderBy('id')
            ->get()
            ->map(fn (GithubApp $app) => [
                'id' => $app->id,
                'name' => $app->name,
                'base_url' => $app->base_url,
                'client_id' => $app->client_id,
                'redirect_uri' => $app->redirect_uri,
                'proxy' => $app->proxy,
                'app_id' => $app->app_id,
                // Secrets themselves are never echoed back (blank keeps the
                // stored value) — but the edit form shows a "value stored"
                // placeholder, so it needs to know whether one exists.
                'has_client_secret' => filled($app->client_secret),
                'has_private_key' => filled($app->private_key),
                'sign_in_enabled' => $app->sign_in_enabled,
                'actions_enabled' => $app->actions_enabled,
                'can_sign_in' => $app->canSignIn(),
                'can_dispatch' => $app->canDispatch(),
                'users_count' => $app->users_count,
                'active_integrations_count' => $app->integrations_count,
            ]);

        return Inertia::render('Admin/GithubApps/Index', [
            'apps' => $apps,
            'defaultRedirectUri' => route('github.callback'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        GithubApp::create($this->attributes($data));

        return back()->with('flash.success', 'GitHub App added.');
    }

    public function update(Request $request, GithubApp $githubApp): RedirectResponse
    {
        $data = $this->validated($request, $githubApp);

        $githubApp->update($this->attributes($data, $githubApp));

        return back()->with('flash.success', 'GitHub App updated.');
    }

    public function destroy(GithubApp $githubApp): RedirectResponse
    {
        // The model's deleting hook force-disables active integrations that
        // dispatch as this app, with an explanatory note on the suite page.
        $githubApp->delete();

        return back()->with('flash.success', 'GitHub App deleted.');
    }

    /**
     * Quick reachability check for the Base URL / Proxy fields of the app
     * form: hits the instance's REST API root. Used live by the admin form
     * while editing, so the admin sees whether the settings work before
     * saving.
     */
    public function testConnection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'base_url' => ['nullable', 'string', 'max:255'],
            'proxy' => ['nullable', 'string', 'max:500'],
        ]);

        $baseUrl = rtrim((string) ($data['base_url'] ?? ''), '/');
        $apiBase = $baseUrl === '' || in_array($baseUrl, ['https://github.com', 'https://www.github.com'], true)
            ? 'https://api.github.com'
            : $baseUrl.'/api/v3';

        try {
            $client = Http::timeout(5)->withHeaders(['Accept' => 'application/vnd.github+json']);

            if ($proxy = trim((string) ($data['proxy'] ?? ''))) {
                $client->withOptions(['proxy' => $proxy]);
            }

            $response = $client->get($apiBase.'/');

            return response()->json([
                'ok' => $response->successful(),
                'status' => $response->status(),
                'url' => $apiBase.'/',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => Str::limit($e->getMessage(), 200),
                'url' => $apiBase.'/',
            ]);
        }
    }

    private function validated(Request $request, ?GithubApp $app = null): array
    {
        $unique = Rule::unique('github_apps', 'client_id')
            ->where(fn ($query) => $query->where('base_url', $request->input('base_url') ?? ''));

        if ($app) {
            $unique->ignore($app->id);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            // Empty means public github.com.
            'base_url' => ['nullable', 'string', 'max:255', 'starts_with:http://,https://'],
            'client_id' => ['required', 'string', 'max:100', $unique],
            // Blank on update keeps the stored secret.
            'client_secret' => [$app ? 'nullable' : 'required', 'string', 'max:1000'],
            'redirect_uri' => ['nullable', 'string', 'max:500'],
            'proxy' => ['nullable', 'string', 'max:500'],
            'app_id' => ['nullable', 'string', 'max:50'],
            'private_key' => ['nullable', 'string', 'max:20000'],
            'sign_in_enabled' => ['nullable', 'boolean'],
            'actions_enabled' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?GithubApp $app = null): array
    {
        $attributes = [
            'name' => $data['name'],
            'base_url' => rtrim((string) ($data['base_url'] ?? '')),
            'client_id' => $data['client_id'],
            'redirect_uri' => $data['redirect_uri'] ?? null,
            'proxy' => $data['proxy'] ?? null,
            'app_id' => $data['app_id'] ?? null,
            'sign_in_enabled' => (bool) ($data['sign_in_enabled'] ?? true),
            'actions_enabled' => (bool) ($data['actions_enabled'] ?? true),
        ];

        // Blank secret fields keep the stored values on update.
        if (filled($data['client_secret'] ?? null)) {
            $attributes['client_secret'] = $data['client_secret'];
        }

        if (filled($data['private_key'] ?? null)) {
            $attributes['private_key'] = $data['private_key'];
        }

        if (! $app && ! array_key_exists('client_secret', $attributes)) {
            $attributes['client_secret'] = '';
        }

        return $attributes;
    }
}
