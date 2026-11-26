<?php

namespace Tests\Unit;

use App\Support\SsrfGuard;
use PHPUnit\Framework\TestCase;

/** EA-1 / S-11: deliveries connect to the vetted address, never a second DNS answer. */
class SsrfGuardPinTest extends TestCase
{
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
}
