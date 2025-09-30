<?php declare(strict_types=1);

namespace FpHic\Helpers;

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

function hic_register_rest_route_fallback(string $namespace, string $route, array $args): void
{
    if (!defined('HIC_REST_API_FALLBACK')) {
        return;
    }

    if (!isset($GLOBALS['hic_rest_route_registry']) || !is_array($GLOBALS['hic_rest_route_registry'])) {
        $GLOBALS['hic_rest_route_registry'] = [];
    }

    $normalized_namespace = trim($namespace, '/');
    $normalized_route = '/' . $normalized_namespace . '/' . ltrim($route, '/');
    $normalized_route = preg_replace('#/+#', '/', $normalized_route ?? '');

    $GLOBALS['hic_rest_route_registry'][$normalized_route] = [
        'namespace' => $namespace,
        'route'     => $route,
        'args'      => $args,
    ];
}

/**
 * Retrieve the list of REST routes captured by the fallback registry.
 *
 * @return array<string,array<string,mixed>>
 */
function hic_get_registered_rest_routes(): array
{
    if (defined('HIC_REST_API_FALLBACK') && HIC_REST_API_FALLBACK) {
        hic_include_rest_route_fallback_files();

        if (!isset($GLOBALS['hic_rest_route_registry']) || !is_array($GLOBALS['hic_rest_route_registry'])) {
            $GLOBALS['hic_rest_route_registry'] = [];
        }

        if (function_exists('hic_get_webhook_route_args')) {
            $normalized_route = '/hic/v1/conversion';
            if (in_array(hic_get_connection_type(), ['webhook', 'hybrid'], true)) {
                $GLOBALS['hic_rest_route_registry'][$normalized_route] = [
                    'namespace' => 'hic/v1',
                    'route'     => '/conversion',
                    'args'      => hic_get_webhook_route_args(),
                ];
            } else {
                unset($GLOBALS['hic_rest_route_registry'][$normalized_route]);
            }
        }

        return $GLOBALS['hic_rest_route_registry'];
    }

    if (!isset($GLOBALS['hic_rest_route_registry']) || !is_array($GLOBALS['hic_rest_route_registry'])) {
        return [];
    }

    return $GLOBALS['hic_rest_route_registry'];
}

function hic_reset_registered_rest_routes(): void
{
    $GLOBALS['hic_rest_route_registry'] = [];
}

function hic_include_rest_route_fallback_files(): void
{
    static $included = false;

    if ($included) {
        return;
    }

    $included = true;

    foreach ([
        __DIR__ . '/../api/webhook.php',
        __DIR__ . '/../health-monitor.php',
        __DIR__ . '/../integrations/gtm.php',
    ] as $fallback_include) {
        if (is_string($fallback_include) && file_exists($fallback_include)) {
            require_once $fallback_include;
        }
    }
}

if (defined('HIC_REST_API_FALLBACK') && HIC_REST_API_FALLBACK) {
    hic_include_rest_route_fallback_files();
}

function hic_get_webhook_token()
{
    return hic_get_option('webhook_token', '');
}

function hic_get_webhook_secret(): string
{
    $secret = hic_get_option('webhook_secret', '');

    if (!is_string($secret)) {
        return '';
    }

    return trim($secret);
}

function hic_get_api_url()
{
    return hic_get_option('api_url', '');
}

function hic_get_api_key()
{
    return hic_get_option('api_key', '');
}

function hic_get_api_email()
{
    if (defined('HIC_API_EMAIL') && !empty(HIC_API_EMAIL)) {
        return HIC_API_EMAIL;
    }

    return hic_get_option('api_email', '');
}

function hic_get_api_password()
{
    if (defined('HIC_API_PASSWORD') && !empty(HIC_API_PASSWORD)) {
        return HIC_API_PASSWORD;
    }

    return hic_get_option('api_password', '');
}

function hic_get_property_id()
{
    if (defined('HIC_PROPERTY_ID') && !empty(HIC_PROPERTY_ID)) {
        return HIC_PROPERTY_ID;
    }

    return hic_get_option('property_id', '');
}

function hic_has_basic_auth_credentials()
{
    return hic_get_property_id() && hic_get_api_email() && hic_get_api_password();
}

function hic_acquire_polling_lock($timeout = 300)
{
    $lock_key = 'hic_polling_lock';
    $lock_value = current_time('timestamp');

    $existing_lock = get_transient($lock_key);
    if ($existing_lock && ($lock_value - $existing_lock) < $timeout) {
        return false;
    }

    return set_transient($lock_key, $lock_value, $timeout);
}

