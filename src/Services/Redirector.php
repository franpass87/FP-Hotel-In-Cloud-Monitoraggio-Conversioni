<?php declare(strict_types=1);

namespace FpHic\HicS2S\Services;

use FpHic\HicS2S\Admin\SettingsPage;
use FpHic\HicS2S\Repository\BookingIntents;
use FpHic\HicS2S\Repository\Logs;

if (!defined('ABSPATH')) {
    exit;
}

final class Redirector
{
    private const COOKIE_NAME = 'hic_sid';

    public static function bootstrap(): void
    {
        if (!\function_exists('add_action')) {
            return;
        }

        \add_action('template_redirect', [self::class, 'maybeHandleRedirect'], 0);
    }

    public static function maybeHandleRedirect(): void
    {
        if (!isset($_GET['fp_go_booking'])) {
            return;
        }

        $settings = SettingsPage::getSettings();

        if (empty($settings['redirector_enabled'])) {
            return;
        }

        $targetParam = isset($_GET['target']) ? (string) $_GET['target'] : '';
        $decodedTarget = base64_decode($targetParam, true);

        if (!is_string($decodedTarget) || $decodedTarget === '' || !wp_http_validate_url($decodedTarget)) {
            wp_die(__('URL di destinazione non valido.', 'hotel-in-cloud'));
        }

        $sid = self::ensureSid();

        $utmParams = self::collectPrefixedParams('utm_');
        $ids = self::collectIdentifiers();

        $intentId = wp_generate_uuid4();
        $repo = new BookingIntents();
        $logs = new Logs();

        $saved = $repo->record($intentId, $sid, $utmParams, $ids);

        if ($saved === null) {
            $logs->log('webhook', 'error', 'Impossibile salvare booking intent', [
                'intent_id' => $intentId,
                'sid' => $sid,
                'utm' => $utmParams,
                'ids' => $ids,
            ]);
        } else {
            $logs->log('webhook', 'info', 'Booking intent registrato', [
                'intent_id' => $intentId,
                'sid' => $sid,
            ]);
        }

        wp_safe_redirect($decodedTarget, 302);
        exit;
    }

    private static function ensureSid(): string
    {
        $sid = isset($_COOKIE[self::COOKIE_NAME]) ? (string) $_COOKIE[self::COOKIE_NAME] : '';

        if ($sid === '') {
            $sid = wp_generate_uuid4();
            $secure = false;

            if (function_exists('is_ssl')) {
                $secure = (bool) is_ssl();
            }

            if (!$secure) {
                $forwardedProto = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] : '';

                if ($forwardedProto !== '') {
                    $secure = stripos($forwardedProto, 'https') !== false;
                }
            }

            if (!$secure && isset($_SERVER['HTTP_X_FORWARDED_SSL'])) {
                $secure = strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL']) === 'on';
            }
            $path = defined('COOKIEPATH') ? COOKIEPATH : '/';
            $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
            $options = [
                'expires' => time() + YEAR_IN_SECONDS,
                'path' => $path,
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ];

            if (headers_sent($file, $line)) {
                (new Logs())->log('webhook', 'warning', 'Impossibile impostare il cookie SID: headers già inviati', [
                    'file' => $file,
                    'line' => $line,
                ]);
            } else {
                setcookie(self::COOKIE_NAME, $sid, $options);
                $_COOKIE[self::COOKIE_NAME] = $sid;
            }
        }

        return $sid;
    }

    /**
     * @return array<string,string>
     */
    private static function collectPrefixedParams(string $prefix): array
    {
        $params = [];

        foreach ($_GET as $key => $value) {
            if (!is_string($key) || strpos($key, $prefix) !== 0) {
                continue;
            }

            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $params[$key] = sanitize_text_field($value);
        }

        return $params;
    }

    /**
     * @return array<string,string>
     */
    private static function collectIdentifiers(): array
    {
        $keys = ['gclid', 'fbclid', 'gbraid', 'wbraid', 'msclkid', 'ttclid'];
        $ids = [];

        foreach ($keys as $key) {
            if (!isset($_GET[$key]) || !is_string($_GET[$key])) {
                continue;
            }

            $value = sanitize_text_field((string) $_GET[$key]);
            if ($value === '') {
                continue;
            }

            $ids[$key] = $value;
        }

        return $ids;
    }
}
