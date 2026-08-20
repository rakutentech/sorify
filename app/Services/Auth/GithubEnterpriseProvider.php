<?php

namespace App\Services\Auth;

use Laravel\Socialite\Two\GithubProvider;

/**
 * GitHub OAuth provider that supports GitHub Enterprise by reading the
 * instance base URL from config("services.github.url").
 *
 * Laravel Socialite's built-in GithubProvider hard-codes github.com URLs,
 * so we override the authorize / token / user endpoints to honor a custom
 * base URL (e.g. https://ghe.example.com). When no "url" is configured,
 * behavior falls back to public github.com.
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

        if (in_array('user:email', $this->scopes, true)) {
            $user['email'] = $this->getEmailByToken($token);
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
            return;
        }

        foreach (json_decode($response->getBody(), true) as $email) {
            if ($email['primary'] && $email['verified']) {
                return $email['email'];
            }
        }
    }
}
