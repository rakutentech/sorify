<?php

namespace Tests\Unit;

use App\Services\EphemeralReadinessChecker;
use Tests\TestCase;

class EphemeralReadinessCheckerTest extends TestCase
{
    private const FAKE_DOCKER = '/fake/docker';

    private array $runResults = [];

    private function makeChecker(): EphemeralReadinessChecker
    {
        config()->set('sorify.execution.docker_binary', self::FAKE_DOCKER);
        config()->set('sorify.execution.docker_host', null);
        config()->set('sorify.execution.runner_image', 'sorify-runner:latest');
        config()->set('sorify.execution.runner_network', 'sorify-runners');
        config()->set('cache.default', 'array');

        return new EphemeralReadinessChecker(function (array $command) {
            $key = implode(' ', $command);

            foreach ($this->runResults as $pattern => $result) {
                if (str_contains($key, $pattern)) {
                    return $result;
                }
            }

            return ['exitCode' => 0, 'output' => '', 'errorOutput' => ''];
        });
    }

    private function setResponses(array $responses): void
    {
        $this->runResults = $responses;
    }

    public function test_all_passing_environment_is_ready(): void
    {
        $this->setResponses([
            '--version' => ['exitCode' => 0, 'output' => 'Docker version 27.0', 'errorOutput' => ''],
            'image inspect' => ['exitCode' => 0, 'output' => '2026-09-08T10:00:00Z', 'errorOutput' => ''],
            'network inspect' => ['exitCode' => 0, 'output' => 'sorify-runners', 'errorOutput' => ''],
            'Runtimes' => ['exitCode' => 0, 'output' => '{"runc":{"path":"runc"}}', 'errorOutput' => ''],
            'sorify-smoke-check' => ['exitCode' => 0, 'output' => '{"status":"passed"}', 'errorOutput' => ''],
            'version' => ['exitCode' => 0, 'output' => 'ok', 'errorOutput' => ''],
        ]);

        $checker = $this->makeChecker();
        $checks = $checker->check(fresh: true);

        $this->assertTrue($checker->isReady($checks));
        $this->assertNotNull($this->findCheck($checks, 'docker_cli'));
        $this->assertSame('pass', $this->findCheck($checks, 'daemon')['status']);
        $this->assertSame('pass', $this->findCheck($checks, 'image')['status']);
        $this->assertSame('pass', $this->findCheck($checks, 'network')['status']);
        $this->assertSame('info', $this->findCheck($checks, 'gvisor')['status']);
        $this->assertSame('warn', $this->findCheck($checks, 'root_daemon')['status']);
        $this->assertSame('pass', $this->findCheck($checks, 'smoke')['status']);
    }

    public function test_missing_docker_cli_blocks_and_skips_later_checks(): void
    {
        $this->setResponses([
            '--version' => ['exitCode' => 127, 'output' => '', 'errorOutput' => 'not found'],
        ]);

        $checker = $this->makeChecker();
        $checks = $checker->check(fresh: true);

        $this->assertFalse($checker->isReady($checks));
        $this->assertSame('fail', $this->findCheck($checks, 'docker_cli')['status']);
        $this->assertNull($this->findCheck($checks, 'smoke'));
        $this->assertNull($this->findCheck($checks, 'image'));
    }

    public function test_unreachable_daemon_blocks_and_skips_smoke(): void
    {
        $this->setResponses([
            '--version' => ['exitCode' => 0, 'output' => 'Docker version 27.0', 'errorOutput' => ''],
            'version --format' => ['exitCode' => 1, 'output' => '', 'errorOutput' => 'cannot connect'],
        ]);

        $checker = $this->makeChecker();
        $checks = $checker->check(fresh: true);

        $this->assertFalse($checker->isReady($checks));
        $this->assertSame('fail', $this->findCheck($checks, 'daemon')['status']);
        $this->assertStringContainsString('cannot connect', $this->findCheck($checks, 'daemon')['message']);
        $this->assertNull($this->findCheck($checks, 'smoke'));
    }

