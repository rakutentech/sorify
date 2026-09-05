<?php

namespace App\Support;

/**
 * Assembles the storable attributes of a suite integration from the flat
 * request payload shared by the web controller and the MCP tools. Keeps the
 * per-type payload → config mapping in one place so new integration types
 * (slack, pagerduty, jenkins…) only touch this class plus validation.
 *
 * github_action config: repository, workflow, ref, inputs (name => value).
 * http_request config: url, method, inputs (name => value) and headers
 * (name => value) — all values are plain, visible data.
 */
class IntegrationPayload
{
    /**
     * @param  array<string, mixed>  $data  validated payload: type, label,
     *                                      repository, workflow, ref,
     *                                      url, method, inputs[{name, value}],
     *                                      headers[{name, value}], enabled,
     *                                      trigger_before, trigger_after
     * @return array<string, mixed> attributes ready for
     *                              TestSuiteIntegration::create()/update()
     */
    public static function normalize(array $data): array
    {
        $config = [];

        if (($data['type'] ?? '') === 'github_action') {
            $config = [
                'repository' => trim((string) ($data['repository'] ?? '')),
                'workflow' => ltrim(trim((string) ($data['workflow'] ?? '')), '/'),
                'ref' => trim((string) ($data['ref'] ?? '')),
                'inputs' => self::normalizePairs((array) ($data['inputs'] ?? [])),
                // Proxy for GitHub API traffic lives on the GitHub App
                // (Admin → GitHub Apps), not per integration.
            ];
        } elseif (($data['type'] ?? '') === 'http_request') {
            $config = [
                'url' => self::sanitizeUrl((string) ($data['url'] ?? '')),
                'method' => strtoupper(trim((string) ($data['method'] ?? 'POST'))),
                'inputs' => self::normalizePairs((array) ($data['inputs'] ?? [])),
                'headers' => self::normalizePairs((array) ($data['headers'] ?? [])),
                // Raw JSON body, sent as-is on POST/PUT (validated as JSON).
                'body' => self::presentString($data['body'] ?? null),
                // Optional per-integration proxy for the outgoing request.
                'proxy' => self::presentString($data['proxy'] ?? null),
            ];
        }

        return [
            'type' => $data['type'],
            // Which GitHub App a github_action integration dispatches as.
            'github_app_id' => ($data['type'] ?? '') === 'github_action' && ! empty($data['github_app_id'])
                ? (int) $data['github_app_id']
                : null,
            'label' => self::presentString($data['label'] ?? null),
            'config' => $config ?: null,
            'enabled' => (bool) ($data['enabled'] ?? true),
            'trigger_before' => (bool) ($data['trigger_before'] ?? false),
            'trigger_after' => (bool) ($data['trigger_after'] ?? false),
        ];
    }

    /**
     * Strips fragments and control characters; the full safety check
     * (scheme, host, credentials) happens at request time in
     * HttpRequestIntegrationService — this only keeps stored data tidy.
     */
    private static function sanitizeUrl(string $url): string
    {
        $url = preg_replace('/[\x00-\x1F\x7F]/u', '', trim(explode('#', $url, 2)[0]));

        return trim((string) $url);
    }

    /**
     * @param  array<int, array{name?: string, value?: string|null}>  $rows
     * @return array<string, string>
     */
    private static function normalizePairs(array $rows): array
    {
        $pairs = [];

        foreach ($rows as $row) {
            $name = $row['name'] ?? null;

            if (is_string($name) && $name !== '' && ! str_starts_with($name, 'sorify_')) {
                $pairs[$name] = (string) ($row['value'] ?? '');
            }
        }

        return $pairs;
    }

    private static function presentString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = is_string($value) ? trim($value) : (string) $value;

        return $value === '' ? null : $value;
    }
}
