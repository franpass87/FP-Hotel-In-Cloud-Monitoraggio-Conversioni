<?php declare(strict_types=1);

namespace FpHic\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ensure the current request is executed by a user with the required capability.
 *
 * @param string $capability Capability name to validate.
 */
function hic_require_cap(string $capability): void
{
    $capability = trim($capability);

    if ($capability === '') {
        $capability = 'hic_manage';
    }

    if (!\function_exists('current_user_can') || !\current_user_can($capability)) {
        $message = \function_exists('__')
            ? \__('Non hai i permessi necessari per completare questa operazione.', 'hotel-in-cloud')
            : 'You do not have permission to perform this action.';
        $title = \function_exists('__')
            ? \__('Accesso negato', 'hotel-in-cloud')
            : 'Access denied';

        if (\function_exists('wp_doing_ajax') && \wp_doing_ajax() && \function_exists('wp_send_json_error')) {
            \wp_send_json_error(
                [
                    'message' => $message,
                    'code' => 'hic_forbidden',
                ],
                403
            );
        }

        if (\function_exists('wp_die')) {
            \wp_die($message, $title, 403);
        }

        throw new \RuntimeException($message);
    }
}

/**
 * Sanitize SQL identifiers (table, column, index names) enforcing a strict whitelist.
 *
 * @param string $identifier Raw identifier.
 * @param string $type       Context for error messages.
 * @return string            Sanitized identifier.
 */
function hic_sanitize_identifier(string $identifier, string $type = 'identifier'): string
{
    $identifier = trim($identifier);
    $type = trim($type) !== '' ? $type : 'identifier';

    if ($identifier === '' || !preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        $message = \function_exists('__')
            ? sprintf(\__('Identificatore SQL non valido per %s.', 'hotel-in-cloud'), $type)
            : sprintf('Invalid SQL identifier for %s.', $type);
        $title = \function_exists('__')
            ? \__('Parametro non valido', 'hotel-in-cloud')
            : 'Invalid parameter';

        if (\function_exists('wp_die')) {
            \wp_die($message, $title, 400);
        }

        throw new \InvalidArgumentException($message);
    }

    return $identifier;
}

/**
 * Retrieve the global wpdb instance when available and supporting the required methods.
 *
 * @param string[] $required_methods
 * @return object|null
 */
function hic_get_wpdb_instance(array $required_methods = [])
{
    global $wpdb;

    if (!isset($wpdb) || !is_object($wpdb)) {
        return null;
    }

    foreach ($required_methods as $method) {
        if (!method_exists($wpdb, $method)) {
            return null;
        }
    }

    return $wpdb;
}

/**
 * Determine the options table name for a wpdb instance without triggering
 * dynamic property notices on custom test doubles.
 *
 * @param object $wpdb The wpdb-like instance.
 * @return string|null Fully qualified table name or null when unavailable.
 */
function hic_get_options_table_name($wpdb)
{
    if (!is_object($wpdb)) {
        return null;
    }

    $candidates = [];

    if (property_exists($wpdb, 'options')) {
        $options_table = $wpdb->options;
        if (is_string($options_table) && $options_table !== '') {
            $candidates[] = $options_table;
        }
    }

    if (property_exists($wpdb, 'prefix')) {
        $prefix = $wpdb->prefix;
        if (is_string($prefix) && $prefix !== '') {
            $candidates[] = $prefix . 'options';
        }
    }

    if (property_exists($wpdb, 'base_prefix')) {
        $base_prefix = $wpdb->base_prefix;
        if (is_string($base_prefix) && $base_prefix !== '') {
            $candidates[] = $base_prefix . 'options';
        }
    }

    if (method_exists($wpdb, 'get_blog_prefix')) {
        $blog_prefix = $wpdb->get_blog_prefix();
        if (is_string($blog_prefix) && $blog_prefix !== '') {
            $candidates[] = $blog_prefix . 'options';
        }
    }

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return null;
}

function &hic_option_cache()
{
    static $cache = [];

    return $cache;
}

function hic_get_option($key, $default = '')
{
    $cache = &hic_option_cache();
    if (!array_key_exists($key, $cache)) {
        $cache[$key] = get_option('hic_' . $key, $default);
    }

    return $cache[$key];
}

function hic_clear_option_cache($key = null)
{
    $cache = &hic_option_cache();
    if ($key === null) {
        $cache = [];

        return;
    }

    if (!is_string($key) || $key === '') {
        return;
    }

    if (strpos($key, 'hic_') === 0) {
        $key = substr($key, 4);
    }

    unset($cache[$key]);
}

/**
 * Generate a deterministic signature for a hook registration.
 *
 * @param mixed $callback The callback being registered.
 */
