<?php

namespace App\Mcp\Tools\Suites;

use App\Http\Requests\Api\StoreSuiteRequest;
use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\TestSuite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateSuiteTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'create_suite';

    protected string $description = 'Create a new test suite.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Suite name.'),
            'description' => $schema->string()->description('Suite description.'),
            'base_url' => $schema->string()->description('Base URL the suite\'s tests run against.'),
            'browser' => $schema->string()->enum(['chromium', 'firefox', 'webkit'])->description('Playwright browser to use.'),
            'headless' => $schema->boolean()->description('Whether to run headless.'),
            'playwright_proxy' => $schema->string()->description('Default HTTP proxy server to use for anything not matched by proxy_rules (e.g. http://proxy:8080).'),
            'proxy_rules' => $schema->array()
                ->items($schema->object([
                    'domain' => $schema->string()->required()->description(
                        "Regular expression tested against the request's hostname (case-insensitive). Examples:\n"
                        .'- "^example\\.com$" — **exact host only**, matches example.com but not foo.example.com.'."\n"
                        .'- "(^|\\.)example\\.com$" — **host or any subdomain**, matches example.com and foo.example.com, but not notexample.com.'."\n"
                        .'- "example\\.com$" — avoid, also matches unrelated hosts like notexample.com.'
                    ),
                    'proxy' => $schema->string()->required()->description('Proxy server to use for requests whose hostname matches domain (e.g. http://proxy:8080).'),
                ]))
                ->description('Per-host proxy overrides, evaluated against every request made during a test (including each hop of a redirect chain). The first matching rule wins; falls back to playwright_proxy when nothing matches.'),
            'variables' => $schema->array()
                ->items($schema->object([
                    'key' => $schema->string()->required()->description('Variable name. Must be a valid JavaScript identifier (letters, digits, underscore; must not start with a digit). Exposed to test code as variables.<key>.'),
                    'value' => $schema->string()->description('Variable value. May contain secrets (tokens, passwords); values are only visible to suite members.'),
                ]))
                ->description('Key/value pairs injected into every test run as a `variables` object in the Playwright code scope. Reference them in test code as variables.KEY. Useful for credentials, URLs, or environment-specific values that should not be hardcoded in test code.'),
            'history_retention' => $schema->integer()->enum([3, 5, 10])->description('Number of past runs to keep per test (3, 5, or 10). Older results and screenshots are pruned automatically. Defaults to 5.'),
            'timeout_ms' => $schema->integer()->enum([10000, 30000, 60000, 120000, 300000, 600000])->description('Per-action timeout in milliseconds (10000, 30000, 60000, 120000, 300000, or 600000). Defaults to 30000.'),
            'take_screenshot' => $schema->boolean()->description('Whether to capture screenshots during test runs. Disable for faster runs. Defaults to true.'),
            'teams_webhook_url' => $schema->string()->description('MS Teams incoming webhook URL to notify when runs complete.'),
            'teams_webhook_proxy' => $schema->string()->description('HTTP proxy to use when posting to the Teams webhook, if any.'),
            'teams_notify_on_success' => $schema->boolean()->description('Whether to notify Teams when a run succeeds.'),
            'teams_notify_on_failure' => $schema->boolean()->description('Whether to notify Teams when a run fails.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $this->authorizeSuite('create', TestSuite::class);

        $data = $request->validate((new StoreSuiteRequest)->rules());
        $proxyRules = $data['proxy_rules'] ?? null;
        unset($data['proxy_rules']);
        $variables = $data['variables'] ?? null;
        unset($data['variables']);

        $suite = TestSuite::create([...$data, 'created_by' => Auth::id()]);

        if ($proxyRules) {
            $suite->proxyRules()->createMany($proxyRules);
        }

        if ($variables) {
            $this->syncVariables($suite, $variables);
        }

        if (Auth::id()) {
            $suite->members()->attach(Auth::id(), [
                'can_view' => true,
                'can_edit' => true,
                'can_delete' => true,
                'can_run' => true,
            ]);
        }

        return Response::structured(['suite' => $suite->load(['proxyRules', 'variables'])->toArray()]);
    }

    /**
     * Replace a suite's variables with the given set (last write wins per key).
     *
     * @param  array<int, array{key: string, value?: string|null}>  $variables
     */
    private function syncVariables(TestSuite $suite, array $variables): void
    {
        $rows = [];
        foreach ($variables as $variable) {
            $key = $variable['key'] ?? null;
            if ($key === null || $key === '') {
                continue;
            }
            $rows[$key] = [
                'key' => $key,
                'value' => $variable['value'] ?? null,
            ];
        }

        if ($rows) {
            $suite->variables()->createMany(array_values($rows));
        }
    }
}
