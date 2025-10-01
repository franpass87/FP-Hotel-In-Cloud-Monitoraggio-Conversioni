<?php

declare(strict_types=1);

use FpHic\HicS2S\Http\Routes;
use FpHic\HicS2S\Support\ServiceContainer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/helpers/api.php';
require_once __DIR__ . '/../includes/api/webhook.php';
require_once __DIR__ . '/../src/bootstrap.php';

final class WebhookRouteRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ServiceContainer::flush();

        $GLOBALS['hic_registered_rest_routes_store'] = [];
        $GLOBALS['hic_rest_route_registry'] = [];

        if (!isset($GLOBALS['hic_test_options']) || !is_array($GLOBALS['hic_test_options'])) {
            $GLOBALS['hic_test_options'] = [];
        }

        $GLOBALS['hic_test_options']['hic_s2s_settings'] = [
            'token' => 'test-token',
            'webhook_secret' => '',
            'ga4_measurement_id' => '',
            'ga4_api_secret' => '',
            'meta_pixel_id' => '',
            'meta_access_token' => '',
            'redirector_enabled' => false,
            'redirector_engine_url' => '',
        ];
    }

    public function testNamespacedRouteRegistersWithoutLegacyHandler(): void
    {
        Routes::registerRoutes();

        $routes = $GLOBALS['hic_registered_rest_routes_store'] ?? [];
        self::assertArrayHasKey('/hic/v1/conversion', $routes);

        $callback = $routes['/hic/v1/conversion']['callback'] ?? null;
        self::assertIsArray($callback);
        self::assertSame('handleConversion', $callback[1] ?? null);

        $fallback = \FpHic\Helpers\hic_get_registered_rest_routes();
        self::assertArrayHasKey('/hic/v1/conversion', $fallback);
        self::assertSame('hic/v1', $fallback['/hic/v1/conversion']['namespace']);
        self::assertSame('/conversion', $fallback['/hic/v1/conversion']['route']);
    }
}
