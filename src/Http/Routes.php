<?php declare(strict_types=1);

namespace FpHic\HicS2S\Http;

use FpHic\HicS2S\Http\Controllers\WebhookController;
use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

final class Routes
{
    public static function bootstrap(): void
    {
        if (!\function_exists('add_action')) {
            return;
        }

        \add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    public static function registerRoutes(): void
    {
        if (!\function_exists('register_rest_route')) {
            return;
        }

        $controller = new WebhookController();

        \register_rest_route(
            'hic/v1',
            '/conversion',
            [
                'methods'             => 'POST',
                'callback'            => [$controller, 'handleConversion'],
                'permission_callback' => [self::class, 'conversionPermissions'],
            ]
        );

        \register_rest_route(
            'hic/v1',
            '/health',
            [
                'methods'             => ['GET'],
                'callback'            => [$controller, 'health'],
                'permission_callback' => [$controller, 'healthPermissions'],
            ]
        );
    }

    /**
     * @param WP_REST_Request $request
     *
     * @return bool|\WP_Error
     */
    public static function conversionPermissions(WP_REST_Request $request)
    {
        if (\function_exists('hic_webhook_permission_callback')) {
            return \hic_webhook_permission_callback($request);
        }

        return true;
    }
}
