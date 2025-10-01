<?php declare(strict_types=1);

namespace FpHic\HicS2S\Support;

use FpHic\HicS2S\ValueObjects\BookingPayload;

if (!defined('ABSPATH')) {
    exit;
}

final class UserDataConsent
{
    public static function shouldSend(BookingPayload $payload): bool
    {
        $raw = $payload->getRaw();
        $consentKeys = ['consent', 'marketing_consent', 'analytics_consent', 'privacy_consent', 'tcf_consent', 'cmode'];

        $consent = null;

        foreach ($consentKeys as $key) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }

            $value = $raw[$key];

            if (is_string($value)) {
                $value = strtolower(trim($value));
            }

            if ($value === true || $value === 'granted' || $value === 'yes' || $value === 'true' || $value === '1' || $value === 1) {
                $consent = true;
                continue;
            }

            if ($value === false || $value === 'denied' || $value === 'no' || $value === 'false' || $value === '0' || $value === 0) {
                $consent = false;
                break;
            }

            if ($value !== null && $value !== '') {
                $consent = false;
                break;
            }
        }

        /** @var mixed $filtered */
        $filtered = apply_filters('hic_s2s_user_data_consent', $consent, $raw, $payload);

        if ($filtered !== null) {
            return (bool) $filtered;
        }

        if ($consent !== null) {
            return (bool) $consent;
        }

        return true;
    }
}
