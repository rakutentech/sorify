<?php

namespace App\Providers;

use App\Events\TestRunCompleted;
use App\Listeners\NotifyTeamsOnRunCompleted;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        if (!app()->isLocal()) {
            URL::forceScheme('https');
        }

        Vite::createAssetPathsUsing(fn (string $path) => parse_url(asset($path), PHP_URL_PATH));

        Event::listen(TestRunCompleted::class, NotifyTeamsOnRunCompleted::class);

        DevCommands::artisan('queue:listen --queue=sorify,default --tries=1 --timeout=0', 'queue');
    }
}
