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
require_once __DIR__ . '/Support/Hasher.php';
require_once __DIR__ . '/Support/Http.php';
require_once __DIR__ . '/ValueObjects/BookingPayload.php';
require_once __DIR__ . '/Admin/SettingsPage.php';

use FpHic\HicS2S\Repository\BookingIntents;
use FpHic\HicS2S\Repository\Conversions;
use FpHic\HicS2S\Repository\Logs;

use FpHic\HicS2S\Admin\SettingsPage;
use FpHic\HicS2S\Http\Routes;
use FpHic\HicS2S\Services\Redirector;

if (!defined('ABSPATH')) {
    exit;
}

Routes::bootstrap();
SettingsPage::bootstrap();
Redirector::bootstrap();

if (\function_exists('add_action')) {
    \add_action('plugins_loaded', static function (): void {
        (new Conversions())->maybeMigrate();
        (new BookingIntents())->maybeMigrate();
        (new Logs())->maybeMigrate();
    }, 50);
}
