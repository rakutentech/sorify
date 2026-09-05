<?php

namespace App\Services\Integrations;

use App\Models\GithubApp;
use App\Models\TestSuiteIntegration;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mints GitHub App credentials: a short-lived app JWT (RS256, signed with the
 * app's private key) and, from that, an installation access token for a given
 * owner/repository. Works against both github.com and GitHub Enterprise —
 * each configured GithubApp carries its own base URL, App ID and private
 * key (see the GithubApps admin section).
 */
class GithubAppAuthenticator
{
    private const CACHE_PREFIX = 'sorify:github-app:';

    /**
     * Installation token with actions:write for the app installation on the
     * given owner/repository. Cached until shortly before its expiry.
     * The optional proxy is only used to REACH GitHub — the token itself is
     * identical regardless of proxy, so the cache key ignores it.
     */
    public function accessToken(GithubApp $app, string $owner, string $repo, ?string $proxy = null): string
    {
        return Cache::remember(
            $this->cacheKey($app, "token:{$owner}/{$repo}"),
            now()->addSeconds(300),
            function () use ($app, $owner, $repo, $proxy) {
                $installationId = $this->installationId($app, $owner, $repo, $proxy);

                $response = $this->appRequest($app, $proxy)
                    ->post("{$app->apiBase()}/app/installations/{$installationId}/access_tokens");

                if ($response->failed()) {
                    throw new RuntimeException(
                        "Failed to create a GitHub App installation token for {$owner}/{$repo} via {$app->name} (HTTP {$response->status()})."
                    );
                }

                $expiresAt = $response->json('expires_at');
                $ttl = $expiresAt !== null
                    ? max(60, now()->diffInSeconds(now()->parse($expiresAt)) - 60)
                    : 300;

                // Re-store with the real TTL announced by GitHub (usually 1h).
                Cache::put($this->cacheKey($app, "token:{$owner}/{$repo}"), $response->json('token'), now()->addSeconds($ttl));

                return $response->json('token');
            },
        );
    }

    /**
     * Installation id for the app on the given repository. Throws when the
     * app has not been installed there (or cannot see the repository).
     */
    public function installationId(GithubApp $app, string $owner, string $repo, ?string $proxy = null): int
    {
        return (int) Cache::remember(
            $this->cacheKey($app, "installation:{$owner}/{$repo}"),
            now()->addHour(),
            function () use ($app, $owner, $repo, $proxy) {
                $response = $this->appRequest($app, $proxy)
                    ->get("{$app->apiBase()}/repos/{$owner}/{$repo}/installation");

                if ($response->status() === 404) {
                    throw new RuntimeException(
                        "The GitHub App \"{$app->name}\" is not installed on {$owner}/{$repo} (or the repository does not exist)."
                    );
                }

                if ($response->failed()) {
                    throw new RuntimeException(
                        "Failed to look up the GitHub App installation for {$owner}/{$repo} via {$app->name} (HTTP {$response->status()})."
                    );
                }

                return (int) $response->json('id');
            },
        );
    }

    /**
     * The GitHub App to dispatch as for an integration. No implicit
     * fallback: if the integration has no app (or its app was deleted),
     * dispatching fails with a clear error instead of silently switching
     * to another app.
     */
    public static function resolveApp(?TestSuiteIntegration $integration): ?GithubApp
    {
        return $integration?->githubApp()->first();
    }

    /**
     * Short-lived JWT authenticating as the GitHub App itself (used to mint
     * installation tokens, never to call repository APIs directly).
     */
    private function appJwt(GithubApp $app): string
    {
        $privateKey = $app->privateKeyPem();

        if (! $app->app_id || ! $privateKey) {
            throw new RuntimeException(
                "The GitHub App \"{$app->name}\" cannot dispatch workflows. Configure its App ID and private key (Admin → GitHub Apps)."
            );
        }

        return JWT::encode([
            'iss' => (string) $app->app_id,
            'iat' => now()->subSeconds(60)->getTimestamp(),
            'exp' => now()->addSeconds(540)->getTimestamp(), // GitHub caps at 10 minutes
        ], $privateKey, 'RS256');
    }

    private function appRequest(GithubApp $app, ?string $proxy = null): PendingRequest
    {
        $request = Http::timeout(15)
            ->withToken($this->appJwt($app))
            ->withHeaders($app->apiHeaders());

        if ($proxy !== null) {
            $request->withOptions(['proxy' => $proxy]);
        }

        return $request;
    }

    private function cacheKey(GithubApp $app, string $suffix): string
    {
        return self::CACHE_PREFIX.$app->id.':'.$suffix;
    }
}