    public function test_missing_image_is_blocking_failure(): void
    {
        $this->setResponses([
            '--version' => ['exitCode' => 0, 'output' => 'Docker version 27.0', 'errorOutput' => ''],
            'version --format' => ['exitCode' => 0, 'output' => 'ok', 'errorOutput' => ''],
            'image inspect' => ['exitCode' => 1, 'output' => '', 'errorOutput' => 'no such image'],
            'network inspect' => ['exitCode' => 0, 'output' => 'sorify-runners', 'errorOutput' => ''],
            'Runtimes' => ['exitCode' => 0, 'output' => '{"runc":{"path":"runc"}}', 'errorOutput' => ''],
        ]);

        $checker = $this->makeChecker();
        $checks = $checker->check(fresh: true);

        $this->assertFalse($checker->isReady($checks));
        $this->assertSame('fail', $this->findCheck($checks, 'image')['status']);
        $this->assertStringContainsString('sorify:runner-image', $this->findCheck($checks, 'image')['message']);
    }

    public function test_missing_network_is_created(): void
    {
        $this->setResponses([
            '--version' => ['exitCode' => 0, 'output' => 'Docker version 27.0', 'errorOutput' => ''],
            'version --format' => ['exitCode' => 0, 'output' => 'ok', 'errorOutput' => ''],
            'image inspect' => ['exitCode' => 0, 'output' => '2026-09-08T10:00:00Z', 'errorOutput' => ''],
            'network inspect sorify-runners' => ['exitCode' => 1, 'output' => '', 'errorOutput' => 'no such network'],
            'network create' => ['exitCode' => 0, 'output' => '', 'errorOutput' => ''],
            'Runtimes' => ['exitCode' => 0, 'output' => '{"runc":{"path":"runc"}}', 'errorOutput' => ''],
            'sorify-smoke-check' => ['exitCode' => 0, 'output' => '{"status":"passed"}', 'errorOutput' => ''],
        ]);

        $checker = $this->makeChecker();
        $checks = $checker->check(fresh: true);

        $this->assertTrue($checker->isReady($checks));
        $this->assertSame('pass', $this->findCheck($checks, 'network')['status']);
    }

    public function test_network_create_failure_blocks(): void
    {
        $this->setResponses([
            '--version' => ['exitCode' => 0, 'output' => 'Docker version 27.0', 'errorOutput' => ''],
            'version --format' => ['exitCode' => 0, 'output' => 'ok', 'errorOutput' => ''],
            'image inspect' => ['exitCode' => 0, 'output' => '2026-09-08T10:00:00Z', 'errorOutput' => ''],
            'network inspect sorify-runners' => ['exitCode' => 1, 'output' => '', 'errorOutput' => 'no such network'],
            'network create' => ['exitCode' => 1, 'output' => '', 'errorOutput' => 'denied'],
            'Runtimes' => ['exitCode' => 0, 'output' => '{"runc":{"path":"runc"}}', 'errorOutput' => ''],
        ]);

        $checker = $this->makeChecker();
        $checks = $checker->check(fresh: true);

        $this->assertFalse($checker->isReady($checks));
        $this->assertSame('fail', $this->findCheck($checks, 'network')['status']);
    }

    public function test_gvisor_available_is_info_pass(): void
    {
        $this->setResponses([
            '--version' => ['exitCode' => 0, 'output' => 'Docker version 27.0', 'errorOutput' => ''],
            'version --format' => ['exitCode' => 0, 'output' => 'ok', 'errorOutput' => ''],
            'image inspect' => ['exitCode' => 0, 'output' => '2026-09-08T10:00:00Z', 'errorOutput' => ''],
            'network inspect' => ['exitCode' => 0, 'output' => 'sorify-runners', 'errorOutput' => ''],
            'Runtimes' => ['exitCode' => 0, 'output' => '{"runsc":{"path":"/usr/bin/runsc"}}', 'errorOutput' => ''],
            'sorify-smoke-check' => ['exitCode' => 0, 'output' => '{"status":"passed"}', 'errorOutput' => ''],
        ]);

        $checks = $this->makeChecker()->check(fresh: true);

        $this->assertSame('info', $this->findCheck($checks, 'gvisor')['status']);
        $this->assertStringContainsString('gVisor', $this->findCheck($checks, 'gvisor')['message']);
    }

