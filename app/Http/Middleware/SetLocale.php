<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class SetLocale
{
    public function handle(Request $request, Closure $next): mixed
    {
        $supported = array_keys(config('app.supported_locales'));

        $locale = $request->user()?->locale
            ?? $request->getPreferredLanguage($supported)
            ?? config('app.fallback_locale');

        App::setLocale($locale);

        return $next($request);
    }
}
