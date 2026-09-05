<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Two\GithubProvider;

/**
 * GitHub OAuth provider that supports GitHub Enterprise by reading the
 * instance base URL from config("services.github.url").
 *
 * Laravel Socialite's built-in GithubProvider hard-codes github.com URLs,
 * so we override the authorize / token / user endpoints to honor a custom
 * base URL (e.g. https://ghe.example.com). When no "url" is configured,
 * behavior falls back to public github.com.
 *
 * Supports both classic OAuth Apps and GitHub Apps as the client: GitHub
 * Apps (client IDs starting with "Iv1.") must not receive a `scope`
 * parameter on the authorize URL — GitHub rejects the whole request
 * otherwise. Their user permissions (e.g. email access) are configured on
 * the app itself, not via scopes.
 */
class GithubEnterpriseProvider extends GithubProvider
{
    protected function getBaseUrl(): string
    {
        return rtrim((string) config('services.github.url'), '/') ?: 'https://github.com';
    }

    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase($this->getBaseUrl().'/login/oauth/authorize', $state);
    }

    /**
     * GitHub Apps reject authorize requests that carry a `scope` parameter
     * ("The requested scope is invalid, unknown, or malformed"). Classic
     * OAuth Apps keep the configured user:email scope.
     */
    protected function getCodeFields($state = null)
    {
        $fields = parent::getCodeFields($state);

        if ($this->isGithubApp()) {
            unset($fields['scope']);
        }

        return $fields;
    }

    protected function getTokenUrl()
    {
        return $this->getBaseUrl().'/login/oauth/access_token';
    }

    protected function getUserByToken($token)
    {
        $userUrl = $this->getBaseUrl().'/api/v3/user';

        $response = $this->getHttpClient()->get(
            $userUrl, $this->getRequestOptions($token)
        );

        $user = json_decode($response->getBody(), true);

        // Fetch the verified primary email whenever it is reachable:
        // classic OAuth Apps via the user:email scope, GitHub Apps via
        // their "Email addresses" account permission.
        if (in_array('user:email', $this->scopes, true) || $this->isGithubApp()) {
            $email = $this->getEmailByToken($token);

            if ($email) {
                $user['email'] = $email;
            }
        }

        return $user;
    }

    protected function getEmailByToken($token)
    {
        $emailsUrl = $this->getBaseUrl().'/api/v3/user/emails';

        try {
            $response = $this->getHttpClient()->get(
                $emailsUrl, $this->getRequestOptions($token)
            );
        } catch (\Exception $e) {
            // Most often a 404/403: the GitHub App lacks the "Email
            // addresses" account permission for this user's token.
            Log::warning('GitHub email lookup failed', [
                'error' => $e->getMessage(),
                'hint' => 'Enable "Email addresses: Read-only" on the GitHub App (Permissions & events → Account permissions) and re-authorize.',
            ]);

            return;
        }

        foreach (json_decode($response->getBody(), true) as $email) {
            if ($email['primary'] && $email['verified']) {
                return $email['email'];
            }
        }
    }

    /**
     * GitHub App client IDs start with "Iv1." (classic OAuth App IDs start
     * with "Ov").
     */
    private function isGithubApp(): bool
    {
        return str_starts_with((string) $this->clientId, 'Iv');
    }
}
