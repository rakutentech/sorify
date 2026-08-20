<?php

namespace App\Providers;

use App\Events\TestRunCompleted;
use App\Listeners\NotifyTeamsOnRunCompleted;
use App\Models\User;
use App\Services\Auth\GithubEnterpriseProvider;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if (! app()->isLocal()) {
            URL::forceScheme('https');
        }

        Vite::createAssetPathsUsing(fn (string $path) => parse_url(asset($path), PHP_URL_PATH));

        // Override the built-in GitHub driver so the authorize/token/user
        // endpoints honor a GitHub Enterprise base URL (services.github.url).
        Socialite::extend('github', function ($app) {
            $config = $app['config']['services.github'];

            return Socialite::buildProvider(GithubEnterpriseProvider::class, $config);
        });

        Event::listen(TestRunCompleted::class, NotifyTeamsOnRunCompleted::class);

        Gate::define('viewLogViewer', fn (User $user) => $user->is_admin);

        DevCommands::artisan('queue:listen --queue=sorify,default --tries=1 --timeout=0', 'queue');
        DevCommands::artisan('schedule:work', 'scheduler');
    }
}
