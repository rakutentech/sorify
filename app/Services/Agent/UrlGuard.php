<?php

namespace App\Services\Agent;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * SSRF protection for agent URL tools (fetch_url / browser_map).
 *
 * Only public http(s) URLs are allowed: the host must resolve to addresses
 * outside private/reserved ranges. Redirects are followed manually so every
 * hop is re-validated — a public URL that redirects to an internal host is a
 * classic SSRF bypass.
 */
class UrlGuard
{
    private bool $allowPrivateIps;

    public function __construct(?bool $allowPrivateIps = null)
    {
        $this->allowPrivateIps = $allowPrivateIps ?? (bool) config('sorify.agent.allow_private_ips', false);
    }

    /**
     * Validate a URL and return it normalized.
     *
     * @throws InvalidArgumentException
     */
    public function validate(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new InvalidArgumentException('URL is required.');
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only http and https URLs are allowed.');
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            throw new InvalidArgumentException('URL has no host.');
        }

        $this->assertPublicHost($host);

        return $url;
    }

    /**
     * GET a URL following redirects, re-validating every hop.
     *
     * @param  array<string, mixed>  $options
     * @return array{url: string, status: int, headers: array<string, mixed>, body: string}
     *
     * @throws InvalidArgumentException when a redirect target is not allowed
     */
    public function get(string $url, array $options = []): array
    {
        $url = $this->validate($url);
        $maxRedirects = (int) ($options['max_redirects'] ?? 5);

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'timeout' => $options['timeout'] ?? 20,
                'connect_timeout' => $options['connect_timeout'] ?? 10,
                ...(isset($options['headers']) ? ['headers' => $options['headers']] : []),
            ])->get($url);

            if ($response->redirect() && $location = $response->header('Location')) {
                if ($hop === $maxRedirects) {
                    throw new InvalidArgumentException("Too many redirects (more than {$maxRedirects}).");
                }

                $url = $this->validate($this->resolveRedirect($url, $location));

                continue;
            }

            return [
                'url' => $url,
                'status' => $response->status(),
                'headers' => $response->headers(),
                'body' => (string) $response->body(),
            ];
        }

        throw new InvalidArgumentException('Too many redirects.');
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertPublicHost(string $host): void
    {
        if ($this->allowPrivateIps) {
            return;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = gethostbynamel($host) ?: [];

            if ($ips === []) {
                throw new InvalidArgumentException("Could not resolve host [{$host}].");
            }
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidArgumentException('Requests to private or reserved IP ranges are not allowed.');
            }
        }
    }

    private function resolveRedirect(string $from, string $location): string
    {
        if (! parse_url($location, PHP_URL_HOST)) {
            $base = parse_url($from);

            return sprintf(
                '%s://%s%s%s',
                $base['scheme'] ?? 'https',
                $base['host'] ?? '',
                isset($base['port']) ? ":{$base['port']}" : '',
                str_starts_with($location, '/') ? $location : '/'.$location
            );
        }

        return $location;
    }
}
