<?php declare(strict_types=1);

namespace FpHic\HicS2S\Http;

use FpHic\HicS2S\Http\Controllers\WebhookController;

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
                'methods'             => ['GET', 'POST'],
                'callback'            => [$controller, 'handleConversion'],
                'permission_callback' => '__return_true',
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
}
