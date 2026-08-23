<?php

namespace App\Mcp\Tools\Suites;

use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\TestSuite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class UploadSuiteCookiesTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'upload_suite_cookies';

    protected string $description = 'Replace a test suite\'s cookie set with the given cookies. Cookies are added to the Playwright browser context before any page is created, so tests start already authenticated. Accepts either a cookies array directly, or a Playwright storageState JSON string (which is parsed to extract its cookies). Passing an empty cookies array clears the suite\'s cookies.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
            'cookies' => $schema->array()
                ->items($schema->object([
                    'name' => $schema->string()->required()->description('Cookie name.'),
                    'value' => $schema->string()->description('Cookie value.'),
                    'domain' => $schema->string()->description('Cookie domain (e.g. "example.com"). Either domain or url is required.'),
                    'path' => $schema->string()->description('Cookie path. Defaults to "/".'),
                    'url' => $schema->string()->description('Cookie URL. Either domain or url is required.'),
                    'expires' => $schema->integer()->description('Unix epoch seconds. Omit or set to -1 for a session cookie.'),
                    'http_only' => $schema->boolean()->description('Whether the cookie is HttpOnly.'),
                    'secure' => $schema->boolean()->description('Whether the cookie is Secure.'),
                    'same_site' => $schema->string()->enum(['Strict', 'Lax', 'None'])->description('SameSite attribute.'),
                ]))
                ->description('Cookies to store on the suite. Replaces the entire existing set. Pass [] to clear.'),
            'storage_state' => $schema->string()->description('A Playwright storageState JSON string ({cookies: [...], origins: [...]}). When provided, its cookies array is extracted and used instead of the cookies argument. Useful for uploading a cookie snapshot captured by the Sorify Recorder extension.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'suite_id' => 'required|integer|exists:test_suites,id',
            'cookies' => 'nullable|array',
            'storage_state' => 'nullable|string',
        ]);

        $suite = TestSuite::findOrFail($data['suite_id']);
        $this->authorizeSuite('edit', $suite);

        $cookies = $data['cookies'] ?? null;

        // If a Playwright storageState JSON string was provided, parse it and
        // extract its cookies array. This is the shape the Sorify Recorder
        // extension produces when it snapshots cookies during a recording.
        if (! empty($data['storage_state'])) {
            $parsed = json_decode($data['storage_state'], true);
            if (is_array($parsed) && isset($parsed['cookies']) && is_array($parsed['cookies'])) {
                $cookies = $parsed['cookies'];
            } else {
                return Response::structured([
                    'error' => 'storage_state must be a valid JSON object with a "cookies" array.',
                ]);
            }
        }

        // Validate the individual cookie shapes using rules that mirror StoreSuiteRequest.
        if ($cookies !== null) {
            $cookieRules = [
                'cookies' => 'nullable|array',
                'cookies.*.name' => ['required_with:cookies', 'string', 'max:255'],
                'cookies.*.value' => 'nullable|string',
                'cookies.*.domain' => 'nullable|string|max:255',
                'cookies.*.path' => 'nullable|string|max:255',
                'cookies.*.url' => 'nullable|string|max:500|url',
                'cookies.*.expires' => 'nullable|integer',
                'cookies.*.http_only' => 'nullable|boolean',
                'cookies.*.secure' => 'nullable|boolean',
                'cookies.*.same_site' => 'nullable|string|in:Strict,Lax,None',
                'cookies.*' => [function (string $attribute, mixed $value, \Closure $fail) {
                    if (! is_array($value)) {
                        return;
                    }
                    if (empty($value['url']) && empty($value['domain'])) {
                        $fail('The :attribute must set either a domain or a url.');
                    }
                }],
            ];
            $validator = validator(['cookies' => $cookies], $cookieRules);
            if ($validator->fails()) {
                return Response::structured([
                    'error' => 'Invalid cookies.',
                    'details' => $validator->errors()->toArray(),
                ]);
            }
        }

        $this->syncCookies($suite, $cookies);

        $count = $suite->cookies()->count();

        return Response::structured([
            'suite_id' => $suite->id,
            'cookie_count' => $count,
            'message' => $count === 0
                ? 'All cookies cleared.'
                : "{$count} cookie(s) stored. They will be injected into every test run for this suite.",
        ]);
    }

    /**
     * Replace a suite's cookies with the given set (last write wins per name+domain+path).
     *
     * @param  array<int, array{name: string, value?: string|null, domain?: string|null, path?: string|null, url?: string|null, expires?: int|null, http_only?: bool|null, secure?: bool|null, same_site?: string|null}>|null  $cookies
     */
    private function syncCookies(TestSuite $suite, ?array $cookies): void
    {
        $suite->cookies()->delete();

        if (! $cookies) {
            return;
        }

        $rows = [];
        foreach ($cookies as $cookie) {
            $name = $cookie['name'] ?? null;
            if ($name === null || $name === '') {
                continue;
            }
            $domain = isset($cookie['domain']) && $cookie['domain'] !== '' ? $cookie['domain'] : null;
            $path = isset($cookie['path']) && $cookie['path'] !== '' ? $cookie['path'] : null;
            $url = isset($cookie['url']) && $cookie['url'] !== '' ? $cookie['url'] : null;
            if ($domain === null && $url === null) {
                continue;
            }
            $key = $name.'|'.$domain.'|'.$path;
            $rows[$key] = [
                'name' => $name,
                'value' => $cookie['value'] ?? null,
                'domain' => $domain,
                'path' => $path,
                'url' => $url,
                'expires' => isset($cookie['expires']) ? (int) $cookie['expires'] : null,
                'http_only' => (bool) ($cookie['http_only'] ?? false),
                'secure' => (bool) ($cookie['secure'] ?? false),
                'same_site' => isset($cookie['same_site']) && $cookie['same_site'] !== '' ? $cookie['same_site'] : null,
            ];
        }

        if ($rows) {
            $suite->cookies()->createMany(array_values($rows));
        }
    }
}
