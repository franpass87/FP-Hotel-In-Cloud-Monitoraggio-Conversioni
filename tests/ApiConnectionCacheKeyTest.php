<?php

use PHPUnit\Framework\TestCase;

use function FpHic\hic_get_api_test_cache_key;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/api/polling-cache-helpers.php';

final class ApiConnectionCacheKeyTest extends TestCase
{
    public function test_different_credentials_with_same_concatenation_yield_distinct_cache_keys(): void
    {
        $endpoint = 'https://api.example.com/reservations/property';

        $emailOne = 'foo@example.com';
        $passwordOne = 'bar';

        $emailTwo = 'foo@example.co';
        $passwordTwo = 'mbar';

        // Guard: legacy cache keys would collide for these credentials.
        $legacyKeyOne = "api_test_{$endpoint}" . md5($emailOne . $passwordOne);
        $legacyKeyTwo = "api_test_{$endpoint}" . md5($emailTwo . $passwordTwo);

        $this->assertSame($legacyKeyOne, $legacyKeyTwo);

        $cacheKeyOne = hic_get_api_test_cache_key($endpoint, $emailOne, $passwordOne);
        $cacheKeyTwo = hic_get_api_test_cache_key($endpoint, $emailTwo, $passwordTwo);

        $this->assertNotSame($cacheKeyOne, $cacheKeyTwo);
    }
}