function hic_get_hook_signature(string $type, string $hook, $callback, int $priority, int $accepted_args): string
{
    if (is_string($callback)) {
        $identifier = $callback;
    } elseif ($callback instanceof \Closure) {
        $identifier = spl_object_hash($callback);
    } elseif (is_object($callback) && method_exists($callback, '__invoke')) {
        $identifier = spl_object_hash($callback);
    } elseif (is_array($callback) && count($callback) === 2) {
        [$callable, $method] = $callback;
        if (is_object($callable)) {
            $identifier = spl_object_hash($callable) . '::' . (string) $method;
        } else {
            $identifier = (string) $callable . '::' . (string) $method;
        }
    } else {
        $identifier = md5(var_export($callback, true));
    }

    return implode('|', [$type, $hook, $identifier, (string) $priority, (string) $accepted_args]);
}

/**
 * Determine whether the provided hook is already registered in WordPress.
 *
 * @param mixed $callback The callback being inspected.
 */
function hic_hook_is_registered(string $type, string $hook, $callback): bool
{
    if ($type === 'action' && function_exists('has_action')) {
        $result = has_action($hook, $callback);

        if ($result !== false && $result !== null) {
            return true;
        }
    }

    if (function_exists('has_filter')) {
        $result = has_filter($hook, $callback);

        if ($result !== false && $result !== null) {
            return true;
        }
    }

    return false;
}

/**
 * Safely register a WordPress hook, deferring if WordPress is not ready.
 */
function hic_safe_add_hook($type, $hook, $function, $priority = 10, $accepted_args = 1)
{
    static $deferred_hooks = [];

    $type = strtolower((string) $type) === 'filter' ? 'filter' : 'action';
    $hook = (string) $hook;
    $priority = (int) $priority;
    $accepted_args = (int) $accepted_args;

    $signature = hic_get_hook_signature($type, $hook, $function, $priority, $accepted_args);

    $hook_data = [
        'type' => $type,
        'hook' => $hook,
        'function' => $function,
        'priority' => $priority,
        'accepted_args' => $accepted_args,
    ];

    $hooks_available = function_exists('add_action') && function_exists('add_filter');

    if (!$hooks_available) {
        if (!isset($deferred_hooks[$signature])) {
            $deferred_hooks[$signature] = $hook_data;
        }

        return;
    }

    $callback_exists = hic_hook_is_registered($type, $hook, $function);

    if (!empty($deferred_hooks)) {
        foreach ($deferred_hooks as $deferred_signature => $deferred) {
            $deferred_exists = hic_hook_is_registered($deferred['type'], $deferred['hook'], $deferred['function']);

            if ($deferred_exists) {
                continue;
            }

            if ($deferred['type'] === 'action') {
                add_action($deferred['hook'], $deferred['function'], $deferred['priority'], $deferred['accepted_args']);
            } else {
                add_filter($deferred['hook'], $deferred['function'], $deferred['priority'], $deferred['accepted_args']);
            }
        }

        $deferred_hooks = [];
    }

    if ($callback_exists) {
        return;
    }

    if ($type === 'action') {
        add_action($hook, $function, $priority, $accepted_args);
    } else {
        add_filter($hook, $function, $priority, $accepted_args);
    }
}

/**
 * Initialize WordPress action hooks for the plugin helpers.
 * This function should be called when WordPress is ready.
 */
function hic_init_helper_hooks(): void
{
    hic_safe_add_hook('action', 'added_option', __NAMESPACE__ . '\\hic_clear_option_cache', 10, 1);
    hic_safe_add_hook('action', 'updated_option', __NAMESPACE__ . '\\hic_clear_option_cache', 10, 1);
    hic_safe_add_hook('action', 'deleted_option', __NAMESPACE__ . '\\hic_clear_option_cache', 10, 1);

    if (function_exists('add_action') && function_exists('add_filter')) {
        add_filter('wp_privacy_personal_data_exporters', __NAMESPACE__ . '\\hic_register_exporter');
        add_filter('wp_privacy_personal_data_erasers', __NAMESPACE__ . '\\hic_register_eraser');
        add_filter('cron_schedules', __NAMESPACE__ . '\\hic_add_failed_request_schedule');

        hic_schedule_failed_request_retry();
        hic_schedule_failed_request_cleanup();

        add_action('hic_retry_failed_requests', __NAMESPACE__ . '\\hic_retry_failed_requests');
        add_action('hic_cleanup_failed_requests', __NAMESPACE__ . '\\hic_cleanup_failed_requests');
        add_action('admin_init', __NAMESPACE__ . '\\hic_upgrade_reservation_email_map');
        add_action('admin_init', __NAMESPACE__ . '\\hic_upgrade_integration_retry_queue');
    }
}

function hic_get_measurement_id()
{
    return hic_get_option('measurement_id', '');
}

