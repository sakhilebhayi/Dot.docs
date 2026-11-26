<?php

namespace Tests\Unit;

use App\Support\SsrfGuard;
use PHPUnit\Framework\TestCase;

/** EA-1 / S-11: deliveries connect to the vetted address, never a second DNS answer. */
class SsrfGuardPinTest extends TestCase
{
    protected function tearDown(): void
    {
        SsrfGuard::$resolver = null;
        parent::tearDown();
    }

    public function test_private_and_metadata_targets_get_no_pin(): void
    {
        $this->assertNull(SsrfGuard::pinnedResolve('http://169.254.169.254/latest/meta-data'));
        $this->assertNull(SsrfGuard::pinnedResolve('http://10.0.0.5/hook'));
        $this->assertNull(SsrfGuard::pinnedResolve('http://100.64.1.1/hook'));
        $this->assertNull(SsrfGuard::pinnedResolve('ftp://8.8.8.8/hook'));
        $this->assertNull(SsrfGuard::pinnedResolve('http://localhost/hook'));
    }

    public function test_a_public_ip_literal_needs_no_pin(): void
    {
        $this->assertSame([], SsrfGuard::pinnedResolve('https://8.8.8.8/hook'));
    }

    public function test_a_hostname_is_pinned_to_its_vetted_address_and_port(): void
    {
        SsrfGuard::$resolver = fn (string $host) => ['93.184.216.34'];

        $this->assertSame(['hooks.example.test:443:93.184.216.34'], SsrfGuard::pinnedResolve('https://hooks.example.test/x'));
        $this->assertSame(['hooks.example.test:8080:93.184.216.34'], SsrfGuard::pinnedResolve('http://hooks.example.test:8080/x'));
        $this->assertSame(['hooks.example.test:80:93.184.216.34'], SsrfGuard::pinnedResolve('http://hooks.example.test/x'));
    }

    public function test_an_ipv6_address_is_bracketed(): void
    {
        SsrfGuard::$resolver = fn (string $host) => ['2606:4700::6810:84e5'];

        $this->assertSame(['v6.example.test:443:[2606:4700::6810:84e5]'], SsrfGuard::pinnedResolve('https://v6.example.test/'));
    }

    public function test_a_name_that_resolves_to_a_private_address_is_refused(): void
    {
        SsrfGuard::$resolver = fn (string $host) => ['169.254.169.254'];

        $this->assertNull(SsrfGuard::pinnedResolve('https://rebind.example.test/'));
    }
}
