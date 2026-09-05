<?php

namespace App\Support;

/**
 * Builds absolute URLs from relative route paths. Queued jobs and listeners
 * run without an active HTTP request, so route() falls back to config('app.url')
 * as the root — which already includes the "/sorify" path segment used by the
 * route group prefix, doubling it. Only the scheme and host are reused; the
 * given path already carries the prefix.
 */
class AppUrl
{
    public static function absolute(string $path): string
    {
        $appUrl = (string) config('app.url');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?? 'http';
        $host = parse_url($appUrl, PHP_URL_HOST) ?? 'localhost';
        $port = parse_url($appUrl, PHP_URL_PORT);

        $root = "{$scheme}://{$host}".($port ? ":{$port}" : '');

        return $root.$path;
    }
}