function hic_get_api_secret()
{
    return hic_get_option('api_secret', '');
}

function hic_get_brevo_api_key()
{
    return hic_get_option('brevo_api_key', '');
}

function hic_get_brevo_list_it()
{
    return hic_get_option('brevo_list_it', '20');
}

function hic_get_brevo_list_en()
{
    return hic_get_option('brevo_list_en', '21');
}

function hic_get_brevo_list_default()
{
    return hic_get_option('brevo_list_default', '20');
}

function hic_get_brevo_optin_default()
{
    return hic_get_option('brevo_optin_default', '0') === '1';
}

function hic_is_brevo_enabled()
{
    return hic_get_option('brevo_enabled', '0') === '1';
}

function hic_is_debug_verbose()
{
    return hic_get_option('debug_verbose', '0') === '1';
}

function hic_get_health_token()
{
    return hic_get_option('health_token', '');
}

function hic_updates_enrich_contacts()
{
    return hic_get_option('updates_enrich_contacts', '1') === '1';
}

function hic_get_brevo_list_alias()
{
    return hic_get_option('brevo_list_alias', '');
}

function hic_brevo_double_optin_on_enrich()
{
    return hic_get_option('brevo_double_optin_on_enrich', '0') === '1';
}

function hic_realtime_brevo_sync_enabled()
{
    return hic_get_option('realtime_brevo_sync', '1') === '1';
}

function hic_get_brevo_event_endpoint()
{
    return hic_get_option('brevo_event_endpoint', 'https://in-automate.brevo.com/api/v2/trackEvent');
}

function hic_reliable_polling_enabled()
{
    return hic_get_option('reliable_polling_enabled', '1') === '1';
}

function hic_get_admin_email()
{
    $email = sanitize_email(hic_get_option('admin_email', ''));
    if ($email === '') {
        $email = sanitize_email(get_option('admin_email'));
    }

    return $email;
}

function hic_is_gtm_enabled()
{
    return hic_get_option('gtm_enabled', '0') === '1';
}

function hic_get_gtm_container_id()
{
    return hic_get_option('gtm_container_id', '');
}

function hic_get_tracking_mode()
{
    return hic_get_option('tracking_mode', 'ga4_only');
}

function hic_get_fb_pixel_id()
{
    return hic_get_option('fb_pixel_id', '');
}

function hic_get_fb_access_token()
{
    return hic_get_option('fb_access_token', '');
}

function hic_get_connection_type()
{
    return hic_get_option('connection_type', 'webhook');
}

function hic_normalize_connection_type($type = null)
{
    if ($type === null) {
        $type = hic_get_connection_type();
    }

    if (!is_string($type)) {
        return '';
    }

    $normalized = strtolower(trim($type));

    if ($normalized === 'polling') {
        $normalized = 'api';
    }

    return $normalized;
}

function hic_connection_uses_api($type = null)
{
    $normalized = hic_normalize_connection_type($type);

    return in_array($normalized, ['api', 'hybrid'], true);
}

function hic_get_currency()
{
    return hic_get_option('currency', 'EUR');
}

function hic_use_net_value()
{
    return hic_get_option('ga4_use_net_value', '0') === '1';
}

function hic_process_invalid()
{
    return hic_get_option('process_invalid', '0') === '1';
}

function hic_allow_status_updates()
{
    return hic_get_option('allow_status_updates', '0') === '1';
}

function hic_refund_tracking_enabled()
{
    return hic_get_option('refund_tracking', '0') === '1';
}

function hic_get_polling_range_extension_days()
{
    return (int) hic_get_option('polling_range_extension_days', '7');
}

function hic_get_polling_interval()
{
    $interval = hic_get_option('polling_interval', 'every_two_minutes');
    $valid_intervals = ['every_minute', 'every_two_minutes', 'hic_poll_interval', 'hic_reliable_interval'];

    return in_array($interval, $valid_intervals, true) ? $interval : 'every_two_minutes';
}

/**
 * Normalize a feature flag identifier ensuring predictable array keys.
 */
function hic_normalize_feature_key(string $feature): string
{
    $normalized = strtolower(trim($feature));
    $normalized = str_replace('-', '_', $normalized);
    $normalized = preg_replace('/[^a-z0-9_]+/', '_', $normalized ?? '') ?? '';
    $normalized = preg_replace('/_+/', '_', $normalized) ?? '';

    return trim($normalized, '_');
}

/**
 * Retrieve the default feature flags controlled by constants/filters.
 *
 * @return array<string,bool>
 */
