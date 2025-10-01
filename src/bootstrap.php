<?php declare(strict_types=1);

namespace FpHic\HicS2S;

require_once __DIR__ . '/Http/Routes.php';
require_once __DIR__ . '/Http/Controllers/WebhookController.php';
require_once __DIR__ . '/Services/Ga4Service.php';
require_once __DIR__ . '/Services/MetaCapiService.php';
require_once __DIR__ . '/Services/Redirector.php';
require_once __DIR__ . '/Repository/Conversions.php';
require_once __DIR__ . '/Repository/BookingIntents.php';
require_once __DIR__ . '/Repository/Logs.php';
require_once __DIR__ . '/Support/ServiceContainer.php';
require_once __DIR__ . '/Support/Hasher.php';
require_once __DIR__ . '/Support/Http.php';
require_once __DIR__ . '/Support/UserDataConsent.php';
require_once __DIR__ . '/ValueObjects/BookingPayload.php';
require_once __DIR__ . '/Admin/SettingsPage.php';
require_once __DIR__ . '/Jobs/ConversionDispatchQueue.php';

use FpHic\HicS2S\Repository\BookingIntents;
use FpHic\HicS2S\Repository\Conversions;
use FpHic\HicS2S\Repository\Logs;

use FpHic\HicS2S\Admin\SettingsPage;
use FpHic\HicS2S\Http\Routes;
use FpHic\HicS2S\Services\Redirector;
use FpHic\HicS2S\Jobs\ConversionDispatchQueue;

if (!defined('ABSPATH')) {
    exit;
}

Routes::bootstrap();
SettingsPage::bootstrap();
Redirector::bootstrap();
ConversionDispatchQueue::bootstrap();

if (\function_exists('add_action')) {
    \add_action('plugins_loaded', static function (): void {
        $container = \FpHic\HicS2S\Support\ServiceContainer::instance();
        $container->conversions()->maybeMigrate();
        $container->bookingIntents()->maybeMigrate();
        $container->logs()->maybeMigrate();
    }, 50);

    \add_action('init', static function (): void {
        if (!\function_exists('wp_next_scheduled')) {
            return;
        }

        $dayInSeconds = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;

        if (!wp_next_scheduled('hic_logs_prune')) {
            wp_schedule_event(time() + $dayInSeconds, 'daily', 'hic_logs_prune');
        }

        if (!wp_next_scheduled('hic_conversions_prune')) {
            wp_schedule_event(time() + $dayInSeconds, 'daily', 'hic_conversions_prune');
        }
    });

    \add_action('hic_logs_prune', static function (): void {
        $container = \FpHic\HicS2S\Support\ServiceContainer::instance();
        $days = (int) apply_filters('hic_logs_retention_days', 30);
        $container->logs()->pruneOlderThan($days);
    });

    \add_action('hic_conversions_prune', static function (): void {
        $container = \FpHic\HicS2S\Support\ServiceContainer::instance();
        $days = (int) apply_filters('hic_conversions_retention_days', 180);
        $container->conversions()->pruneDeliveredOlderThan($days);
    });
}
