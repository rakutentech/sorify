<?php

namespace App\Services\Agent;

use App\Models\TestSuite;
use App\Services\DockerExecutor;
use App\Services\ExecutionMode;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

/**
 * Maps a live page with the suite's Playwright runner (browser_map agent tool).
 *
 * Executes a generated spec through the same Docker/local runner pipeline as
 * test runs — inheriting the suite's cookies, proxy, and browser settings —
 * and emits a page accessibility snapshot (Playwright ariaSnapshot) that
 * LLMs can turn into high-quality Playwright tests. No TestResult records
 * are written; the output travels back on stdout behind a marker line.
 */
class AgentBrowserService
{
    private string $runnerScript;

    private string $tmpDir;

    public function __construct(
        private readonly DockerExecutor $docker,
        private readonly UrlGuard $urlGuard,
    ) {
        $this->runnerScript = (string) config('sorify.runner_script_path');
        $this->tmpDir = (string) config('sorify.tmp_dir');
    }

    /**
     * @return array{url: string, title: string|null, snapshot: string|null}
     *
     * @throws InvalidArgumentException when the URL is rejected
     */
    public function map(TestSuite $suite, string $url): array
    {
        $url = $this->urlGuard->validate($url);

        $timeoutMs = min(
            (int) ($suite->timeout_ms ?: config('sorify.max_test_timeout_ms', 30000)),
            (int) config('sorify.agent.browser_map_timeout_ms', 30000)
        );

        $token = bin2hex(random_bytes(6));
        $runDir = $this->tmpDir."/agent-map-{$token}";
        $workDir = $runDir.'/work';
        $outDir = $runDir.'/out';

        File::ensureDirectoryExists($workDir, 0755);
        File::ensureDirectoryExists($outDir, 0755);

        $containerName = null;

        try {
            file_put_contents($workDir.'/spec.js', $this->buildSpec($url));

            $runnerArgs = [
                '--spec', 'SPEC',
                '--output', 'OUTPUT',
                '--timeout', (string) $timeoutMs,
                '--browser', $suite->browser ?? 'chromium',
                '--headless', 'true',
                '--screenshot-mode', 'disabled',
            ];

            if ($suite->playwright_proxy) {
                $runnerArgs[] = '--proxy';
                $runnerArgs[] = $suite->playwright_proxy;
            }

            $proxyRules = $suite->proxyRules;
            if ($proxyRules->isNotEmpty()) {
                file_put_contents($workDir.'/proxy-rules.json', $proxyRules->map(fn ($rule) => [
                    'domain' => $rule->domain,
                    'proxy' => $rule->proxy,
                ])->values()->toJson());
                $runnerArgs[] = '--proxy-rules';
                $runnerArgs[] = 'PROXY_RULES';
            }

            $cookies = $suite->cookies;
            if ($cookies->isNotEmpty()) {
                file_put_contents($workDir.'/cookies.json', $cookies->map(fn ($c) => [
                    'name' => $c->name,
                    'value' => $c->value ?? '',
                    'domain' => $c->domain,
                    'path' => $c->path,
                    'url' => $c->url,
                    'expires' => $c->expires,
                    'httpOnly' => (bool) $c->http_only,
                    'secure' => (bool) $c->secure,
                    'sameSite' => $c->same_site,
                ])->values()->toJson(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $runnerArgs[] = '--cookies';
                $runnerArgs[] = 'COOKIES';
            }

            if (ExecutionMode::current() === ExecutionMode::EPHEMERAL) {
                $paths = [
                    'SPEC' => '/work/spec.js',
                    'OUTPUT' => '/out',
                    'PROXY_RULES' => '/work/proxy-rules.json',
                    'COOKIES' => '/work/cookies.json',
                ];
                $runnerArgs = array_map(fn ($arg) => $paths[$arg] ?? $arg, $runnerArgs);

                $containerName = "sorify-agent-{$token}";
                $command = $this->docker->buildRunnerCommand($workDir, $outDir, $runnerArgs, 0, 0, name: $containerName);
                $env = $this->docker->cliEnv();
            } else {
                $paths = [
                    'SPEC' => $workDir.'/spec.js',
                    'OUTPUT' => $outDir,
                    'PROXY_RULES' => $workDir.'/proxy-rules.json',
                    'COOKIES' => $workDir.'/cookies.json',
                ];
                $runnerArgs = array_map(fn ($arg) => $paths[$arg] ?? $arg, $runnerArgs);

                $prefix = $this->docker->localRunnerPrefix();
                if ($prefix) {
                    @chmod($workDir, 0777);
                    @chmod($outDir, 0777);
                }

                $command = array_merge($prefix, ['node', $this->runnerScript], $runnerArgs);
                $env = $this->localEnv();
            }

            $process = new Process(
                command: $command,
                env: $env,
                timeout: ($timeoutMs / 1000) + 15
            );
            $process->run();

            return $this->extractMap($process->getOutput());
        } finally {
            if ($containerName) {
                $this->docker->removeContainer($containerName);
            }

            File::deleteDirectory($runDir);
        }
    }

    /**
     * The generated spec navigates to the target and prints the page map
     * behind a marker so it can be separated from the runner's own JSON
     * result line.
     */
    private function buildSpec(string $url): string
    {
        $target = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<JS
        const target = {$target};

        await page.goto(target, { waitUntil: 'domcontentloaded' });

        // Give SPAs a beat to render before snapshotting.
        await page.waitForTimeout(1000);

        let snapshot = null;
        try {
            snapshot = await page.locator('body').ariaSnapshot();
        } catch (e) {
            // ariaSnapshot needs Playwright >= 1.49 — fall back to basic markup info.
            snapshot = await page.evaluate(() => ({
                inputs: Array.from(document.querySelectorAll('input, select, textarea, button, a')).slice(0, 200)
                    .map(el => ({ tag: el.tagName.toLowerCase(), id: el.id || null, name: el.getAttribute('name'), text: (el.innerText || el.value || '').slice(0, 60) })),
            }));
            snapshot = JSON.stringify(snapshot);
        }

        const data = {
            url: page.url(),
            title: await page.title(),
            snapshot,
        };

        console.log('SORIFY_MAP:' + JSON.stringify(data));
        JS;
    }

    /**
     * @return array{url: string, title: string|null, snapshot: string|null}
     */
    private function extractMap(string $stdout): array
    {
        foreach (explode("\n", $stdout) as $line) {
            if (str_starts_with($line, 'SORIFY_MAP:')) {
                $decoded = json_decode(substr($line, strlen('SORIFY_MAP:')), true);

                if (is_array($decoded)) {
                    return [
                        'url' => (string) ($decoded['url'] ?? ''),
                        'title' => $decoded['title'] ?? null,
                        'snapshot' => $decoded['snapshot'] ?? null,
                    ];
                }
            }
        }

        throw new \RuntimeException('Browser map produced no output — the page may have failed to load or the runner is not available.');
    }

    /**
     * Mirrors PlaywrightRunnerService::localEnv — browsers path resolution
     * with real HOME passthrough.
     *
     * @return array<string, string>
     */
    private function localEnv(): array
    {
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'NODE_ENV' => 'production',
        ];

        $browsersPath = getenv('PLAYWRIGHT_BROWSERS_PATH')
            ?: (string) (config('sorify.execution.browsers_path') ?: '');

        if ($browsersPath !== '') {
            $env['PLAYWRIGHT_BROWSERS_PATH'] = $browsersPath;
            $env['HOME'] = '/tmp';
        } else {
            $env['HOME'] = getenv('HOME') ?: '/tmp';
        }

        return $this->docker->scrubbedEnv($env);
    }
}
