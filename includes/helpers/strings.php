<?php declare(strict_types=1);

namespace FpHic\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

const HIC_PHONE_DEFAULT_COUNTRY = 'IT';

function hic_generate_sid(): string
{
    $attempts = 0;
    $sid = '';

    do {
        $raw = (string) wp_generate_uuid4();
        $sanitized = sanitize_text_field($raw);
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $sanitized ?? '');
        $sid = substr((string) $sanitized, 0, HIC_SID_MAX_LENGTH);
        $attempts++;
    } while (($sid === '' || strlen($sid) < HIC_SID_MIN_LENGTH) && $attempts < 5);

    if (strlen($sid) < HIC_SID_MIN_LENGTH) {
        $sid = str_pad($sid, HIC_SID_MIN_LENGTH, '0');
    }

    return $sid;
}

function hic_normalize_price($value)
{
    if (empty($value) || (!is_numeric($value) && !is_string($value))) {
        return 0.0;
    }

    $normalized = (string) $value;
    $normalized = str_replace(["\xC2\xA0", ' '], '', $normalized);

    $has_comma = strpos($normalized, ',') !== false;
    $has_dot   = strpos($normalized, '.') !== false;

    if ($has_comma && $has_dot) {
        if (strrpos($normalized, ',') > strrpos($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } else {
            $normalized = str_replace(',', '', $normalized);
        }
    } elseif ($has_comma) {
        $normalized = str_replace(',', '.', $normalized);
    }

    $normalized = preg_replace('/[^0-9.-]/', '', $normalized);

    if (!is_numeric($normalized)) {
        hic_log('hic_normalize_price: Invalid numeric value after normalization: ' . $value);

        return 0.0;
    }

    $result = (float) $normalized;

    if ($result < 0) {
        hic_log('hic_normalize_price: Negative price detected: ' . $result . ' (original: ' . $value . ')');

        return 0.0;
    }

    if ($result > 999999.99) {
        hic_log('hic_normalize_price: Unusually high price detected: ' . $result . ' (original: ' . $value . ')');
    }

    return $result;
}

/**
 * Retrieve the list of supported ISO 4217 currency codes.
 *
 * @return string[]
 */
function hic_get_iso4217_currency_codes(): array
{
    static $cache = null;

    if (is_array($cache)) {
        return $cache;
    }

    if (function_exists('get_woocommerce_currencies')) {
        $currencies = array_keys((array) get_woocommerce_currencies());
        $cache = array_map('strtoupper', $currencies);

        return $cache;
    }

    $cache = [
        'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN',
        'BAM', 'BBD', 'BDT', 'BGN', 'BHD', 'BIF', 'BMD', 'BND', 'BOB', 'BOV',
        'BRL', 'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHE', 'CHF',
        'CHW', 'CLF', 'CLP', 'CNY', 'COP', 'COU', 'CRC', 'CUC', 'CUP', 'CVE',
        'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ERN', 'ETB', 'EUR', 'FJD',
        'FKP', 'GBP', 'GEL', 'GHS', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HKD',
        'HNL', 'HRK', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'IQD', 'IRR', 'ISK',
        'JMD', 'JOD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KPW', 'KRW', 'KWD',
        'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'LYD', 'MAD', 'MDL',
        'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU', 'MUR', 'MVR', 'MWK', 'MXN',
        'MXV', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'OMR',
        'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD',
        'RUB', 'RWF', 'SAR', 'SBD', 'SCR', 'SDG', 'SEK', 'SGD', 'SHP', 'SLE',
        'SLL', 'SOS', 'SRD', 'SSP', 'STN', 'SVC', 'SYP', 'SZL', 'THB', 'TJS', 'TMT',
        'TND', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD', 'USN',
        'UYI', 'UYU', 'UYW', 'UZS', 'VED', 'VES', 'VND', 'VUV', 'WST', 'XAF',
        'XAG', 'XAU', 'XBA', 'XBB', 'XBC', 'XBD', 'XCD', 'XDR', 'XOF', 'XPD',
        'XPF', 'XPT', 'XSU', 'XTS', 'XUA', 'XXX', 'YER', 'ZAR', 'ZMW', 'ZWL',
    ];

    return $cache;
}

function hic_normalize_currency_code($currency): string
{
    $fallback = 'EUR';

    if (!is_scalar($currency)) {
        return $fallback;
    }

    $normalized = strtoupper(sanitize_text_field((string) $currency));

    if ($normalized === '' || !preg_match('/^[A-Z]{3}$/', $normalized)) {
        return $fallback;
    }

    if (!in_array($normalized, hic_get_iso4217_currency_codes(), true)) {
        return $fallback;
    }

    return $normalized;
}

function hic_is_valid_email($email)
{
    if (empty($email) || !is_string($email)) {
        return false;
    }

    $email = sanitize_email($email);
    if ($email === '') {
        return false;
    }

    return is_email($email) !== false;
}

function hic_is_ota_alias_email($email)
{
    if (empty($email) || !is_string($email)) {
        return false;
    }

    $email = strtolower(trim($email));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $domains = [
        'guest.booking.com',
        'message.booking.com',
        'guest.airbnb.com',
        'airbnb.com',
        'expedia.com',
        'stay.expedia.com',
        'guest.expediapartnercentral.com',
    ];

    foreach ($domains as $domain) {
        if (substr($email, -strlen('@' . $domain)) === '@' . $domain) {
            return true;
        }
    }

    return false;
}

/**
 * Normalize a phone number and detect language by international prefix.
 *
 * @param string $phone Raw phone number.
 * @return array{phone:string, language:?string}
 */
function hic_detect_phone_language($phone)
{
    $normalized = preg_replace('/[^0-9+]/', '', (string) $phone);
    if ($normalized === '') {
        return ['phone' => '', 'language' => null];
    }

    if (strpos($normalized, '00') === 0) {
        $normalized = '+' . substr($normalized, 2);
    }

    if (strlen($normalized) <= 1) {
        return ['phone' => $normalized, 'language' => null];
    }

    if (strpos($normalized, '+') !== 0) {
        if ((strlen($normalized) >= 9 && strlen($normalized) <= 10) && ($normalized[0] === '3' || $normalized[0] === '0')) {
            return ['phone' => $normalized, 'language' => 'it'];
        }

        return ['phone' => $normalized, 'language' => null];
    }

    if (strpos($normalized, '+39') === 0) {
        return ['phone' => $normalized, 'language' => 'it'];
    }

    return ['phone' => $normalized, 'language' => 'en'];
}

/**
 * Retrieve the list of supported telephone calling codes keyed by ISO country.
 *
 * @return array<string,string>
 */
function hic_phone_country_calling_codes(): array
{
    static $codes = null;

    if ($codes === null) {
        $codes = [
            'IT' => '39',
            'SM' => '378',
            'VA' => '379',
            'US' => '1',
            'CA' => '1',
            'GB' => '44',
            'UK' => '44',
            'IE' => '353',
            'FR' => '33',
            'DE' => '49',
            'ES' => '34',
            'PT' => '351',
            'NL' => '31',
            'BE' => '32',
            'CH' => '41',
            'AT' => '43',
            'DK' => '45',
            'SE' => '46',
            'NO' => '47',
            'FI' => '358',
            'PL' => '48',
            'CZ' => '420',
            'SK' => '421',
            'HU' => '36',
            'RO' => '40',
            'HR' => '385',
            'SI' => '386',
            'BG' => '359',
            'RS' => '381',
            'GR' => '30',
            'TR' => '90',
            'RU' => '7',
            'UA' => '380',
            'IL' => '972',
            'LU' => '352',
            'IS' => '354',
            'MT' => '356',
            'CY' => '357',
            'EE' => '372',
            'LV' => '371',
            'LT' => '370',
            'LI' => '423',
            'SG' => '65',
        ];
    }

    return $codes;
}

function hic_phone_language_country_map(): array
{
    static $map = null;

    if ($map === null) {
        $map = [
            'it' => 'IT',
            'en' => 'GB',
            'fr' => 'FR',
            'de' => 'DE',
            'es' => 'ES',
            'pt' => 'PT',
            'nl' => 'NL',
            'be' => 'BE',
            'da' => 'DK',
            'sv' => 'SE',
            'no' => 'NO',
            'fi' => 'FI',
            'pl' => 'PL',
            'cs' => 'CZ',
            'sk' => 'SK',
            'hu' => 'HU',
            'ro' => 'RO',
            'hr' => 'HR',
            'sl' => 'SI',
            'bg' => 'BG',
            'sr' => 'RS',
            'el' => 'GR',
            'tr' => 'TR',
            'ru' => 'RU',
            'uk' => 'UA',
            'he' => 'IL',
            'ar' => 'AE',
            'ja' => 'JP',
            'zh' => 'CN',
            'hi' => 'IN',
            'ptbr' => 'BR',
            'esmx' => 'MX',
            'enus' => 'US',
            'engb' => 'GB',
            'enau' => 'AU',
            'ennz' => 'NZ',
            'frca' => 'CA',
            'deat' => 'AT',
            'dech' => 'CH',
        ];
    }

    return $map;
}

function hic_phone_normalize_country_value($value): ?array
{
    if (!is_scalar($value)) {
        return null;
    }

    $candidate = trim((string) $value);
    if ($candidate === '') {
        return null;
    }

    if (preg_match('/^\+?\d{1,6}$/', $candidate)) {
        return ['type' => 'code', 'value' => ltrim($candidate, '+')];
    }

    $upper = strtoupper($candidate);

    if (preg_match('/^[A-Z]{2}$/', $upper)) {
        if ($upper === 'UK') {
            $upper = 'GB';
        }

        return ['type' => 'country', 'value' => $upper];
    }

    if (preg_match('/^[A-Z]{3}$/', $upper)) {
        $map = [
            'ITA' => 'IT',
            'SMR' => 'SM',
            'VAT' => 'VA',
            'USA' => 'US',
            'GBR' => 'GB',
            'IRL' => 'IE',
            'FRA' => 'FR',
            'DEU' => 'DE',
            'ESP' => 'ES',
            'PRT' => 'PT',
            'NLD' => 'NL',
            'BEL' => 'BE',
            'CHE' => 'CH',
            'AUT' => 'AT',
            'DNK' => 'DK',
            'SWE' => 'SE',
            'NOR' => 'NO',
            'FIN' => 'FI',
            'POL' => 'PL',
            'CZE' => 'CZ',
            'SVK' => 'SK',
            'HUN' => 'HU',
            'ROU' => 'RO',
            'HRV' => 'HR',
            'SVN' => 'SI',
            'BGR' => 'BG',
            'SRB' => 'RS',
            'GRC' => 'GR',
            'TUR' => 'TR',
            'RUS' => 'RU',
            'UKR' => 'UA',
            'BRA' => 'BR',
            'ARG' => 'AR',
            'CHL' => 'CL',
            'MEX' => 'MX',
            'AUS' => 'AU',
            'NZL' => 'NZ',
            'JPN' => 'JP',
            'CHN' => 'CN',
            'IND' => 'IN',
            'ZAF' => 'ZA',
            'ARE' => 'AE',
            'ISR' => 'IL',
            'LUX' => 'LU',
            'MLT' => 'MT',
            'CYP' => 'CY',
            'EST' => 'EE',
            'LVA' => 'LV',
            'LTU' => 'LT',
            'LIE' => 'LI',
            'CAN' => 'CA',
            'SGP' => 'SG',
        ];

        if (isset($map[$upper])) {
            return ['type' => 'country', 'value' => $map[$upper]];
        }
    }

    $normalized = preg_replace('/[^A-Z]/', '', $upper);
    if ($normalized === '') {
        return null;
    }

    $names = [
        'ITALIA' => 'IT',
        'ITALY' => 'IT',
        'ITALIEN' => 'IT',
        'SANMARINO' => 'SM',
        'VATICAN' => 'VA',
        'VATICANCITY' => 'VA',
        'UNITEDKINGDOM' => 'GB',
        'REGNOUNITO' => 'GB',
        'ENGLAND' => 'GB',
        'SCOTLAND' => 'GB',
        'WALES' => 'GB',
        'IRELAND' => 'IE',
        'IRLANDA' => 'IE',
        'UNITEDSTATES' => 'US',
        'STATIUNITI' => 'US',
        'FRANCE' => 'FR',
        'GERMANY' => 'DE',
        'DEUTSCHLAND' => 'DE',
        'SPAIN' => 'ES',
        'ESPANA' => 'ES',
        'PORTUGAL' => 'PT',
        'PORTOGALLO' => 'PT',
        'NETHERLANDS' => 'NL',
        'PAESIBASSI' => 'NL',
        'BELGIUM' => 'BE',
        'BELGIO' => 'BE',
        'SWITZERLAND' => 'CH',
        'SVIZZERA' => 'CH',
        'AUSTRIA' => 'AT',
        'DENMARK' => 'DK',
        'DANIMARCA' => 'DK',
        'SWEDEN' => 'SE',
        'SVEZIA' => 'SE',
        'NORWAY' => 'NO',
        'NORVEGIA' => 'NO',
        'FINLAND' => 'FI',
        'FINLANDIA' => 'FI',
        'POLAND' => 'PL',
        'POLONIA' => 'PL',
        'CZECHREPUBLIC' => 'CZ',
        'REPUBBLICACECA' => 'CZ',
        'SLOVAKIA' => 'SK',
        'SLOVACCHIA' => 'SK',
        'HUNGARY' => 'HU',
        'UNGHERIA' => 'HU',
        'ROMANIA' => 'RO',
        'GREECE' => 'GR',
        'GRECIA' => 'GR',
        'TURKEY' => 'TR',
        'TURCHIA' => 'TR',
        'RUSSIA' => 'RU',
        'RUSSIANFEDERATION' => 'RU',
        'UKRAINE' => 'UA',
        'BRAZIL' => 'BR',
        'BRASILE' => 'BR',
        'MEXICO' => 'MX',
        'MESSICO' => 'MX',
        'ARGENTINA' => 'AR',
        'CHILE' => 'CL',
        'AUSTRALIA' => 'AU',
        'NUOVAZELANDA' => 'NZ',
        'NEWZEALAND' => 'NZ',
        'JAPAN' => 'JP',
        'GIAPPONE' => 'JP',
        'CHINA' => 'CN',
        'CINA' => 'CN',
        'INDIA' => 'IN',
        'SOUTHAFRICA' => 'ZA',
        'SUDAFRICA' => 'ZA',
        'EMIRATIARABIUNITI' => 'AE',
        'UNITEDARABEMIRATES' => 'AE',
        'ISRAEL' => 'IL',
        'LUXEMBOURG' => 'LU',
        'LUSSEMBURGO' => 'LU',
        'MALTA' => 'MT',
        'CYPRES' => 'CY',
        'CIPRO' => 'CY',
        'ESTONIA' => 'EE',
        'LETTONIA' => 'LV',
        'LATVIA' => 'LV',
        'LITUANIA' => 'LT',
        'LITHUANIA' => 'LT',
        'LIECHTENSTEIN' => 'LI',
        'CANADA' => 'CA',
        'SINGAPORE' => 'SG',
    ];

    if (isset($names[$normalized])) {
        return ['type' => 'country', 'value' => $names[$normalized]];
    }

    return null;
}

function hic_phone_map_language_to_country($language): ?array
{
    if (!is_scalar($language)) {
        return null;
    }

    $value = strtolower(trim((string) $language));
    if ($value === '') {
        return null;
    }

    $value = str_replace([' ', '_'], '-', $value);
    $parts = explode('-', $value);

    if (isset($parts[1])) {
        $region_candidate = hic_phone_normalize_country_value($parts[1]);
        if ($region_candidate !== null) {
            return $region_candidate;
        }
    }

    $primary = preg_replace('/[^a-z]/', '', $parts[0]);
    if ($primary === '') {
        return null;
    }

    $map = hic_phone_language_country_map();
    if (isset($map[$primary])) {
        return ['type' => 'country', 'value' => $map[$primary]];
    }

    return null;
}

function hic_phone_get_default_country(): ?array
{
    $settings = function_exists('get_option') ? get_option('hic_google_ads_enhanced_settings', []) : [];

    if (is_array($settings) && array_key_exists('default_phone_country', $settings)) {
        $stored = $settings['default_phone_country'];

        if (!is_scalar($stored)) {
            return null;
        }

        $stored_value = (string) $stored;
        if ($stored_value === '') {
            return null;
        }

        return hic_phone_normalize_country_value($stored_value);
    }

    return hic_phone_normalize_country_value(HIC_PHONE_DEFAULT_COUNTRY);
}

function hic_phone_resolve_calling_code(array $country): ?string
{
    if (empty($country['type']) || empty($country['value'])) {
        return null;
    }

    if ($country['type'] === 'code') {
        $digits = preg_replace('/\D/', '', $country['value']);

        return $digits !== '' ? $digits : null;
    }

    $iso = strtoupper($country['value']);
    $codes = hic_phone_country_calling_codes();

    return $codes[$iso] ?? null;
}

function hic_phone_should_retain_trunk_zero(array $country): bool
{
    if (($country['type'] ?? '') === 'code') {
        return true;
    }

    $iso = strtoupper($country['value'] ?? '');
    $retain = ['IT', 'SM', 'VA'];

    return in_array($iso, $retain, true);
}

function hic_normalize_phone_for_hash($phone, array $context = []): ?string
{
    if (!is_scalar($phone)) {
        return null;
    }

    $raw_phone = trim((string) $phone);
    if ($raw_phone === '') {
        return null;
    }

    $customer_data = [];
    if (!empty($context['customer_data']) && is_array($context['customer_data'])) {
        $customer_data = $context['customer_data'];
    }

    $booking_data = [];
    if (!empty($context['booking_data']) && is_array($context['booking_data'])) {
        $booking_data = $context['booking_data'];
    }

    $details = hic_detect_phone_language($raw_phone);
    $helper_phone = $details['phone'] ?? null;
    $detected_language = $details['language'] ?? null;

    $normalized_phone = preg_replace('/[^0-9+]/', '', $helper_phone ?? $raw_phone);
    if ($normalized_phone === '') {
        return null;
    }

    if (strpos($normalized_phone, '00') === 0) {
        $normalized_phone = '+' . substr($normalized_phone, 2);
    }

    if ($normalized_phone !== '' && $normalized_phone[0] === '+') {
        $digits = preg_replace('/\D/', '', substr($normalized_phone, 1));

        return $digits !== '' ? '+' . $digits : null;
    }

    $numeric_phone = preg_replace('/\D/', '', $normalized_phone);
    if ($numeric_phone === '') {
        return null;
    }

    $country_candidates = [];
    if (!empty($context['country_candidates']) && is_array($context['country_candidates'])) {
        foreach ($context['country_candidates'] as $candidate) {
            if (is_array($candidate) && isset($candidate['type'], $candidate['value'])) {
                $country_candidates[] = $candidate;

                continue;
            }

            $normalized_candidate = hic_phone_normalize_country_value($candidate);
            if ($normalized_candidate !== null) {
                $country_candidates[] = $normalized_candidate;
            }
        }
    }

    foreach ([$customer_data, $booking_data] as $dataset) {
        if (!is_array($dataset)) {
            continue;
        }

        foreach (['country_code', 'country', 'phone_country'] as $field) {
            if (isset($dataset[$field]) && is_scalar($dataset[$field])) {
                $candidate = hic_phone_normalize_country_value($dataset[$field]);
                if ($candidate !== null) {
                    $country_candidates[] = $candidate;
                }
            }
        }
    }

    $sid = '';
    if (array_key_exists('sid', $context) && is_scalar($context['sid'])) {
        $sid = (string) $context['sid'];
    } elseif (isset($booking_data['sid']) && is_scalar($booking_data['sid'])) {
        $sid = (string) $booking_data['sid'];
    }

    if ($sid !== '' && function_exists('apply_filters')) {
        $sid_country = apply_filters('hic_google_ads_phone_country_from_sid', null, $sid, $customer_data, $booking_data);
        if (is_string($sid_country) && $sid_country !== '') {
            $candidate = hic_phone_normalize_country_value($sid_country);
            if ($candidate !== null) {
                $country_candidates[] = $candidate;
            }
        }
    }

    $language_sources = [];
    if (is_string($detected_language) && $detected_language !== '') {
        $language_sources[] = $detected_language;
    }

    if (!empty($context['language_candidates']) && is_array($context['language_candidates'])) {
        foreach ($context['language_candidates'] as $language_candidate) {
            if (is_scalar($language_candidate)) {
                $language_sources[] = (string) $language_candidate;
            }
        }
    }

    foreach ([$customer_data, $booking_data] as $dataset) {
        if (!is_array($dataset)) {
            continue;
        }

        foreach (['phone_language', 'language', 'locale'] as $field) {
            if (!empty($dataset[$field]) && is_scalar($dataset[$field])) {
                $language_sources[] = (string) $dataset[$field];
            }
        }
    }

    foreach ($language_sources as $language_candidate) {
        $language_country = hic_phone_map_language_to_country($language_candidate);
        if ($language_country !== null) {
            $country_candidates[] = $language_country;
        }
    }

    $default_country = null;
    if (array_key_exists('default_country', $context)) {
        $hint = $context['default_country'];
        if (is_array($hint) && isset($hint['type'], $hint['value'])) {
            $default_country = $hint;
        } else {
            $default_country = hic_phone_normalize_country_value($hint);
        }
    }

    if ($default_country === null) {
        $default_country = hic_phone_get_default_country();
    }

    if ($default_country !== null) {
        $country_candidates[] = $default_country;
    }

    $selected_country = null;
    $calling_code = null;
    foreach ($country_candidates as $candidate) {
        if (!is_array($candidate) || empty($candidate['type']) || empty($candidate['value'])) {
            continue;
        }

        $code = hic_phone_resolve_calling_code($candidate);
        if ($code !== null) {
            $selected_country = $candidate;
            $calling_code = $code;
            break;
        }
    }

    $logger = null;
    if (isset($context['logger']) && is_callable($context['logger'])) {
        $logger = $context['logger'];
    }

    $warn = static function (string $message) use ($logger): void {
        $level = defined('HIC_LOG_LEVEL_WARNING') ? HIC_LOG_LEVEL_WARNING : 'warning';
        if (is_callable($logger)) {
            $logger($message, $level);

            return;
        }

        if (function_exists('\\FpHic\\hic_log')) {
            \FpHic\hic_log($message, $level);
        }
    };

    if ($calling_code === null) {
        $warn(sprintf('Unable to determine country prefix for phone "%s"; skipping phone hash.', $raw_phone));

        return null;
    }

    if (strpos($numeric_phone, $calling_code) === 0 && strlen($numeric_phone) > strlen($calling_code) + 2) {
        return '+' . $numeric_phone;
    }

    $national_number = $numeric_phone;
    if ($selected_country !== null && !hic_phone_should_retain_trunk_zero($selected_country)) {
        $trimmed = preg_replace('/^0+/', '', $national_number);
        if ($trimmed !== '') {
            $national_number = $trimmed;
        }
    }

    if ($national_number === '') {
        $warn(sprintf('Normalized phone number became empty for "%s"; skipping phone hash.', $raw_phone));

        return null;
    }

    return '+' . $calling_code . $national_number;
}

function hic_hash_normalized_phone($phone, array $context = []): ?string
{
    $normalized = hic_normalize_phone_for_hash($phone, $context);
    if ($normalized === null) {
        return null;
    }

    return hash('sha256', $normalized);
}

