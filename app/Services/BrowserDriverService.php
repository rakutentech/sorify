<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Which Playwright browser drivers (chromium / firefox / webkit) are
 * actually installed on this host — used by the dashboard to enable or
 * disable the per-browser checkboxes in the suite's run settings.
 *
 * The check shells out to the same `node` + `playwright` package the test
 * runner uses (resources/playwright/browser-check.cjs), so "installed" means
 * exactly what the runner means: the driver's executable exists where
 * Playwright would launch it from (honoring PLAYWRIGHT_BROWSERS_PATH /
 * SORIFY_BROWSERS_PATH the same way PlaywrightRunnerService does), or —
 * chromium only — a system Chrome install the runner would fall back to.
 *
 * In ephemeral (Docker) mode the drivers live inside the runner image, so
 * this host-level check can report "not installed" while sandboxed runs
 * still work — the checkbox hint is only a local-mode convenience.
 */
class BrowserDriverService
{
    private const CACHE_KEY = 'sorify.browser_drivers.availability.v2';

    /** How long an availability report stays cached (seconds). */
    private const CACHE_TTL_SECONDS = 300;

    private const SUPPORTED_BROWSERS = ['chromium', 'firefox', 'webkit'];

    public function __construct(
        private readonly DockerExecutor $docker,
    ) {}

    /**
     * Availability report: one entry per supported browser, keyed by engine
     * name (installed + where the driver's executable lives / would live).
     *
     * @return array<string, array{installed: bool, executable_path: ?string}>
     */
    public function availability(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $report = $this->runCheckScript();

            $availability = [];
            foreach (self::SUPPORTED_BROWSERS as $browser) {
                $entry = $report[$browser] ?? null;
                $availability[$browser] = [
                    'installed' => (bool) ($entry['installed'] ?? false),
                    'executable_path' => $entry['executable_path'] ?? null,
                ];
            }

            return $availability;
        });
    }

    public function isInstalled(string $browser): bool
    {
        return $this->availability()[$browser]['installed'] ?? false;
    }

    /**
     * Run the node check script and parse its JSON report. Any failure
     * (node missing, playwright package missing, timeout) degrades to
     * "not installed" everywhere rather than breaking the settings page.
     *
     * @return array<string, mixed>
     */
    private function runCheckScript(): array
    {
        $script = (string) config('sorify.browser_check_script_path');

        if ($script === '' || ! is_file($script)) {
            return [];
        }

        $process = new Process(
            command: ['node', $script],
            env: $this->localEnv(),
            timeout: 15,
        );

        try {
            $process->run();
        } catch (\Throwable $e) {
            Log::warning('Browser driver check failed to execute', ['error' => $e->getMessage()]);

            return [];
        }

        if (! $process->isSuccessful()) {
            Log::warning('Browser driver check exited non-zero', [
                'stderr' => Str::limit($process->getErrorOutput(), 500),
            ]);

            return [];
        }

        $payload = json_decode(trim($process->getOutput()), true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * Browser-resolution env mirroring PlaywrightRunnerService::localEnv —
     * an ambient PLAYWRIGHT_BROWSERS_PATH (set by the Docker image) or the
     * SORIFY_BROWSERS_PATH config override must steer the check to the same
     * driver store the runner would use.
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
