<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process as ProcessFacade;

class EphemeralReadinessChecker
{
    private const CACHE_KEY = 'sorify.execution.readiness';

    private const ROOT_SOCKET = 'unix:///var/run/docker.sock';

    /**
     * @param \Closure|null $runner receives the command array, returns
     *   ['exitCode' => int, 'output' => string, 'errorOutput' => string].
     *   Null shells out to the real Docker CLI.
     */
    public function __construct(private readonly ?\Closure $runner = null)
    {
    }

    public function check(bool $fresh = false): array
    {
        if (! $fresh) {
            $cached = Cache::get(self::CACHE_KEY);
            if ($cached !== null) {
                return $cached;
            }
        }

        $checks = [];

        $cli = $this->dockerCliCheck();
        $checks[] = $cli;
        if ($cli['status'] === 'fail') {
            return $this->finish($checks);
        }

        $daemon = $this->daemonCheck();
        $checks[] = $daemon;
        if ($daemon['status'] === 'fail') {
            return $this->finish($checks);
        }

        $checks[] = $this->imageCheck();
        $checks[] = $this->networkCheck();
        $checks[] = $this->gvisorCheck();
        $checks[] = $this->rootDaemonCheck();

        if ($this->isReady($checks)) {
            $checks[] = $this->smokeCheck();
        }

        return $this->finish($checks);
    }

    public function isReady(array $checks): bool
    {
        foreach ($checks as $check) {
            if (($check['blocking'] ?? false) && $check['status'] === 'fail') {
                return false;
            }
        }

        return true;
    }

    private function finish(array $checks): array
    {
        Cache::put(self::CACHE_KEY, $checks, now()->addSeconds(60));

        return $checks;
    }

    private function dockerCliCheck(): array
    {
        $result = $this->runCommand(array_merge($this->dockerBase(), ['--version']));

        if ($result['exitCode'] !== 0) {
            return $this->checkResult('docker_cli', 'fail', 'Docker CLI not found at "'.config('sorify.execution.docker_binary', 'docker').'".', true);
        }

        return $this->checkResult('docker_cli', 'pass', trim($result['output']) ?: 'Docker CLI available.');
    }

    private function daemonCheck(): array
    {
        $result = $this->runCommand(array_merge($this->dockerBase(), ['version', '--format', 'ok']));

        if ($result['exitCode'] !== 0) {
            $error = trim($result['errorOutput']) ?: trim($result['output']) ?: 'unreachable';

            return $this->checkResult('daemon', 'fail', 'Docker daemon unreachable: '.$error, true);
        }

        return $this->checkResult('daemon', 'pass', 'Docker daemon reachable.');
    }

    private function imageCheck(): array
    {
        $image = (string) config('sorify.execution.runner_image', 'sorify-runner:latest');

        $result = $this->runCommand(array_merge($this->dockerBase(), ['image', 'inspect', $image, '--format', '{{.Created}}']));

        if ($result['exitCode'] !== 0) {
            return $this->checkResult('image', 'fail', "Runner image \"{$image}\" not found. Build it via: php artisan sorify:runner-image", true);
        }

        return $this->checkResult('image', 'pass', "Runner image \"{$image}\" built ".trim($result['output']).'.');
    }

    private function networkCheck(): array
    {
        $network = (string) config('sorify.execution.runner_network', 'sorify-runners');

        $result = $this->runCommand(array_merge($this->dockerBase(), ['network', 'inspect', $network]));

        if ($result['exitCode'] === 0) {
            return $this->checkResult('network', 'pass', "Runner network \"{$network}\" exists.");
        }

        $created = $this->runCommand(array_merge($this->dockerBase(), ['network', 'create', $network]));

        if ($created['exitCode'] !== 0) {
            $error = trim($created['errorOutput']) ?: 'network create failed';

            return $this->checkResult('network', 'fail', "Runner network \"{$network}\" missing and could not be created: {$error}", true);
        }

        return $this->checkResult('network', 'pass', "Runner network \"{$network}\" created.");
    }

    private function gvisorCheck(): array
    {
        $runtime = (string) config('sorify.execution.gvisor_runtime', 'runsc');

        $result = $this->runCommand(array_merge($this->dockerBase(), ['info', '--format', '{{json .Runtimes}}']));

        if ($result['exitCode'] === 0 && str_contains($result['output'], $runtime)) {
            return $this->checkResult('gvisor', 'info', "gVisor runtime \"{$runtime}\" available — container escapes face syscall interception.");
        }

        return $this->checkResult('gvisor', 'info', 'gVisor not installed — containers use the default runtime (runc). Optional hardening; see the README.');
    }

    private function rootDaemonCheck(): array
    {
        $host = config('sorify.execution.docker_host');

        if (! $host || $host === self::ROOT_SOCKET) {
            return $this->checkResult('root_daemon', 'warn', "Connected to the host's main Docker daemon — its socket is root-equivalent on the VM. Prefer the rootless daemon set up by bin/setup-ephemeral-runner.");
        }

        return $this->checkResult('root_daemon', 'pass', "Connected via {$host}.");
    }

    private function smokeCheck(): array
    {
        $command = app(DockerExecutor::class)->buildRunnerCommand(
            '/nonexistent',
            '/tmp',
            ['--spec', '/smoke/smoke.spec.js', '--output', '/tmp/smoke-out', '--timeout', '30000', '--browser', 'chromium', '--headless', 'true', '--screenshot-mode', 'disabled'],
            0,
            0,
            mounts: false,
            name: 'sorify-smoke-check'
        );

        $result = $this->runCommand($command, 60);

        if ($result['exitCode'] !== 0) {
            $error = strtok(trim($result['errorOutput']) ?: trim($result['output']) ?: 'unknown error', "\n");

            return $this->checkResult('smoke', 'fail', "Smoke run failed in the hardened container: {$error}", true);
        }

        return $this->checkResult('smoke', 'pass', 'Smoke test executed successfully in a hardened container.');
    }

    private function dockerBase(): array
    {
        return app(DockerExecutor::class)->dockerBase();
    }

    private function checkResult(string $name, string $status, string $message, bool $blocking = false): array
    {
        return [
            'name' => $name,
            'status' => $status,
            'message' => $message,
            'blocking' => $blocking,
        ];
    }

    /**
     * @return array{exitCode: int, output: string, errorOutput: string}
     */
    private function runCommand(array $command, int $timeout = 10): array
    {
        if ($this->runner) {
            return ($this->runner)($command);
        }

        $result = ProcessFacade::timeout($timeout)->run($command);

        return [
            'exitCode' => $result->exitCode(),
            'output' => $result->output(),
            'errorOutput' => $result->errorOutput(),
        ];
    }
}
