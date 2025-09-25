<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use FpHic\Api\RateLimitController;
use FpHic\HIC_Rate_Limiter;

final class HttpRateLimitControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__) . '/includes/rate-limiter.php';
        require_once dirname(__DIR__) . '/includes/api/rate-limit-controller.php';
        HIC_Rate_Limiter::reset('test:limit');
    }

    protected function tearDown(): void
    {
        remove_filter('hic_rate_limit_map', [$this, 'extendRateLimitMap'], 10);
        parent::tearDown();
    }

    public function testAllowsRequestsWithinConfiguredWindow(): void
    {
        $config = [
            'key' => 'test:limit',
            'max_attempts' => 2,
            'window' => 60,
        ];

        $result = RateLimitController::intercept(false, ['hic_rate_limit' => $config], 'https://example.com/endpoint');
        self::assertFalse($result);

        $second = RateLimitController::intercept(false, ['hic_rate_limit' => $config], 'https://example.com/endpoint');
        self::assertFalse($second);
    }

    public function testBlocksRequestWhenThresholdExceeded(): void
    {
        $config = [
            'key' => 'test:limit',
            'max_attempts' => 1,
            'window' => 60,
        ];

        $first = RateLimitController::intercept(false, ['hic_rate_limit' => $config], 'https://example.com/endpoint');
        self::assertFalse($first);

        $second = RateLimitController::intercept(false, ['hic_rate_limit' => $config], 'https://example.com/endpoint');
        self::assertInstanceOf(WP_Error::class, $second);
        self::assertSame(HIC_ERROR_RATE_LIMITED, $second->get_error_code());
        self::assertSame(HIC_HTTP_TOO_MANY_REQUESTS, $second->get_error_data()['status']);
        self::assertGreaterThanOrEqual(1, $second->get_error_data()['retry_after']);
    }

    public function testGetRegisteredLimitsExposesNormalizedConfiguration(): void
    {
        $limits = RateLimitController::getRegisteredLimits();

        self::assertArrayHasKey('api.hotelcincloud.com', $limits);
        self::assertSame('hic:hotel-in-cloud', $limits['api.hotelcincloud.com']['key']);
        self::assertSame(180, $limits['api.hotelcincloud.com']['max_attempts']);
        self::assertSame(60, $limits['api.hotelcincloud.com']['window']);
    }

    public function testGetRegisteredLimitsHonoursFilters(): void
    {
        add_filter('hic_rate_limit_map', [$this, 'extendRateLimitMap'], 10, 3);

        $limits = RateLimitController::getRegisteredLimits();

        self::assertArrayHasKey('custom.example.com', $limits);
        self::assertSame('test:custom', $limits['custom.example.com']['key']);
        self::assertSame(5, $limits['custom.example.com']['max_attempts']);
        self::assertSame(30, $limits['custom.example.com']['window']);
    }

    /**
     * @param array<string,mixed> $limits
     * @return array<string,mixed>
     */
    public function extendRateLimitMap($limits)
    {
        if (!is_array($limits)) {
            $limits = [];
        }

        $limits['custom.example.com'] = [
            'key' => 'test:custom',
            'max_attempts' => 5,
            'window' => 30,
        ];

        return $limits;
    }
}