function hic_release_polling_lock()
{
    return delete_transient('hic_polling_lock');
}

function hic_http_request($url, $args = [], bool $suppress_failed_storage = false)
{
    $validated_url = wp_http_validate_url($url);
    if (!$validated_url) {
        hic_log('HTTP request rifiutata: URL non valido ' . $url, HIC_LOG_LEVEL_ERROR);

        return new WP_Error('invalid_url', 'URL non valido');
    }

    if ('https' !== parse_url($validated_url, PHP_URL_SCHEME)) {
        $allow_insecure = apply_filters('hic_allow_insecure_http', false, $validated_url, $args);
        if (!$allow_insecure) {
            hic_log('HTTP request rifiutata: solo HTTPS consentito ' . $url, HIC_LOG_LEVEL_ERROR);

            return new WP_Error('invalid_url', 'Solo HTTPS consentito');
        }
    }

    $url = $validated_url;

    if (!isset($args['timeout'])) {
        $args['timeout'] = defined('HIC_API_TIMEOUT') ? HIC_API_TIMEOUT : 15;
    }

    $version = defined('HIC_PLUGIN_VERSION') ? HIC_PLUGIN_VERSION : '1.0';
    if (!isset($args['user-agent'])) {
        $args['user-agent'] = 'HIC-Plugin/' . $version;
    }

    if (!isset($args['headers']) || !is_array($args['headers'])) {
        $args['headers'] = [];
    }

    $args['headers']['User-Agent'] = 'HIC-Plugin/' . $version;

    $response = wp_safe_remote_request($url, $args);

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        hic_log('HTTP request error: ' . $error_message, HIC_LOG_LEVEL_ERROR);
        if (!$suppress_failed_storage) {
            hic_store_failed_request($url, $args, $error_message);
        }
    } else {
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 400) {
            $error_message = 'HTTP ' . $code;
            hic_log('HTTP request to ' . $url . ' failed with status ' . $code, HIC_LOG_LEVEL_ERROR);
            if (!$suppress_failed_storage) {
                hic_store_failed_request($url, $args, $error_message);
            }
        }
    }

    return $response;
}

function hic_store_failed_request($url, $args, $error)
{
    $wpdb = hic_get_wpdb_instance(['insert']);
    if (!$wpdb) {
        return;
    }

    $table = $wpdb->prefix . 'hic_failed_requests';
    $max_attempts = 2;
    $attempt = 0;
    $table_recreation_attempted = false;
    $table_recreation_succeeded = false;

    do {
        $attempt++;

        $insert_result = $wpdb->insert(
            $table,
            [
                'endpoint'   => $url,
                'payload'    => wp_json_encode($args),
                'attempts'   => 1,
                'last_error' => $error,
                'last_try'   => current_time('mysql'),
            ],
            ['%s', '%s', '%d', '%s', '%s']
        );

        if (is_wp_error($insert_result)) {
            $log_result = hic_log(
                'Failed to store failed request: ' . $insert_result->get_error_message(),
                HIC_LOG_LEVEL_ERROR,
                [
                    'endpoint' => $url,
                    'error'    => $error,
                    'attempt'  => $attempt,
                ]
            );

            if (is_wp_error($log_result)) {
                error_log('HIC logging failure: ' . $log_result->get_error_message());
            }

            return;
        }

        if ($insert_result === false) {
            $db_error_message = trim((string) $wpdb->last_error);
            if ($db_error_message === '') {
                $db_error_message = 'Unknown database error';
            }

            $lower_error = strtolower($db_error_message);
            $missing_table_indicators = [
                'no such table',
                'does not exist',
                "doesn't exist",
                'missing table',
                'unknown table',
                '1146',
            ];

            $missing_table_detected = false;
            foreach ($missing_table_indicators as $indicator) {
                if ($indicator !== '' && strpos($lower_error, $indicator) !== false) {
                    $missing_table_detected = true;
                    break;
                }
            }

            if ($missing_table_detected && function_exists('\\hic_create_failed_requests_table') && !$table_recreation_attempted) {
                $table_recreation_attempted = true;
                $table_recreation_succeeded = (bool) \hic_create_failed_requests_table();

                if ($table_recreation_succeeded && $attempt < $max_attempts) {
                    continue;
                }
            }

            $context = [
                'endpoint' => $url,
                'error'    => $error,
                'db_error' => $db_error_message,
                'table'    => $table,
                'attempt'  => $attempt,
            ];

            hic_log('Failed to store failed request: ' . $db_error_message, HIC_LOG_LEVEL_ERROR, $context);

            return;
        }

        break;
    } while ($attempt < $max_attempts);
}

