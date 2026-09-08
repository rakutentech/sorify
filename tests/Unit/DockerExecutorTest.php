<?php

namespace Tests\Unit;

use App\Services\DockerExecutor;
use Tests\TestCase;

class DockerExecutorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sorify.execution.docker_binary', 'docker');
        config()->set('sorify.execution.docker_host', 'unix:///run/user/1500/docker.sock');
        config()->set('sorify.execution.runner_image', 'sorify-runner:latest');
        config()->set('sorify.execution.runner_network', 'sorify-runners');
        config()->set('sorify.execution.host_runs_dir', '/srv/sorify/runs');
        config()->set('sorify.tmp_dir', '/app/storage/app/tmp');
        config()->set('sorify.execution.container', [
            'pids_limit' => 512, 'memory' => '2g', 'cpus' => 2, 'tmpfs_size' => '256m',
        ]);
    }

    public function test_builds_hardened_run_command(): void
    {
        $workDir = '/app/storage/app/tmp/run-1-2/work';
        $outDir = '/app/storage/app/tmp/run-1-2/out';
        $runnerArgs = ['--spec', '/work/spec.js', '--output', '/out', '--timeout', '30000'];

        $command = app(DockerExecutor::class)->buildRunnerCommand($workDir, $outDir, $runnerArgs, 1, 2);

        $this->assertSame('docker', $command[0]);
        $this->assertSame('--host', $command[1]);
        $this->assertSame('unix:///run/user/1500/docker.sock', $command[2]);
        $this->assertSame('run', $command[3]);
        $this->assertContains('--rm', $command);
        $this->assertContains('sorify-run-1-2', $command);
        $this->assertContains('--network', $command);
        $this->assertContains('sorify-runners', $command);
        $this->assertContains('--read-only', $command);
        $this->assertContains('--cap-drop', $command);
        $this->assertContains('ALL', $command);
        $this->assertContains('--security-opt', $command);
        $this->assertContains('no-new-privileges', $command);
        $this->assertContains('--init', $command);
        $this->assertContains('--user', $command);
        $this->assertContains('1000:1000', $command);
        $this->assertContains('--stop-timeout', $command);
        $this->assertContains('/srv/sorify/runs/run-1-2/work:/work:ro', $command);
        $this->assertContains('/srv/sorify/runs/run-1-2/out:/out:rw', $command);
        $this->assertContains('-e', $command);
        $this->assertContains('PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright', $command);

        $imagePos = array_search('sorify-runner:latest', $command, true);
        $this->assertNotFalse($imagePos);
        $this->assertSame('node', $command[$imagePos + 1]);
        $this->assertSame('/app/resources/playwright/runner.cjs', $command[$imagePos + 2]);
        $this->assertSame($runnerArgs, array_slice($command, $imagePos + 3));
    }

    public function test_resource_limits_present(): void
    {
        $command = app(DockerExecutor::class)->buildRunnerCommand('/w', '/o', [], 1, 2);

        $this->assertContains('--pids-limit', $command);
        $this->assertContains('512', $command);
        $this->assertContains('--memory', $command);
        $this->assertContains('2g', $command);
        $this->assertContains('--cpus', $command);
        $this->assertContains('2', $command);
        $this->assertContains('--tmpfs', $command);
        $this->assertContains('/tmp:rw,noexec,nosuid,size=256m', $command);
    }

    public function test_no_docker_host_omits_host_flag(): void
    {
        config()->set('sorify.execution.docker_host', null);

        $command = app(DockerExecutor::class)->buildRunnerCommand('/w', '/o', [], 1, 2);

        $this->assertNotContains('--host', $command);
        $this->assertSame('run', $command[1]);
    }

    public function test_map_to_host_replaces_tmp_prefix(): void
    {
        $this->assertSame(
            '/srv/sorify/runs/run-9-9/out',
            app(DockerExecutor::class)->mapToHost('/app/storage/app/tmp/run-9-9/out')
        );
    }

    public function test_map_to_host_passthrough_without_host_runs_dir(): void
    {
        config()->set('sorify.execution.host_runs_dir', null);

        $this->assertSame(
            '/app/storage/app/tmp/run-9-9/out',
            app(DockerExecutor::class)->mapToHost('/app/storage/app/tmp/run-9-9/out')
        );
    }

    public function test_container_name_format(): void
    {
        $this->assertSame('sorify-run-7-3', app(DockerExecutor::class)->containerName(7, 3));
    }

    public function test_cli_env_neutralizes_secrets(): void
    {
        putenv('DB_PASSWORD=secret');

        $env = app(DockerExecutor::class)->cliEnv();

        $this->assertSame('', $env['DB_PASSWORD']);
        $this->assertArrayHasKey('PATH', $env);

        putenv('DB_PASSWORD');
    }

    public function test_build_image_command_targets_runner_dockerfile(): void
    {
        $command = app(DockerExecutor::class)->buildImage();

        $this->assertContains('build', $command);
        $this->assertContains('docker/runner.Dockerfile', $command);
        $this->assertContains('sorify-runner:latest', $command);
        $this->assertSame(base_path(), end($command));
    }
}
