<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One configured GitHub App — public github.com or a GitHub Enterprise
 * instance — serving both sign-in (OAuth user flow) and Actions dispatch
 * (installation tokens). `github_id` values are only unique PER app, which
 * is why users and integrations reference the app they belong to.
 */
class GithubApp extends Model
{
    protected $fillable = [
        'name',
        'base_url',
        'client_id',
        'client_secret',
        'redirect_uri',
        'proxy',
        'app_id',
        'private_key',
        'sign_in_enabled',
        'actions_enabled',
    ];

    protected $casts = [
        'client_secret' => 'encrypted',
        'private_key' => 'encrypted',
        'sign_in_enabled' => 'boolean',
        'actions_enabled' => 'boolean',
    ];

    /** Never serialized to clients — the admin UI uses blank-to-keep. */
    protected $hidden = ['client_secret', 'private_key'];

    /**
     * Deleting an app force-disables every active integration that
     * dispatches as it — with a note shown on the suite page — instead of
     * silently re-routing them to another app. Runs for every deletion
     * path (admin UI, artisan, …).
     */
    protected static function booted(): void
    {
        static::deleting(function (GithubApp $app) {
            $app->integrations()
                ->where('type', 'github_action')
                ->where('enabled', true)
                ->update([
                    'enabled' => false,
                    'disabled_note' => "The GitHub App \"{$app->name}\" was deleted by an administrator. Pick another GitHub App and re-enable this integration.",
                ]);
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(TestSuiteIntegration::class);
    }

    public function isPublic(): bool
    {
        $base = rtrim((string) $this->base_url, '/');

        return $base === '' || $base === 'https://github.com' || $base === 'https://www.github.com';
    }

    /**
     * REST API base: api.github.com for public GitHub, {base}/api/v3 for
     * GitHub Enterprise.
     */
    public function apiBase(): string
    {
        if ($this->isPublic()) {
            return 'https://api.github.com';
        }

        return rtrim((string) $this->base_url, '/').'/api/v3';
    }

    /**
     * Site base URL for OAuth flows (authorize/token/user endpoints).
     */
    public function siteUrl(): string
    {
        return rtrim((string) $this->base_url, '/') ?: 'https://github.com';
    }

    /**
     * Env vars can't hold real newlines; the admin UI stores "\n"-escaped
     * PEM one-liners, exactly like GITHUB_APP_PRIVATE_KEY.
     */
    public function privateKeyPem(): ?string
    {
        $key = $this->private_key;

        if (! is_string($key) || $key === '') {
            return null;
        }

        return str_contains($key, '\\n')
            ? str_replace('\\n', "\n", $key)
            : $key;
    }

    public function canSignIn(): bool
    {
        return $this->sign_in_enabled
            && $this->client_id !== null && $this->client_id !== ''
            && $this->client_secret !== null && $this->client_secret !== '';
    }

    public function canDispatch(): bool
    {
        return $this->actions_enabled
            && $this->app_id !== null && $this->app_id !== ''
            && $this->privateKeyPem() !== null;
    }

    public function apiHeaders(): array
    {
        return [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
    }

    /**
     * Apps offered for sign-in, in display order.
     *
     * @return Collection<int, self>
     */
    public static function signInApps()
    {
        return static::query()->where('sign_in_enabled', true)->orderBy('id')->get()->filter->canSignIn();
    }

    /**
     * Apps usable for Actions dispatch, in display order.
     *
     * @return Collection<int, self>
     */
    public static function dispatchApps()
    {
        return static::query()->where('actions_enabled', true)->orderBy('id')->get()->filter->canDispatch();
    }
}