function hic_get_default_feature_flags(): array
{
    $defaults = [
        'enterprise_suite'   => defined('HIC_FEATURE_ENTERPRISE_SUITE') ? (bool) HIC_FEATURE_ENTERPRISE_SUITE : true,
        'google_ads_enhanced' => defined('HIC_FEATURE_GOOGLE_ADS_ENHANCED') ? (bool) HIC_FEATURE_GOOGLE_ADS_ENHANCED : true,
        'realtime_dashboard' => defined('HIC_FEATURE_REALTIME_DASHBOARD') ? (bool) HIC_FEATURE_REALTIME_DASHBOARD : true,
    ];

    $defaults = apply_filters('hic_default_feature_flags', $defaults);

    if (!is_array($defaults)) {
        return [];
    }

    $normalized = [];

    foreach ($defaults as $feature => $enabled) {
        $key = hic_normalize_feature_key((string) $feature);

        if ($key === '') {
            continue;
        }

        $normalized[$key] = (bool) $enabled;
    }

    return $normalized;
}

/**
 * Retrieve persisted feature flags merged with defaults.
 *
 * @return array<string,bool>
 */
function hic_get_feature_flags(): array
{
    $flags = hic_get_default_feature_flags();

    $stored = hic_get_option('feature_flags', []);
    if (is_array($stored)) {
        foreach ($stored as $feature => $value) {
            $key = hic_normalize_feature_key((string) $feature);

            if ($key === '') {
                continue;
            }

            $flags[$key] = (bool) $value;
        }
    }

    $flags = apply_filters('hic_feature_flags', $flags);

    if (!is_array($flags)) {
        return [];
    }

    $normalized = [];

    foreach ($flags as $feature => $value) {
        $key = hic_normalize_feature_key((string) $feature);

        if ($key === '') {
            continue;
        }

        $normalized[$key] = (bool) $value;
    }

    return $normalized;
}

/**
 * Determine whether the requested feature is enabled.
 */
function hic_is_feature_enabled(string $feature, bool $default = true): bool
{
    $key = hic_normalize_feature_key($feature);

    if ($key === '') {
        return $default;
    }

    $flags = hic_get_feature_flags();
    $enabled = array_key_exists($key, $flags) ? (bool) $flags[$key] : $default;

    $enabled = apply_filters('hic_feature_enabled', $enabled, $key, $flags);
    $enabled = apply_filters('hic_feature_enabled_' . $key, $enabled, $flags);

    return (bool) $enabled;
}

/**
 * Detect whether the current execution context matches a public frontend request.
 */
function hic_is_frontend_context(): bool
{
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        return false;
    }

    if (function_exists('wp_doing_cron') && wp_doing_cron()) {
        return false;
    }

    if (defined('REST_REQUEST') && REST_REQUEST) {
        return false;
    }

    if (function_exists('is_admin') && is_admin()) {
        return false;
    }

    if (defined('WP_CLI') && WP_CLI) {
        return false;
    }

    if (defined('PHP_SAPI') && in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
        return false;
    }

    return true;
}

/**
 * Retrieve bootstrap configuration for feature-gated modules.
 *
 * @return array<string,array<string,bool>>
 */
function hic_get_feature_bootstrap_config(): array
{
    $config = [
        'enterprise_suite' => [
            'allow_frontend' => false,
        ],
        'realtime_dashboard' => [
            'allow_frontend' => false,
        ],
        'google_ads_enhanced' => [
            'allow_frontend' => true,
        ],
    ];

    $config = apply_filters('hic_feature_bootstrap_config', $config);

    if (!is_array($config)) {
        return [];
    }

    $normalized = [];

    foreach ($config as $feature => $settings) {
        $key = hic_normalize_feature_key((string) $feature);

        if ($key === '') {
            continue;
        }

        if (!is_array($settings)) {
            $settings = [];
        }

        $normalized[$key] = [
            'allow_frontend' => array_key_exists('allow_frontend', $settings)
                ? (bool) $settings['allow_frontend']
                : true,
        ];
    }

    return $normalized;
}

/**
 * Determine whether a feature-gated module should be bootstrapped in the current request.
 */
function hic_should_bootstrap_feature(string $feature, bool $default = true): bool
{
    $key = hic_normalize_feature_key($feature);

    if ($key === '') {
        return $default;
    }

    if (!hic_is_feature_enabled($key, $default)) {
        return false;
    }

    $config = hic_get_feature_bootstrap_config();
    $settings = $config[$key] ?? ['allow_frontend' => true];

    $should_bootstrap = true;

    if (empty($settings['allow_frontend']) && hic_is_frontend_context()) {
        $should_bootstrap = false;
    }

    $should_bootstrap = apply_filters('hic_should_bootstrap_feature', $should_bootstrap, $key, $settings);
    $should_bootstrap = apply_filters('hic_should_bootstrap_feature_' . $key, $should_bootstrap, $settings);

    return (bool) $should_bootstrap;
}