    public function test_rootless_daemon_connection_passes(): void
    {
        $checker = $this->makeChecker();
        config()->set('sorify.execution.docker_host', 'unix:///run/user/1500/docker.sock');
        $this->setResponses([
            '--version' => ['exitCode' => 0, 'output' => 'Docker version 27.0', 'errorOutput' => ''],
            'version --format' => ['exitCode' => 0, 'output' => 'ok', 'errorOutput' => ''],
            'image inspect' => ['exitCode' => 0, 'output' => '2026-09-08T10:00:00Z', 'errorOutput' => ''],
            'network inspect' => ['exitCode' => 0, 'output' => 'sorify-runners', 'errorOutput' => ''],
            'Runtimes' => ['exitCode' => 0, 'output' => '{"runc":{"path":"runc"}}', 'errorOutput' => ''],
            'sorify-smoke-check' => ['exitCode' => 0, 'output' => '{"status":"passed"}', 'errorOutput' => ''],
        ]);

        $checks = $checker->check(fresh: true);

        $this->assertSame('pass', $this->findCheck($checks, 'root_daemon')['status']);
    }

    public function test_smoke_failure_blocks(): void
    {
        $this->setResponses([
            '--version' => ['exitCode' => 0, 'output' => 'Docker version 27.0', 'errorOutput' => ''],
            'version --format' => ['exitCode' => 0, 'output' => 'ok', 'errorOutput' => ''],
            'image inspect' => ['exitCode' => 0, 'output' => '2026-09-08T10:00:00Z', 'errorOutput' => ''],
            'network inspect' => ['exitCode' => 0, 'output' => 'sorify-runners', 'errorOutput' => ''],
            'Runtimes' => ['exitCode' => 0, 'output' => '{"runc":{"path":"runc"}}', 'errorOutput' => ''],
            'sorify-smoke-check' => ['exitCode' => 1, 'output' => '', 'errorOutput' => 'container failed to start'],
        ]);

        $checker = $this->makeChecker();
        $checks = $checker->check(fresh: true);

        $this->assertFalse($checker->isReady($checks));
        $this->assertSame('fail', $this->findCheck($checks, 'smoke')['status']);
        $this->assertStringContainsString('container failed to start', $this->findCheck($checks, 'smoke')['message']);
    }

    public function test_result_is_cached_until_fresh(): void
    {
        cache()->store('array')->flush();
        $this->setResponses([
            '--version' => ['exitCode' => 0, 'output' => 'Docker version 27.0', 'errorOutput' => ''],
            'version --format' => ['exitCode' => 0, 'output' => 'ok', 'errorOutput' => ''],
            'image inspect' => ['exitCode' => 0, 'output' => '2026-09-08T10:00:00Z', 'errorOutput' => ''],
            'network inspect' => ['exitCode' => 0, 'output' => 'sorify-runners', 'errorOutput' => ''],
            'Runtimes' => ['exitCode' => 0, 'output' => '{"runc":{"path":"runc"}}', 'errorOutput' => ''],
            'sorify-smoke-check' => ['exitCode' => 0, 'output' => '{"status":"passed"}', 'errorOutput' => ''],
        ]);

        $checker = $this->makeChecker();
        $first = $checker->check(fresh: true);

        $this->setResponses([
            '--version' => ['exitCode' => 127, 'output' => '', 'errorOutput' => 'gone'],
        ]);

        $cached = $checker->check();
        $this->assertSame($first, $cached, 'Second call without fresh=1 must return the cached result');

        $fresh = $checker->check(fresh: true);
        $this->assertNotSame($first, $fresh);
        $this->assertSame('fail', $this->findCheck($fresh, 'docker_cli')['status']);
    }

    private function findCheck(array $checks, string $name): ?array
    {
        foreach ($checks as $check) {
            if ($check['name'] === $name) {
                return $check;
            }
        }

        return null;
    }
}
