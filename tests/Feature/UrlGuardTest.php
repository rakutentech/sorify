<?php

namespace Tests\Feature;

use App\Services\Agent\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UrlGuardTest extends TestCase
{
    private UrlGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sorify.agent.allow_private_ips' => false]);

        $this->guard = new UrlGuard(false);
    }

    public static function rejectedUrls(): array
    {
        return [
            'loopback literal' => ['http://127.0.0.1:8080/'],
            'loopback name' => ['http://localhost/'],
            'private class A' => ['http://10.0.0.1/'],
            'private class B' => ['http://172.16.0.1/'],
            'private class C' => ['http://192.168.1.1/'],
            'link-local' => ['http://169.254.1.1/'],
            'metadata service' => ['http://169.254.169.254/latest/meta-data'],
            'file scheme' => ['file:///etc/passwd'],
            'ftp scheme' => ['ftp://example.com/file'],
            'missing host' => ['http://'],
            'empty' => [''],
        ];
    }

    #[DataProvider('rejectedUrls')]
    public function test_rejects_disallowed_urls(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->guard->validate($url);
    }

    public function test_accepts_public_ip(): void
    {
        $this->assertSame('https://1.1.1.1/x', $this->guard->validate('https://1.1.1.1/x'));
    }

    public function test_private_ips_allowed_when_configured(): void
    {
        $guard = new UrlGuard(true);

        $this->assertSame('http://192.168.1.10:8080/', $guard->validate('http://192.168.1.10:8080/'));
    }

    public function test_rejects_unresolvable_host(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->guard->validate('https://this-domain-definitely-does-not-exist-'.bin2hex(random_bytes(8)).'.invalid');
    }
}
