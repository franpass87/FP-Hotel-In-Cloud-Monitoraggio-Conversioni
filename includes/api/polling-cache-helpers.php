<?php declare(strict_types=1);

namespace FpHic;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Build cache key used for API connection tests.
 *
 * @param string $endpoint Fully qualified API endpoint URL.
 * @param string $email    API account email.
 * @param string $password API account password.
 *
 * @return string
 */
if (!function_exists(__NAMESPACE__ . '\\hic_get_api_test_cache_key')) {
    function hic_get_api_test_cache_key($endpoint, $email, $password) {
        $endpoint_key = sprintf('api_test_%s', $endpoint);
        $credentials_hash = md5($email . '|' . $password);

        return sprintf('%s|%s', $endpoint_key, $credentials_hash);
    }
}

