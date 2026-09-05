<?php

namespace App\Services\Integrations;

use App\Models\TestRun;
use App\Models\TestSuiteIntegration;
use App\Support\AppUrl;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Executes "http_request" suite integrations: a plain HTTP call to a
 * user-configured URL (with optional headers) before and/or after each run.
 *
 *  - GET: inputs are appended as query parameters and take priority over
 *    same-named parameters already in the URL.
 *  - POST/PUT/DELETE: inputs are sent as an application/json body; query
 *    parameters in the URL are kept untouched.
 *
 * Inputs named with a "sorify_" prefix are reserved for context Sorify
 * injects (run id, suite id, run url — plus outcome counts post-run), the
 * same contract as the GitHub Actions integration.
 *
 * Security: the URL must be plain http(s) without embedded credentials,
 * fragments or control characters, and every query value is re-encoded via
 * http_build_query() so a value like "a&admin=1" can never inject an extra
 * parameter. Header names must be RFC 7230 tokens and header values are
 * stripped of CR/LF/NUL so a value can never smuggle extra headers into the
 * request. Input/header names are additionally regex-validated on save.
 */
class HttpRequestIntegrationService
{
    public const METHODS = ['GET', 'POST', 'PUT', 'DELETE'];

    /**
     * Blocking execution for pre-run triggers: a non-2xx response throws
     * (the pre-run job fails the whole run), 2xx lets the tests start.
     */
    public function executeAndWait(TestSuiteIntegration $integration, TestRun $run): void
    {
        $response = $this->send($integration, $run, 'before');

        if (! $response->successful()) {
            throw new RuntimeException($this->failureMessage($integration, $response));
        }
    }

    /**
     * Fire-and-forget execution for post-run triggers. Failures are logged,
     * never thrown.
     */
    public function dispatchForRun(TestSuiteIntegration $integration, TestRun $run, string $phase): void
    {
        try {
            $response = $this->send($integration, $run, $phase);

            if (! $response->successful()) {
                report(new RuntimeException($this->failureMessage($integration, $response)));
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function send(TestSuiteIntegration $integration, TestRun $run, string $phase): Response
    {
        $method = $this->method($integration);
        [$url, $options] = $this->buildRequest($integration, $run, $phase);

        $request = Http::timeout(max(1, (int) config('sorify.integrations.http_request.timeout', 15)))
            ->acceptJson()
            ->withHeaders($this->headers($integration));

        // Optional per-integration proxy, mirroring the Teams webhook proxy.
        if ($proxy = trim((string) $integration->config('proxy', ''))) {
            $request->withOptions(['proxy' => $proxy]);
        }

        return $request->send($method, $url, $options);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>} [url, request options]
     */
    private function buildRequest(TestSuiteIntegration $integration, TestRun $run, string $phase): array
    {
        $url = $this->validatedUrl($integration);
        $values = $this->inputValues($integration, $run, $phase);
        $method = $this->method($integration);

        $parts = parse_url($url);
        $scheme = (string) ($parts['scheme'] ?? '');
        $host = (string) ($parts['host'] ?? '');

        // Rebuild the origin + path exactly; the query is rebuilt from the
        // parsed parameters so every key/value is safely re-encoded.
        $base = $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '/');

        parse_str((string) ($parts['query'] ?? ''), $query);

        $options = [];
        $rawBody = trim((string) $integration->config('body', ''));

        if ($method === 'GET' || ($rawBody !== '' && in_array($method, ['POST', 'PUT'], true))) {
            // No (structured) body: inputs take priority over same-named URL
            // parameters. With a raw body the inputs would otherwise be
            // lost, so they become query parameters too.
            $query = array_merge($query, $values);
        } elseif ($values !== []) {
            // Body request: inputs become the JSON body, URL query params
            // are preserved.
            $options['json'] = $values;
        }

        if ($rawBody !== '' && in_array($method, ['POST', 'PUT'], true)) {
            // The user's raw JSON body is sent exactly as configured.
            $options['body'] = $rawBody;
            $options['headers'] = ['Content-Type' => 'application/json'];
        }

        if ($query !== []) {
            // RFC 3986 encoding — values containing &, =, # or similar can
            // neither inject additional parameters nor break out of the URI.
            $base .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return [$base, $options];
    }

    /**
     * Configured headers, sanitized: names must be RFC 7230 token
     * characters, values are stripped of CR/LF/NUL so header injection via
     * a value like "x: y\r\nEvil: 1" is impossible.
     *
     * @return array<string, string>
     */
    private function headers(TestSuiteIntegration $integration): array
    {
        $headers = [];

        foreach ((array) $integration->config('headers', []) as $name => $value) {
            $name = trim((string) $name);

            if ($name === '') {
                continue;
            }

            if (! preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name)) {
                throw new RuntimeException("Integration '{$integration->label}' has an invalid header name '{$name}'.");
            }

            $headers[$name] = str_replace(["\r", "\n", "\0"], '', (string) $value);
        }

        return $headers;
    }

    /**
     * Strict runtime URL validation: http(s) only, a host must be present,
     * embedded credentials (user:pass@host) and control characters are
     * rejected. The fragment (if any) was already stripped on save but is
     * dropped here too, defensively.
     */
    private function validatedUrl(TestSuiteIntegration $integration): string
    {
        $url = preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $integration->config('url'));

        if (! is_string($url) || $url === '') {
            throw new RuntimeException("Integration '{$integration->label}' has no URL configured.");
        }

        $parts = parse_url(explode('#', $url, 2)[0]);

        if ($parts === false) {
            throw new RuntimeException("Integration '{$integration->label}' has a malformed URL configured.");
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException("Integration '{$integration->label}' must use an http:// or https:// URL.");
        }

        if (empty($parts['host']) || preg_match('/[\x00-\x1F\x7F]/', $parts['host']) === 1) {
            throw new RuntimeException("Integration '{$integration->label}' has an invalid host configured.");
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException("Integration '{$integration->label}' must not embed credentials in the URL.");
        }

        return explode('#', $url, 2)[0];
    }

    /**
     * Merged input values plus the sorify_* context (sorify_-prefixed user
     * rows were rejected on save, so these cannot be overridden).
     *
     * @return array<string, string>
     */
    private function inputValues(TestSuiteIntegration $integration, TestRun $run, string $phase): array
    {
        $values = (array) $integration->config('inputs', []);

        $values['sorify_run_id'] = (string) $run->id;
        $values['sorify_suite_id'] = (string) $run->test_suite_id;
        $values['sorify_run_url'] = AppUrl::absolute(route('runs.show', $run, absolute: false));

        if ($phase === 'after') {
            $values['sorify_run_status'] = (string) $run->status;
            $values['sorify_passed_count'] = (string) (int) $run->passed_count;
            $values['sorify_failed_count'] = (string) (int) $run->failed_count;
            $values['sorify_error_count'] = (string) (int) $run->error_count;
        }

        return $values;
    }

    private function method(TestSuiteIntegration $integration): string
    {
        $method = strtoupper((string) $integration->config('method', 'POST'));

        if (! in_array($method, self::METHODS, true)) {
            throw new RuntimeException("Integration '{$integration->label}' has an unsupported method '{$method}'.");
        }

        return $method;
    }

    private function failureMessage(TestSuiteIntegration $integration, Response $response): string
    {
        $label = $integration->label ?: Str::limit((string) $integration->config('url'), 80);

        return "HTTP request to '{$label}' returned {$response->status()}: ".Str::limit(trim($response->body()), 300);
    }
}
