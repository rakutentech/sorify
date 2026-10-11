<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Auth\GithubEnterpriseProvider;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;
use Prism\Prism\PrismManager;
use App\Services\Agent\Prism\OpenAiCompatibleProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The agent's LLM transport: a Prism provider speaking plain
        // OpenAI-compatible `chat/completions`. Prism's own `openai` driver
        // targets the Responses API instead, which the vLLM / Ollama /
        // OpenRouter-style endpoints behind AgentProfiles don't implement
        // (upstream: prism-php/prism#900) — this provider is the vanilla
        // chat-completions implementation plus reasoning-effort support.
        // Credentials are supplied per request from the user's
        // AgentProfile, so nothing is configured here.
        $this->app->make(PrismManager::class)->extend('openai-compatible', function ($app, array $config) {
            return new OpenAiCompatibleProvider(
                apiKey: $config['api_key'] ?? '',
                url: $config['url'] ?? '',
            );
        });
    }

    public function boot(): void
    {
        if (! app()->isLocal()) {
            URL::forceScheme('https');
        }

        Vite::createAssetPathsUsing(fn (string $path) => parse_url(asset($path), PHP_URL_PATH));

        // Override the built-in GitHub driver so the authorize/token/user
        // endpoints honor the chosen GitHub App's base URL. The config is
        // set at runtime from the database app (AuthController::
        // applyGithubAppConfig) — there are no static GITHUB_* env vars.
        Socialite::extend('github', function ($app) {
            $config = $app['config']['services.github'] ?? [];

            return Socialite::buildProvider(GithubEnterpriseProvider::class, $config);
        });

        // TestRunCompleted → NotifyTeamsOnRunCompleted is wired up by Laravel's
        // event auto-discovery (the listener's handle() type-hints the event).
        // Do NOT register it manually here too — that double-registers and the
        // notification fires twice per run.

        Gate::define('viewLogViewer', fn (User $user) => $user->is_admin);

        // `php artisan dev`: the app server runs on port 8081 (Octane
        // registers a "server" process on 8000; this userland registration
        // takes precedence over the vendor one). Keep .env's APP_URL /
        // ASSET_URL / GITHUB_REDIRECT_URI in sync with this port.
        DevCommands::artisan('octane:start --watch --port=8081', 'server');

        DevCommands::artisan('queue:listen --queue=sorify,default,agent --tries=1 --timeout=0', 'queue');
        DevCommands::artisan('schedule:work', 'scheduler');
    }
}
