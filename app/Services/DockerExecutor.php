<?php

namespace App\Services;

use Illuminate\Support\Facades\Process as ProcessFacade;

class DockerExecutor
{
    public function dockerBase(): array
    {
        $command = [(string) config('sorify.execution.docker_binary', 'docker')];

        $host = config('sorify.execution.docker_host');
        if ($host) {
            $command[] = '--host';
            $command[] = (string) $host;
        }

        return $command;
    }

    public function containerName(int $runId, int $testId): string
    {
        return "sorify-run-{$runId}-{$testId}";
    }

    /**
     * $mounts = false builds a self-contained command (used by the readiness
     * smoke check, which reads the spec baked into the image and writes its
     * output to the container's tmpfs).
     */
    public function buildRunnerCommand(string $workDir, string $outDir, array $runnerArgs, int $runId, int $testId, bool $mounts = true, ?string $name = null): array
    {
        $cfg = config('sorify.execution.container');

        $command = array_merge($this->dockerBase(), [
            'run',
            '--rm',
            '--name', $name ?? $this->containerName($runId, $testId),
            '--network', (string) config('sorify.execution.runner_network', 'sorify-runners'),
            '--read-only',
            '--tmpfs', '/tmp:rw,noexec,nosuid,size='.($cfg['tmpfs_size'] ?? '256m'),
            '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges',
            '--init',
            '--user', '1000:1000',
            '--pids-limit', (string) ($cfg['pids_limit'] ?? 512),
            '--memory', (string) ($cfg['memory'] ?? '2g'),
            '--cpus', (string) ($cfg['cpus'] ?? 2),
            '--stop-timeout', '10',
        ]);

        if ($mounts) {
            $command[] = '-v';
            $command[] = $this->mapToHost($workDir).':/work:ro';
            $command[] = '-v';
            $command[] = $this->mapToHost($outDir).':/out:rw';
        }

        $command[] = '-e';
        $command[] = 'PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright';
        $command[] = '-e';
        $command[] = 'NODE_ENV=production';
        $command[] = (string) config('sorify.execution.runner_image', 'sorify-runner:latest');
        $command[] = 'node';
        $command[] = '/app/resources/playwright/runner.cjs';

        return array_merge($command, $runnerArgs);
    }

    public function removeContainer(string $name): void
    {
        ProcessFacade::timeout(15)->run(array_merge($this->dockerBase(), ['rm', '-f', $name]));
    }

    public function buildImage(): array
    {
        return array_merge($this->dockerBase(), [
            'build',
            '-t', (string) config('sorify.execution.runner_image', 'sorify-runner:latest'),
            '-f', 'docker/runner.Dockerfile',
            base_path(),
        ]);
    }

    public function mapToHost(string $path): string
    {
        $hostRunsDir = config('sorify.execution.host_runs_dir');
        if (! $hostRunsDir) {
            return $path;
        }

        $tmpDir = rtrim((string) config('sorify.tmp_dir'), '/');

        return preg_replace('#^'.preg_quote($tmpDir, '#').'#', rtrim((string) $hostRunsDir, '/'), $path);
    }

    /**
     * Symfony Process merges the passed env with the parent environment, so
     * an explicit env alone does not scrub secrets. This neutralizes every
     * inherited variable to an empty string (the value is what leaks — the
     * key name is harmless) before applying the keep-set.
     *
     * @param array<string, string> $keep
     * @return array<string, string>
     */
    public function scrubbedEnv(array $keep): array
    {
        $env = array_fill_keys(array_keys(getenv()), '');

        return array_merge($env, $keep);
    }

    /**
     * Prefix that runs the local-mode node child as a dedicated unprivileged
     * user with kernel-enforced resource caps (Linux only — requires setpriv,
     * which macOS lacks). Returns [] when the safety net is disabled.
     *
     * @param string|null $osFamily override for PHP_OS_FAMILY (testing)
     * @return array<string>
     */
    public function localRunnerPrefix(?string $osFamily = null): array
    {
        $uid = config('sorify.execution.local_test_uid');
        if (! $uid) {
            return [];
        }

        if (($osFamily ?? PHP_OS_FAMILY) !== 'Linux') {
            return [];
        }

        $command = ['setpriv', '--reuid='.(int) $uid, '--regid='.(int) $uid, '--clear-groups'];

        $prlimit = ['prlimit'];
        $nproc = (int) config('sorify.execution.local_max_processes', 256);
        if ($nproc > 0) {
            $prlimit[] = '--nproc='.$nproc;
        }
        $fsize = (int) config('sorify.execution.local_max_filesize', 1073741824);
        if ($fsize > 0) {
            $prlimit[] = '--fsize='.$fsize;
        }
        if (count($prlimit) > 1) {
            $command = array_merge($command, $prlimit);
        }

        return $command;
    }

    public function cliEnv(): array
    {
        return $this->scrubbedEnv([
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: '/tmp',
        ]);
    }
}
