<?php

namespace Tests\Unit;

use App\Services\DockerExecutor;
use Tests\TestCase;

class LocalRunnerSafetyNetTest extends TestCase
{
    protected function tearDown(): void
    {
        config()->set('sorify.execution.local_test_uid', null);
        config()->set('sorify.execution.local_max_processes', 256);
        config()->set('sorify.execution.local_max_filesize', 1073741824);
        parent::tearDown();
    }

    public function test_no_prefix_when_uid_not_configured(): void
    {
        config()->set('sorify.execution.local_test_uid', null);

        $this->assertSame([], app(DockerExecutor::class)->localRunnerPrefix('Linux'));
    }

    public function test_no_prefix_on_non_linux(): void
    {
        config()->set('sorify.execution.local_test_uid', 2000);

        $this->assertSame([], app(DockerExecutor::class)->localRunnerPrefix('Darwin'));
        $this->assertSame([], app(DockerExecutor::class)->localRunnerPrefix('Windows'));
    }

    public function test_linux_prefix_drops_uid_with_prlimit_caps(): void
    {
        config()->set('sorify.execution.local_test_uid', 2000);
        config()->set('sorify.execution.local_max_processes', 128);
        config()->set('sorify.execution.local_max_filesize', 536870912);

        $prefix = app(DockerExecutor::class)->localRunnerPrefix('Linux');

        $this->assertSame('setpriv', $prefix[0]);
        $this->assertContains('--reuid=2000', $prefix);
        $this->assertContains('--regid=2000', $prefix);
        $this->assertContains('--clear-groups', $prefix);
        $this->assertContains('prlimit', $prefix);
        $this->assertContains('--nproc=128', $prefix);
        $this->assertContains('--fsize=536870912', $prefix);
    }

    public function test_zero_limits_omit_prlimit(): void
    {
        config()->set('sorify.execution.local_test_uid', 2000);
        config()->set('sorify.execution.local_max_processes', 0);
        config()->set('sorify.execution.local_max_filesize', 0);

        $prefix = app(DockerExecutor::class)->localRunnerPrefix('Linux');

        $this->assertSame(
            ['setpriv', '--reuid=2000', '--regid=2000', '--clear-groups'],
            $prefix
        );
    }
}
