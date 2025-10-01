<?php

use FpHic\HicS2S\Support\Http;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Support/Http.php';

final class HttpPostWithRetryTest extends TestCase
{
    /** @var list<int> */
    private static array $pauses = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$pauses = [];
        $GLOBALS['hic_test_wp_remote_post'] = null;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['hic_test_wp_remote_post']);
        parent::tearDown();
    }

    public static function recordPause(int $seconds): void
    {
        self::$pauses[] = $seconds;
    }

    public function testRetriesWpErrorResponses(): void
    {
        $attempts = 0;

        $GLOBALS['hic_test_wp_remote_post'] = function () use (&$attempts) {
            $attempts++;

            if ($attempts < 3) {
                return new WP_Error('network_error', 'Temporary failure');
            }

            return [
                'response' => ['code' => 200],
                'body' => '{}',
            ];
        };

        $result = Http::postWithRetry(static function (): array {
            return [
                'url' => 'https://example.com',
                'args' => [],
            ];
        }, 3, 1);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['code']);
        $this->assertSame(3, $result['attempts']);
        $this->assertCount(2, self::$pauses);
    }

    public function testHonoursRetryAfterHeader(): void
    {
        $attempts = 0;

        $GLOBALS['hic_test_wp_remote_post'] = function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                return [
                    'response' => ['code' => 429],
                    'body' => '{}',
                    'headers' => ['retry-after' => '7'],
                ];
            }

            return [
                'response' => ['code' => 204],
                'body' => '',
            ];
        };

        $result = Http::postWithRetry(static function (): array {
            return [
                'url' => 'https://example.com/resource',
                'args' => [],
            ];
        }, 2, 1);

        $this->assertTrue($result['success']);
        $this->assertSame(204, $result['code']);
        $this->assertSame([7], self::$pauses);
    }

    public function testWpErrorResetsResponseMetadata(): void
    {
        $attempts = 0;

        $GLOBALS['hic_test_wp_remote_post'] = function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                return [
                    'response' => ['code' => 503],
                    'body' => '',
                ];
            }

            return new WP_Error('timeout', 'Timeout');
        };

        $result = Http::postWithRetry(static function (): array {
            return [
                'url' => 'https://example.com/unreliable',
                'args' => [],
            ];
        }, 2, 1);

        $this->assertFalse($result['success']);
        $this->assertNull($result['code']);
        $this->assertNull($result['response']);
        $this->assertInstanceOf(WP_Error::class, $result['error']);
        $this->assertSame(2, $result['attempts']);
        $this->assertSame([1], self::$pauses);
    }
}

if (!function_exists('wp_sleep')) {
    function wp_sleep($seconds): void
    {
        HttpPostWithRetryTest::recordPause((int) $seconds);
    }
}
