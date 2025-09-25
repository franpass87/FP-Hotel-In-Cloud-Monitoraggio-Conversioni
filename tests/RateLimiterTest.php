<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/rate-limiter.php';

final class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $hic_test_transients, $hic_test_transient_expirations;
        $hic_test_transients = [];
        $hic_test_transient_expirations = [];
    }

    public function testAllowsWithinLimit(): void
    {
        \FpHic\HIC_Rate_Limiter::reset('limit-test');
        $result = \FpHic\HIC_Rate_Limiter::attempt('limit-test', 3, 60);

        $this->assertTrue($result['allowed']);
        $this->assertSame(2, $result['remaining']);
        $this->assertSame(0, $result['retry_after']);
    }

    public function testBlocksAfterMaxAttempts(): void
    {
        \FpHic\HIC_Rate_Limiter::reset('limit-block');

        \FpHic\HIC_Rate_Limiter::attempt('limit-block', 2, 120);
        \FpHic\HIC_Rate_Limiter::attempt('limit-block', 2, 120);
        $result = \FpHic\HIC_Rate_Limiter::attempt('limit-block', 2, 120);

        $this->assertFalse($result['allowed']);
        $this->assertSame(0, $result['remaining']);
        $this->assertGreaterThanOrEqual(1, $result['retry_after']);
        $this->assertGreaterThan(0, \FpHic\HIC_Rate_Limiter::getRetryAfter('limit-block'));
    }

    public function testResetClearsState(): void
    {
        \FpHic\HIC_Rate_Limiter::attempt('limit-reset', 1, 60);
        \FpHic\HIC_Rate_Limiter::reset('limit-reset');
        $result = \FpHic\HIC_Rate_Limiter::attempt('limit-reset', 1, 60);

        $this->assertTrue($result['allowed']);
        $this->assertSame(0, $result['remaining']);
    }

    public function testWindowExpirationAllowsNewAttempts(): void
    {
        \FpHic\HIC_Rate_Limiter::reset('limit-expire');
        \FpHic\HIC_Rate_Limiter::attempt('limit-expire', 1, 1);
        $blocked = \FpHic\HIC_Rate_Limiter::attempt('limit-expire', 1, 1);
        $this->assertFalse($blocked['allowed']);

        sleep(1);

        $result = \FpHic\HIC_Rate_Limiter::attempt('limit-expire', 1, 1);
        $this->assertTrue($result['allowed']);
        $this->assertSame(0, $result['remaining']);
    }

    public function testGracefulWhenKeyInvalid(): void
    {
        $result = \FpHic\HIC_Rate_Limiter::attempt('', 5, 60);
        $this->assertTrue($result['allowed']);
        $this->assertSame(5, $result['remaining']);
    }
}
