<?php declare(strict_types=1);
namespace FpHic\Helpers {

/**
 * Helper functions for HIC Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ================= CONFIG FUNCTIONS ================= */
function &hic_option_cache() {
    static $cache = [];
    return $cache;
}

function hic_get_option($key, $default = '') {
    $cache = &hic_option_cache();
    if (!array_key_exists($key, $cache)) {
        $cache[$key] = get_option('hic_' . $key, $default);
    }
    return $cache[$key];
}

function hic_clear_option_cache($key = null) {
    $cache = &hic_option_cache();
    if ($key === null) {
        $cache = [];
        return;
    }

    if (strpos($key, 'hic_') === 0) {
        $key = substr($key, 4);
    }

    unset($cache[$key]);
}

/**
 * Safely register a WordPress hook, deferring if WordPress is not ready
 */
function hic_safe_add_hook($type, $hook, $function, $priority = 10, $accepted_args = 1) {
    static $deferred_hooks = [];
    
    if (function_exists('add_action') && function_exists('add_filter')) {
        // WordPress is ready, register the hook
        if ($type === 'action') {
            add_action($hook, $function, $priority, $accepted_args);
        } else {
            add_filter($hook, $function, $priority, $accepted_args);
        }
        
        // Also register any deferred hooks
        foreach ($deferred_hooks as $deferred) {
            if ($deferred['type'] === 'action') {
                add_action($deferred['hook'], $deferred['function'], $deferred['priority'], $deferred['accepted_args']);
            } else {
                add_filter($deferred['hook'], $deferred['function'], $deferred['priority'], $deferred['accepted_args']);
            }
        }
        $deferred_hooks = [];
    } else {
        // WordPress not ready, defer the hook registration
        $deferred_hooks[] = [
            'type' => $type,
            'hook' => $hook,
            'function' => $function,
            'priority' => $priority,
            'accepted_args' => $accepted_args
        ];
    }
}

/**
 * Initialize WordPress action hooks for the plugin helpers
 * This function should be called when WordPress is ready
 */
function hic_init_helper_hooks() {
    // Trigger any deferred hook registrations
    hic_safe_add_hook('action', 'updated_option', __NAMESPACE__ . '\\hic_clear_option_cache', 10, 1);
    
    if (function_exists('add_action') && function_exists('add_filter')) {
        add_filter('wp_privacy_personal_data_exporters', __NAMESPACE__ . '\\hic_register_exporter');
        add_filter('wp_privacy_personal_data_erasers', __NAMESPACE__ . '\\hic_register_eraser');
        add_filter('cron_schedules', __NAMESPACE__ . '\\hic_add_failed_request_schedule');
        add_action('init', __NAMESPACE__ . '\\hic_schedule_failed_request_retry');
        add_action('hic_retry_failed_requests', __NAMESPACE__ . '\\hic_retry_failed_requests');
    }
}

// Helper functions to get configuration values
function hic_get_measurement_id() { return hic_get_option('measurement_id', ''); }
function hic_get_api_secret() { return hic_get_option('api_secret', ''); }
function hic_get_brevo_api_key() { return hic_get_option('brevo_api_key', ''); }
function hic_get_brevo_list_it() { return hic_get_option('brevo_list_it', '20'); }
function hic_get_brevo_list_en() { return hic_get_option('brevo_list_en', '21'); }
function hic_get_brevo_list_default() { return hic_get_option('brevo_list_default', '20'); }
function hic_get_brevo_optin_default() { return hic_get_option('brevo_optin_default', '0') === '1'; }
function hic_is_brevo_enabled() { return hic_get_option('brevo_enabled', '0') === '1'; }
function hic_is_debug_verbose() { return hic_get_option('debug_verbose', '0') === '1'; }

// New email enrichment settings
function hic_updates_enrich_contacts() { return hic_get_option('updates_enrich_contacts', '1') === '1'; }
function hic_get_brevo_list_alias() { return hic_get_option('brevo_list_alias', ''); }
function hic_brevo_double_optin_on_enrich() { return hic_get_option('brevo_double_optin_on_enrich', '0') === '1'; }

// Real-time sync settings
function hic_realtime_brevo_sync_enabled() { return hic_get_option('realtime_brevo_sync', '1') === '1'; }
function hic_get_brevo_event_endpoint() { 
    return hic_get_option('brevo_event_endpoint', 'https://in-automate.brevo.com/api/v2/trackEvent'); 
}

// Reliable polling settings
function hic_reliable_polling_enabled() { return hic_get_option('reliable_polling_enabled', '1') === '1'; }

// Admin and General Settings
function hic_get_admin_email() { return hic_get_option('admin_email', get_option('admin_email')); }
function hic_get_log_file() {
    return hic_get_option('log_file', WP_CONTENT_DIR . '/uploads/hic-logs/hic-log.txt');
}

function hic_validate_log_path($path) {
    $base_dir = WP_CONTENT_DIR . '/uploads/hic-logs/';
    $default = $base_dir . 'hic-log.txt';

    $path = sanitize_text_field($path);
    if (empty($path)) {
        return $default;
    }

    $normalized_path = str_replace('\\', '/', $path);
    $normalized_base = rtrim(str_replace('\\', '/', $base_dir), '/') . '/';

    if (strpos($normalized_path, '..') !== false || strpos($normalized_path, $normalized_base) !== 0) {
        return $default;
    }

    return $normalized_path;
}

/**
 * Mask common sensitive data like emails, phone numbers and tokens.
 */
function hic_mask_sensitive_data($message) {
    if (!is_string($message)) {
        return $message;
    }

    // Mask email addresses
    $message = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/u', '[masked-email]', $message);

    // Mask phone numbers (sequences of digits, spaces or dashes)
    $message = preg_replace('/\b(?:\+?\d[\d\s\-]{7,}\d)\b/u', '[masked-phone]', $message);

    // Mask tokens and authorization headers
    $message = preg_replace('/(token|api[_-]?key|secret|password)\s*[=:]\s*[A-Za-z0-9._-]+/iu', '$1=[masked]', $message);
    $message = preg_replace('/Authorization:\s*Bearer\s+[A-Za-z0-9._-]+/iu', 'Authorization: Bearer [masked]', $message);

    return $message;
}

/**
 * Default filter for hic_log_message that masks sensitive data.
 *
 * @param string $message Log message
 * @param string $level   Log level
 * @return string
 */
function hic_default_log_message_filter($message, $level) {
    if (is_array($message)) {
        foreach ($message as $key => $value) {
            if (is_string($key) && preg_match('/^(?:email)$/i', $key)) {
                $message[$key] = '[masked-email]';
                continue;
            }
            if (is_string($key) && preg_match('/(token|api[_-]?key|secret|password)/i', $key)) {
                $message[$key] = '[masked]';
                continue;
            }
            $message[$key] = hic_default_log_message_filter($value, $level);
        }
        return $message;
    }

    if (is_object($message)) {
        foreach ($message as $key => $value) {
            if (is_string($key) && preg_match('/^(?:email)$/i', $key)) {
                $message->$key = '[masked-email]';
                continue;
            }
            if (is_string($key) && preg_match('/(token|api[_-]?key|secret|password)/i', $key)) {
                $message->$key = '[masked]';
                continue;
            }
            $message->$key = hic_default_log_message_filter($value, $level);
        }
        return $message;
    }

    if (is_bool($message) || $message === null) {
        return $message;
    }

    if (is_string($message)) {
        $masked = hic_mask_sensitive_data($message);
        if ($masked !== $message) {
            return $masked;
        }
        if (is_numeric($message)) {
            return '[masked-number]';
        }
        return $masked;
    }

    if (is_numeric($message)) {
        return '[masked-number]';
    }

    return $message;
}

if (function_exists('add_filter')) {
    add_filter('hic_log_message', __NAMESPACE__ . '\\hic_default_log_message_filter', 10, 2);
}

// GTM Settings
function hic_is_gtm_enabled() { return hic_get_option('gtm_enabled', '0') === '1'; }
function hic_get_gtm_container_id() { return hic_get_option('gtm_container_id', ''); }
function hic_get_tracking_mode() { return hic_get_option('tracking_mode', 'ga4_only'); }

// Facebook Settings
function hic_get_fb_pixel_id() { return hic_get_option('fb_pixel_id', ''); }
function hic_get_fb_access_token() { return hic_get_option('fb_access_token', ''); }

// Hotel in Cloud Connection Settings (with wp-config.php constants support)
function hic_get_connection_type() { return hic_get_option('connection_type', 'webhook'); }
function hic_get_webhook_token() { return hic_get_option('webhook_token', ''); }
function hic_get_api_url() { return hic_get_option('api_url', ''); }
function hic_get_api_key() { return hic_get_option('api_key', ''); }

function hic_get_api_email() { 
    // Check for wp-config.php constant first, then fall back to option
    if (defined('HIC_API_EMAIL') && !empty(HIC_API_EMAIL)) {
        return HIC_API_EMAIL;
    }
    return hic_get_option('api_email', ''); 
}

function hic_get_api_password() { 
    // Check for wp-config.php constant first, then fall back to option
    if (defined('HIC_API_PASSWORD') && !empty(HIC_API_PASSWORD)) {
        return HIC_API_PASSWORD;
    }
    return hic_get_option('api_password', ''); 
}

function hic_get_property_id() { 
    // Check for wp-config.php constant first, then fall back to option
    if (defined('HIC_PROPERTY_ID') && !empty(HIC_PROPERTY_ID)) {
        return HIC_PROPERTY_ID;
    }
    return hic_get_option('property_id', ''); 
}

/**
 * Helper function to check if Basic Auth credentials are configured
 */
function hic_has_basic_auth_credentials() {
    return hic_get_property_id() && hic_get_api_email() && hic_get_api_password();
}

// HIC Extended Integration Settings
function hic_get_currency() { return hic_get_option('currency', 'EUR'); }
function hic_use_net_value() { return hic_get_option('ga4_use_net_value', '0') === '1'; }
function hic_process_invalid() { return hic_get_option('process_invalid', '0') === '1'; }
function hic_allow_status_updates() { return hic_get_option('allow_status_updates', '0') === '1'; }
function hic_refund_tracking_enabled() { return hic_get_option('refund_tracking', '0') === '1'; }
function hic_get_polling_range_extension_days() { return intval(hic_get_option('polling_range_extension_days', '7')); }

/**
 * Get configured polling interval for quasi-realtime polling
 */
function hic_get_polling_interval() { 
    $interval = hic_get_option('polling_interval', 'every_two_minutes'); 
    $valid_intervals = array('every_minute', 'every_two_minutes', 'hic_poll_interval', 'hic_reliable_interval');
    return in_array($interval, $valid_intervals) ? $interval : 'every_two_minutes';
}

/**
 * Quasi-realtime polling lock functions
 */
function hic_acquire_polling_lock($timeout = 300) {
    $lock_key = 'hic_polling_lock';
    $lock_value = current_time('timestamp');
    
    // Check if lock exists and is still valid
    $existing_lock = get_transient($lock_key);
    if ($existing_lock && ($lock_value - $existing_lock) < $timeout) {
        return false; // Lock is held by another process
    }
    
    // Acquire lock
    return set_transient($lock_key, $lock_value, $timeout);
}

function hic_release_polling_lock() {
    return delete_transient('hic_polling_lock');
}

/**
 * Check if retry event should be scheduled based on conditions
 */
function hic_should_schedule_retry_event() {
    if (!hic_realtime_brevo_sync_enabled()) {
        return false;
    }
    
    if (!hic_get_brevo_api_key()) {
        return false;
    }
    
    $schedules = wp_get_schedules();
    return isset($schedules['hic_retry_interval']);
}

/* ============ Privacy Exporter/Eraser ============ */

function hic_register_exporter($exporters) {
    $exporters['hic-tracking-data'] = [
        'exporter_friendly_name' => __('Dati di tracciamento HIC', 'hotel-in-cloud'),
        'callback' => __NAMESPACE__ . '\\hic_export_tracking_data',
    ];
    return $exporters;
}

function hic_register_eraser($erasers) {
    $erasers['hic-tracking-data'] = [
        'eraser_friendly_name' => __('Dati di tracciamento HIC', 'hotel-in-cloud'),
        'callback' => __NAMESPACE__ . '\\hic_erase_tracking_data',
    ];
    return $erasers;
}

/**
 * Export tracking data associated with an email address.
 */
function hic_export_tracking_data($email_address, $page = 1) {
    global $wpdb;

    if (!$wpdb) {
        return ['data' => [], 'done' => true];
    }

    $table = $wpdb->prefix . 'hic_gclids';
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    if (!$table_exists) {
        return ['data' => [], 'done' => true];
    }

    $email = sanitize_email($email_address);
    if (empty($email)) {
        return ['data' => [], 'done' => true];
    }

    $column_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'email'));
    if (!$column_exists) {
        return ['data' => [], 'done' => true];
    }

    $number = 50;
    $offset = ($page - 1) * $number;
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, gclid, fbclid, sid, utm_source, utm_medium, utm_campaign, utm_content, utm_term, created_at FROM $table WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
            $email,
            $number,
            $offset
        )
    );

    $data = [];
    foreach ($rows as $row) {
        $item = [];
        if ($row->gclid !== null) {
            $item[] = ['name' => 'gclid', 'value' => $row->gclid];
        }
        if ($row->fbclid !== null) {
            $item[] = ['name' => 'fbclid', 'value' => $row->fbclid];
        }
        if ($row->sid !== null) {
            $item[] = ['name' => 'sid', 'value' => $row->sid];
        }
        if ($row->utm_source !== null) {
            $item[] = ['name' => 'utm_source', 'value' => $row->utm_source];
        }
        if ($row->utm_medium !== null) {
            $item[] = ['name' => 'utm_medium', 'value' => $row->utm_medium];
        }
        if ($row->utm_campaign !== null) {
            $item[] = ['name' => 'utm_campaign', 'value' => $row->utm_campaign];
        }
        if ($row->utm_content !== null) {
            $item[] = ['name' => 'utm_content', 'value' => $row->utm_content];
        }
        if ($row->utm_term !== null) {
            $item[] = ['name' => 'utm_term', 'value' => $row->utm_term];
        }
        if ($row->created_at !== null) {
            $item[] = ['name' => 'created_at', 'value' => $row->created_at];
        }

        $data[] = [
            'group_id'    => 'hic_tracking_data',
            'group_label' => __('Dati di tracciamento HIC', 'hotel-in-cloud'),
            'item_id'     => 'tracking-' . $row->id,
            'data'        => $item,
        ];
    }

    $done = count($rows) < $number;

    return ['data' => $data, 'done' => $done];
}

/**
 * Erase tracking data associated with an email address.
 */
function hic_erase_tracking_data($email_address, $page = 1) {
    global $wpdb;

    if (!$wpdb) {
        return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    $table = $wpdb->prefix . 'hic_gclids';
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    if (!$table_exists) {
        return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    $email = sanitize_email($email_address);
    if (empty($email)) {
        return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    $column_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'email'));
    if (!$column_exists) {
        return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    $number = 50;
    $offset = ($page - 1) * $number;
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id FROM $table WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
            $email,
            $number,
            $offset
        )
    );

    $items_removed = false;
    foreach ($rows as $row) {
        $deleted = $wpdb->delete($table, ['id' => $row->id], ['%d']);
        if ($deleted) {
            $items_removed = true;
        }
    }

    $done = count($rows) < $number;

    return [
        'items_removed'  => $items_removed,
        'items_retained' => false,
        'messages'       => [],
        'done'           => $done,
    ];
}

// Note: These hooks are now registered in hic_init_helper_hooks() function
// add_filter('wp_privacy_personal_data_exporters', __NAMESPACE__ . '\\hic_register_exporter');
// add_filter('wp_privacy_personal_data_erasers', __NAMESPACE__ . '\\hic_register_eraser');

/* ============ New Helper Functions ============ */

/**
 * Retrieve tracking IDs (gclid and fbclid) for a given SID from database.
 *
 * @param string $sid Session identifier
 * @return array{gclid:?string, fbclid:?string, msclkid:?string, ttclid:?string}
*/
function hic_get_tracking_ids_by_sid($sid) {
    static $cache = [];
    $sid = sanitize_text_field($sid);
    if (empty($sid)) {
        return ['gclid' => null, 'fbclid' => null, 'msclkid' => null, 'ttclid' => null];
    }

    if (array_key_exists($sid, $cache)) {
        return $cache[$sid];
    }

    global $wpdb;
    if (!$wpdb) {
        hic_log('hic_get_tracking_ids_by_sid: wpdb is not available');
        return $cache[$sid] = ['gclid' => null, 'fbclid' => null, 'msclkid' => null, 'ttclid' => null];
    }

    $table = $wpdb->prefix . 'hic_gclids';

    // Ensure table exists before querying
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    if (!$table_exists) {
        hic_log('hic_get_tracking_ids_by_sid: Table does not exist: ' . $table);
        return $cache[$sid] = ['gclid' => null, 'fbclid' => null, 'msclkid' => null, 'ttclid' => null];
    }
    $row = $wpdb->get_row($wpdb->prepare("SELECT gclid, fbclid, msclkid, ttclid FROM $table WHERE sid=%s ORDER BY id DESC LIMIT 1", $sid));

    if ($wpdb->last_error) {
        hic_log('hic_get_tracking_ids_by_sid: Database error retrieving tracking IDs: ' . $wpdb->last_error);
        return $cache[$sid] = ['gclid' => null, 'fbclid' => null, 'msclkid' => null, 'ttclid' => null];
    }

    if ($row) {
        return $cache[$sid] = [
            'gclid' => $row->gclid,
            'fbclid' => $row->fbclid,
            'msclkid' => $row->msclkid,
            'ttclid' => $row->ttclid,
        ];
    }

    return $cache[$sid] = ['gclid' => null, 'fbclid' => null, 'msclkid' => null, 'ttclid' => null];
}

/**
 * Retrieve UTM parameters for a given SID from database.
 *
 * @param string $sid Session identifier
 * @return array{utm_source:?string, utm_medium:?string, utm_campaign:?string, utm_content:?string, utm_term:?string}
*/
function hic_get_utm_params_by_sid($sid) {
    static $cache = [];
    $sid = sanitize_text_field($sid);
    if (empty($sid)) {
        return ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_content' => null, 'utm_term' => null];
    }

    if (array_key_exists($sid, $cache)) {
        return $cache[$sid];
    }

    global $wpdb;
    if (!$wpdb) {
        hic_log('hic_get_utm_params_by_sid: wpdb is not available');
        return $cache[$sid] = ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_content' => null, 'utm_term' => null];
    }

    $table = $wpdb->prefix . 'hic_gclids';

    // Ensure table exists before querying
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    if (!$table_exists) {
        hic_log('hic_get_utm_params_by_sid: Table does not exist: ' . $table);
        return $cache[$sid] = ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_content' => null, 'utm_term' => null];
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT utm_source, utm_medium, utm_campaign, utm_content, utm_term FROM $table WHERE sid=%s ORDER BY id DESC LIMIT 1", $sid));

    if ($wpdb->last_error) {
        hic_log('hic_get_utm_params_by_sid: Database error retrieving UTM params: ' . $wpdb->last_error);
        return $cache[$sid] = ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_content' => null, 'utm_term' => null];
    }

    if ($row) {
        return $cache[$sid] = [
            'utm_source'   => $row->utm_source,
            'utm_medium'   => $row->utm_medium,
            'utm_campaign' => $row->utm_campaign,
            'utm_content'  => $row->utm_content,
            'utm_term'     => $row->utm_term,
        ];
    }

    return $cache[$sid] = ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_content' => null, 'utm_term' => null];
}

function hic_normalize_price($value) {
    if (empty($value) || (!is_numeric($value) && !is_string($value))) return 0.0;

    $normalized = (string) $value;

    // Remove spaces and non-breaking spaces
    $normalized = str_replace(["\xC2\xA0", ' '], '', $normalized);

    $has_comma = strpos($normalized, ',') !== false;
    $has_dot   = strpos($normalized, '.') !== false;

    if ($has_comma && $has_dot) {
        // Determine decimal separator by last occurrence
        if (strrpos($normalized, ',') > strrpos($normalized, '.')) {
            // European format: dot for thousands, comma for decimals
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } else {
            // US format: comma for thousands, dot for decimals
            $normalized = str_replace(',', '', $normalized);
        }
    } elseif ($has_comma) {
        // Only comma present -> treat as decimal separator
        $normalized = str_replace(',', '.', $normalized);
    }

    // Remove any non-numeric characters except dots and minus signs for negative values
    $normalized = preg_replace('/[^0-9.-]/', '', $normalized);

    // Validate that we still have a numeric value
    if (!is_numeric($normalized)) {
        hic_log('hic_normalize_price: Invalid numeric value after normalization: ' . $value);
        return 0.0;
    }

    $result = floatval($normalized);
    
    // Validate reasonable price range
    if ($result < 0) {
        hic_log('hic_normalize_price: Negative price detected: ' . $result . ' (original: ' . $value . ')');
        return 0.0;
    }
    
    if ($result > 999999.99) {
        hic_log('hic_normalize_price: Unusually high price detected: ' . $result . ' (original: ' . $value . ')');
    }
    
    return $result;
}

function hic_is_valid_email($email) {
    if (empty($email) || !is_string($email)) return false;
    
    // Sanitize email first
    $email = sanitize_email($email);
    if (empty($email)) return false;
    
    // Use WordPress built-in email validation and return boolean
    return is_email($email) !== false;
}

function hic_is_ota_alias_email($e){
    if (empty($e) || !is_string($e)) return false;
    $e = strtolower(trim($e));
    
    // Validate email format first
    if (!filter_var($e, FILTER_VALIDATE_EMAIL)) return false;
    
    $domains = array(
      'guest.booking.com', 'message.booking.com',
      'guest.airbnb.com','airbnb.com',
      'expedia.com','stay.expedia.com','guest.expediapartnercentral.com'
    );
    
    foreach ($domains as $d) {
        if (substr($e, -strlen('@'.$d)) === '@'.$d) return true;
    }
    return false;
}

function hic_booking_uid($reservation) {
    if (!is_array($reservation)) {
        hic_log('hic_booking_uid: reservation is not an array');
        return '';
    }
    
    // Try multiple possible ID fields in order of preference
    $id_fields = ['id', 'reservation_id', 'booking_id', 'transaction_id'];
    
    foreach ($id_fields as $field) {
        if (!empty($reservation[$field]) && is_scalar($reservation[$field])) {
            return (string) $reservation[$field];
        }
    }
    
    hic_log('hic_booking_uid: No valid ID found in reservation data');
    return '';
}

/* ============ Helpers ============ */
function hic_http_request($url, $args = []) {
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
        hic_store_failed_request($url, $args, $error_message);
    } else {
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 400) {
            $error_message = 'HTTP ' . $code;
            hic_log('HTTP request to ' . $url . ' failed with status ' . $code, HIC_LOG_LEVEL_ERROR);
            hic_store_failed_request($url, $args, $error_message);
        }
    }

    return $response;
}

function hic_store_failed_request($url, $args, $error) {
    global $wpdb;
    if (!$wpdb) {
        return;
    }

    $table = $wpdb->prefix . 'hic_failed_requests';

    $wpdb->insert(
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
}

function hic_log($msg, $level = HIC_LOG_LEVEL_INFO, $context = []) {
    $log_manager = function_exists('\\hic_get_log_manager') ? \hic_get_log_manager() : null;

    if ($log_manager) {
        return $log_manager->log($msg, $level, $context);
    }
    return false;
}

/**
 * Normalize bucket attribution according to priority: gclid > fbclid > organic
 * 
 * @param string|null $gclid Google Click ID from Google Ads
 * @param string|null $fbclid Facebook Click ID from Meta Ads
 * @return string One of: 'gads', 'fbads', 'organic'
 */
function fp_normalize_bucket($gclid, $fbclid){
  if (!empty($gclid) && trim($gclid) !== '')  return 'gads';
  if (!empty($fbclid) && trim($fbclid) !== '') return 'fbads';
  return 'organic';
}

/**
 * Legacy function name for backward compatibility
 * @deprecated Use fp_normalize_bucket() instead
 */
function hic_get_bucket($gclid, $fbclid){
  return fp_normalize_bucket($gclid, $fbclid);
}

/* ============ Email admin (include bucket) ============ */
function hic_send_admin_email($data, $gclid, $fbclid, $sid){
  // Validate input data
  if (!is_array($data)) {
    hic_log('hic_send_admin_email: data is not an array');
    return false;
  }
  
  $bucket = fp_normalize_bucket($gclid, $fbclid);
  $to = hic_get_admin_email();
  
  // Enhanced email validation with detailed logging
  if (empty($to)) {
    hic_log('hic_send_admin_email: admin email is empty');
    return false;
  }
  
  if (!hic_is_valid_email($to)) {
    hic_log('hic_send_admin_email: invalid admin email format: ' . $to);
    return false;
  }
  
  // Check WordPress email configuration
  if (!function_exists('wp_mail')) {
    hic_log('hic_send_admin_email: wp_mail function not available');
    return false;
  }
  
  // Log which admin email is being used for transparency
  $custom_email = hic_get_option('admin_email', '');
  if (!empty($custom_email)) {
    hic_log('hic_send_admin_email: using custom admin email from settings: ' . $to);
  } else {
    hic_log('hic_send_admin_email: using WordPress default admin email: ' . $to);
  }
  
  // Log WordPress mail configuration for debugging
  $phpmailer_init_triggered = false;
  $phpmailer_error = '';
  
  // Add temporary hook to capture PHPMailer errors
  $phpmailer_hook = function($phpmailer) use (&$phpmailer_init_triggered, &$phpmailer_error) {
    $phpmailer_init_triggered = true;
    if ($phpmailer->ErrorInfo) {
      $phpmailer_error = $phpmailer->ErrorInfo;
    }
  };
  add_action('phpmailer_init', $phpmailer_hook);
  
  $site_name = get_bloginfo('name');
  if (empty($site_name)) {
    $site_name = 'Hotel in Cloud';
  }
  
  $subject = "Nuova prenotazione da " . $site_name;

  $body  = "Hai ricevuto una nuova prenotazione da $site_name:\n\n";
  $body .= "Reservation ID: " . ($data['reservation_id'] ?? ($data['id'] ?? 'n/a')) . "\n";
  $body .= "Importo: " . (isset($data['amount']) ? hic_normalize_price($data['amount']) : '0') . " " . ($data['currency'] ?? 'EUR') . "\n";
  $body .= "Nome: " . trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')) . "\n";
  $body .= "Email: " . ($data['email'] ?? 'n/a') . "\n";
  $body .= "Telefono: " . ($data['phone'] ?? 'n/a') . "\n";
  $body .= "Lingua: " . ($data['lingua'] ?? ($data['lang'] ?? 'n/a')) . "\n";
  $body .= "Camera: " . ($data['room'] ?? 'n/a') . "\n";
  $body .= "Check-in: " . ($data['checkin'] ?? 'n/a') . "\n";
  $body .= "Check-out: " . ($data['checkout'] ?? 'n/a') . "\n";
  $body .= "SID: " . ($sid ?? 'n/a') . "\n";
  $body .= "GCLID: " . ($gclid ?? 'n/a') . "\n";
  $body .= "FBCLID: " . ($fbclid ?? 'n/a') . "\n";
  $body .= "Bucket: " . $bucket . "\n";

  // Allow customization of admin email subject and body
  $subject = apply_filters('hic_admin_email_subject', $subject, $data);
  $body    = apply_filters('hic_admin_email_body', $body, $data);

  $content_type_filter = function(){ return 'text/plain; charset=UTF-8'; };
  add_filter('wp_mail_content_type', $content_type_filter);
  
  // Enhanced email sending with detailed error reporting
  hic_log('hic_send_admin_email: attempting to send email to ' . $to . ' with subject: ' . $subject);
  
  $sent = wp_mail($to, $subject, $body);
  
  // Remove filters and capture additional debugging info
  remove_filter('wp_mail_content_type', $content_type_filter);
  remove_action('phpmailer_init', $phpmailer_hook);

  // Enhanced logging with detailed error information
  if ($sent) {
    hic_log('Email admin inviata con successo (bucket='.$bucket.') a '.$to);
    if ($phpmailer_init_triggered) {
      hic_log('PHPMailer configuration was initialized correctly');
    }
    return true;
  } else {
    $error_details = 'wp_mail returned false';
    
    // Capture PHPMailer specific errors
    if (!empty($phpmailer_error)) {
      $error_details .= ' - PHPMailer Error: ' . $phpmailer_error;
    }
    
    // Check for common WordPress mail issues
    if (!$phpmailer_init_triggered) {
      $error_details .= ' - PHPMailer was not initialized (possible mail function disabled)';
    }
    
    // Log detailed error information
    hic_log('ERRORE invio email admin a '.$to.' - '.$error_details);
    
    // Log server mail configuration for debugging
    if (function_exists('ini_get')) {
      $smtp_config = ini_get('SMTP');
      $sendmail_path = ini_get('sendmail_path');
      hic_log('Server mail config - SMTP: ' . ($smtp_config ?: 'not set') . ', Sendmail: ' . ($sendmail_path ?: 'not set'));
    }
    
    return false;
  }
}

/* ============ Email Configuration Testing ============ */
function hic_test_email_configuration($recipient_email = null) {
    $result = array(
        'success' => false,
        'message' => '',
        'details' => array()
    );
    
    // Use admin email if no recipient specified
    if (empty($recipient_email)) {
        $recipient_email = hic_get_admin_email();
    }
    
    // Validate recipient email
    if (empty($recipient_email) || !hic_is_valid_email($recipient_email)) {
        $result['message'] = 'Invalid recipient email: ' . $recipient_email;
        return $result;
    }
    
    // Check WordPress mail function availability
    if (!function_exists('wp_mail')) {
        $result['message'] = 'wp_mail function not available';
        return $result;
    }
    
    // Capture PHPMailer configuration
    $phpmailer_info = array();
    $phpmailer_hook = function($phpmailer) use (&$phpmailer_info) {
        $phpmailer_info['mailer'] = $phpmailer->Mailer;
        $phpmailer_info['host'] = $phpmailer->Host;
        $phpmailer_info['port'] = $phpmailer->Port;
        $phpmailer_info['smtp_secure'] = $phpmailer->SMTPSecure;
        $phpmailer_info['smtp_auth'] = $phpmailer->SMTPAuth;
        $phpmailer_info['username'] = $phpmailer->Username;
        $phpmailer_info['from'] = $phpmailer->From;
        $phpmailer_info['from_name'] = $phpmailer->FromName;
    };
    
    add_action('phpmailer_init', $phpmailer_hook);
    
    // Prepare test email
    $subject = 'HIC Email Configuration Test - ' . current_time('mysql');
    $body = "Questo è un test di configurazione email per il plugin Hotel in Cloud.\n\n";
    $body .= "Timestamp: " . current_time('mysql') . "\n";
    $body .= "Destinatario: " . $recipient_email . "\n";
    $body .= "Sito: " . get_bloginfo('name') . "\n";
    $body .= "URL: " . get_bloginfo('url') . "\n\n";
    $body .= "Se ricevi questa email, la configurazione email funziona correttamente.";
    
    // Send test email
    $sent = wp_mail($recipient_email, $subject, $body);
    
    remove_action('phpmailer_init', $phpmailer_hook);
    
    // Collect server mail configuration
    $server_config = array();
    if (function_exists('ini_get')) {
        $server_config['smtp'] = ini_get('SMTP') ?: 'not set';
        $server_config['smtp_port'] = ini_get('smtp_port') ?: 'not set';
        $server_config['sendmail_path'] = ini_get('sendmail_path') ?: 'not set';
        $server_config['mail_function'] = function_exists('mail') ? 'available' : 'not available';
    }
    
    // Build result
    $result['details']['phpmailer'] = $phpmailer_info;
    $result['details']['server_config'] = $server_config;
    $result['details']['wp_admin_email'] = get_option('admin_email');
    $result['details']['hic_admin_email'] = hic_get_option('admin_email', '');
    $result['details']['effective_admin_email'] = hic_get_admin_email();
    
    if ($sent) {
        $result['success'] = true;
        $result['message'] = 'Email di test inviata con successo a ' . $recipient_email;
        hic_log('Email test configuration sent successfully to ' . $recipient_email);
    } else {
        $result['message'] = 'Errore nell\'invio dell\'email di test a ' . $recipient_email;
        hic_log('Email test configuration failed for ' . $recipient_email . ' - Check server mail configuration');
    }
    
    return $result;
}

/* ============ Email Diagnostics and Troubleshooting ============ */
function hic_diagnose_email_issues() {
    $issues = array();
    $suggestions = array();
    
    // Check 1: Admin email configuration
    $admin_email = hic_get_admin_email();
    if (empty($admin_email)) {
        $issues[] = 'Email amministratore non configurato';
        $suggestions[] = 'Configura un indirizzo email nelle impostazioni HIC';
    } elseif (!hic_is_valid_email($admin_email)) {
        $issues[] = 'Email amministratore non valido: ' . $admin_email;
        $suggestions[] = 'Correggi l\'indirizzo email nelle impostazioni';
    }
    
    // Check 2: WordPress mail function
    if (!function_exists('wp_mail')) {
        $issues[] = 'Funzione wp_mail non disponibile';
        $suggestions[] = 'Problema critico di WordPress - contatta lo sviluppatore';
    }
    
    // Check 3: PHP mail function
    if (!function_exists('mail')) {
        $issues[] = 'Funzione mail() PHP non disponibile sul server';
        $suggestions[] = 'Contatta il provider hosting per abilitare la funzione mail()';
    }
    
    // Check 4: Server configuration
    if (function_exists('ini_get')) {
        $smtp_config = ini_get('SMTP');
        $sendmail_path = ini_get('sendmail_path');
        
        if (empty($smtp_config) && empty($sendmail_path)) {
            $issues[] = 'Configurazione email server non trovata';
            $suggestions[] = 'Installa un plugin SMTP (WP Mail SMTP, Easy WP SMTP) o contatta l\'hosting';
        }
    }
    
    // Check 5: Recent email sending attempts (if function exists)
    $email_errors = 0;
    $log_manager = function_exists('\\hic_get_log_manager') ? \hic_get_log_manager() : null;
    $recent_lines = $log_manager ? $log_manager->get_recent_logs(50) : array();

    foreach ($recent_lines as $line) {
        // Handle both raw string lines and parsed log entries
        if (is_array($line)) {
            $line = $line['message'] ?? '';
        }

        if (strpos($line, 'ERRORE invio email') !== false) {
            $email_errors++;
        }
    }
    
    if ($email_errors > 0) {
        $issues[] = "$email_errors errori email negli ultimi log";
        $suggestions[] = 'Controlla i log dettagliati nella sezione Diagnostics';
    }
    
    return array(
        'issues' => $issues,
        'suggestions' => $suggestions,
        'has_issues' => !empty($issues)
    );
}

/* ============ Email Enrichment Functions ============ */
function hic_mark_email_enriched($reservation_id, $real_email) {
    if (empty($reservation_id) || !is_scalar($reservation_id)) {
        hic_log('hic_mark_email_enriched: reservation_id is empty or not scalar');
        return false;
    }
    
    if (empty($real_email) || !is_string($real_email) || !hic_is_valid_email($real_email)) {
        hic_log('hic_mark_email_enriched: real_email is empty, not string, or invalid email format');
        return false;
    }
    
    $email_map = get_option('hic_res_email_map', array());
    if (!is_array($email_map)) {
        $email_map = array(); // Reset if corrupted
    }
    
    $email_map[$reservation_id] = $real_email;
    
    // Keep only last 5k entries (FIFO) to prevent bloat
    if (count($email_map) > 5000) {
        $email_map = array_slice($email_map, -5000, null, true);
    }
    
    $result = update_option('hic_res_email_map', $email_map, false); // autoload=false
    hic_clear_option_cache('hic_res_email_map');
    return $result;
}

function hic_get_reservation_email($reservation_id) {
    if (empty($reservation_id) || !is_scalar($reservation_id)) {
        return null;
    }
    
    $email_map = get_option('hic_res_email_map', array());
    if (!is_array($email_map)) {
        return null; // Corrupted data
    }
    
    return isset($email_map[$reservation_id]) ? $email_map[$reservation_id] : null;
}

/* ================= DEDUPLICATION HELPER FUNCTIONS ================= */

/**
 * Extract reservation ID from webhook data for deduplication
 */
function hic_extract_reservation_id($data) {
    if (!is_array($data)) {
        return null;
    }
    
    // Try different field names in order of preference
    $id_fields = ['transaction_id', 'reservation_id', 'id', 'booking_id'];
    
    foreach ($id_fields as $field) {
        if (!empty($data[$field]) && is_scalar($data[$field])) {
            return (string) $data[$field];
        }
    }
    
    return null;
}

/**
 * Mark reservation as processed by ID (for webhook deduplication)
 */
function hic_mark_reservation_processed_by_id($reservation_id) {
    if (empty($reservation_id)) return false;
    
    $synced = get_option('hic_synced_res_ids', array());
    if (!is_array($synced)) {
        $synced = array();
    }
    
    if (!in_array($reservation_id, $synced)) {
        $synced[] = $reservation_id;
        
        // Keep only last 10k entries (FIFO)
        if (count($synced) > 10000) {
            $synced = array_slice($synced, -10000);
        }
        
        update_option('hic_synced_res_ids', $synced, false); // autoload=false
        hic_clear_option_cache('hic_synced_res_ids');
        hic_log("Marked reservation $reservation_id as processed for deduplication");
        return true;
    }
    
    return false;
}

/* ================= TRANSACTION LOCKING FUNCTIONS ================= */

/**
 * Acquire a lock for processing a specific reservation to prevent concurrent processing
 */
function hic_acquire_reservation_lock($reservation_id, $timeout = 30) {
  if (empty($reservation_id)) return false;
  
  $lock_key = 'hic_processing_lock_' . md5($reservation_id);
  $lock_time = current_time('timestamp');
  
  // Check if there's already a recent lock
  $existing_lock = get_transient($lock_key);
  if ($existing_lock !== false) {
    $time_diff = $lock_time - $existing_lock;
    if ($time_diff < $timeout) {
      hic_log("Reservation $reservation_id: processing lock exists (age: {$time_diff}s), skipping");
      return false;
    } else {
      hic_log("Reservation $reservation_id: expired lock found (age: {$time_diff}s), acquiring new lock");
    }
  }
  
  // Set the lock with timeout
  set_transient($lock_key, $lock_time, $timeout);
  hic_log("Reservation $reservation_id: processing lock acquired");
  return true;
}

/**
 * Release the processing lock for a reservation
 */
function hic_release_reservation_lock($reservation_id) {
  if (empty($reservation_id)) return false;
  
  $lock_key = 'hic_processing_lock_' . md5($reservation_id);
  delete_transient($lock_key);
  hic_log("Reservation $reservation_id: processing lock released");
  return true;
}

/**
 * Check if reservation ID was already processed (shared with polling)
 */
function hic_is_reservation_already_processed($reservation_id) {
    if (empty($reservation_id)) return false;
    
    $synced = get_option('hic_synced_res_ids', array());
    if (!is_array($synced)) {
        return false;
    }
    
    return in_array($reservation_id, $synced);
}

/* ================= DIAGNOSTIC FUNCTIONS ================= */

/**
 * Get processing statistics for diagnostics
 */
function hic_get_processing_statistics() {
    $synced = get_option('hic_synced_res_ids', array());
    $current_locks = array();
    
    // Check for active locks (this is just for diagnostics)
    // In production, locks are short-lived (30 seconds max)
    $lock_prefix = 'hic_processing_lock_';
    global $wpdb;
    
    $statistics = array(
        'total_processed_reservations' => is_array($synced) ? count($synced) : 0,
        'last_webhook_processing' => get_option('hic_last_webhook_processing', 'never'),
        'last_polling_processing' => get_option('hic_last_api_poll', 'never'),
        'connection_type' => hic_get_connection_type(),
        'deduplication_enabled' => true,
        'transaction_locking_enabled' => true
    );
    
    return $statistics;
}

/* ================= SAFE WORDPRESS CRON HELPERS ================= */

/**
 * Safely check if an event is scheduled
 */
function hic_safe_wp_next_scheduled($hook) {
    if (!function_exists('wp_next_scheduled')) {
        return false;
    }
    return wp_next_scheduled($hook);
}

/**
 * Safely schedule an event
 */
function hic_safe_wp_schedule_event($timestamp, $recurrence, $hook, $args = array()) {
    if (!function_exists('wp_schedule_event')) {
        return false;
    }
    return wp_schedule_event($timestamp, $recurrence, $hook, $args);
}

/**
 * Safely clear scheduled hooks
 */
function hic_safe_wp_clear_scheduled_hook($hook, $args = array()) {
    if (!function_exists('wp_clear_scheduled_hook')) {
        return false;
    }
    return wp_clear_scheduled_hook($hook, $args);
}

function hic_add_failed_request_schedule($schedules) {
    $schedules['hic_every_fifteen_minutes'] = array(
        'interval' => 15 * 60,
        'display'  => 'Every 15 Minutes (HIC Failed Requests)'
    );
    return $schedules;
}
// Note: This hook is now registered in hic_init_helper_hooks() function
// add_filter('cron_schedules', __NAMESPACE__ . '\\hic_add_failed_request_schedule');

function hic_schedule_failed_request_retry() {
    if (!hic_safe_wp_next_scheduled('hic_retry_failed_requests')) {
        hic_safe_wp_schedule_event(time(), 'hic_every_fifteen_minutes', 'hic_retry_failed_requests');
    }
}
// Note: This hook is now registered in hic_init_helper_hooks() function
// add_action('init', __NAMESPACE__ . '\\hic_schedule_failed_request_retry');

function hic_retry_failed_requests() {
    global $wpdb;
    if (!$wpdb) {
        return;
    }

    $table = $wpdb->prefix . 'hic_failed_requests';
    $rows  = $wpdb->get_results("SELECT * FROM $table");

    if (empty($rows)) {
        return;
    }

    foreach ($rows as $row) {
        if ($row->attempts >= 5) {
            $wpdb->delete($table, array('id' => $row->id));
            continue;
        }

        $delay     = 15 * 60 * pow(2, max(0, $row->attempts - 1));
        $next_time = strtotime($row->last_try) + $delay;
        if (time() < $next_time) {
            continue;
        }

        $args     = json_decode($row->payload, true);
        $response = wp_safe_remote_request($row->endpoint, is_array($args) ? $args : array());

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 400) {
            $error_message = is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response);
            $wpdb->update(
                $table,
                array(
                    'attempts'   => $row->attempts + 1,
                    'last_error' => $error_message,
                    'last_try'   => current_time('mysql'),
                ),
                array('id' => $row->id),
                array('%d', '%s', '%s'),
                array('%d')
            );
            hic_log('Retry failed for ' . $row->endpoint . ': ' . $error_message, HIC_LOG_LEVEL_ERROR);
            if ($row->attempts + 1 >= 5) {
                $wpdb->delete($table, array('id' => $row->id));
            }
        } else {
            hic_log('Retry succeeded for ' . $row->endpoint);
            $wpdb->delete($table, array('id' => $row->id));
        }
    }
}
// Note: This hook is now registered in hic_init_helper_hooks() function
// add_action('hic_retry_failed_requests', __NAMESPACE__ . '\\hic_retry_failed_requests');

}

// Global wrappers for backward compatibility
namespace {
    function hic_http_request($url, $args = array()) { return \FpHic\Helpers\hic_http_request($url, $args); }
    function hic_get_option($key, $default = '') { return \FpHic\Helpers\hic_get_option($key, $default); }
    function hic_clear_option_cache($key = null) { return \FpHic\Helpers\hic_clear_option_cache($key); }
    function hic_get_measurement_id() { return \FpHic\Helpers\hic_get_measurement_id(); }
    function hic_get_api_secret() { return \FpHic\Helpers\hic_get_api_secret(); }
    function hic_get_brevo_api_key() { return \FpHic\Helpers\hic_get_brevo_api_key(); }
    function hic_get_brevo_list_it() { return \FpHic\Helpers\hic_get_brevo_list_it(); }
    function hic_get_brevo_list_en() { return \FpHic\Helpers\hic_get_brevo_list_en(); }
    function hic_get_brevo_list_default() { return \FpHic\Helpers\hic_get_brevo_list_default(); }
    function hic_get_brevo_optin_default() { return \FpHic\Helpers\hic_get_brevo_optin_default(); }
    function hic_is_brevo_enabled() { return \FpHic\Helpers\hic_is_brevo_enabled(); }
    function hic_is_debug_verbose() { return \FpHic\Helpers\hic_is_debug_verbose(); }
    function hic_updates_enrich_contacts() { return \FpHic\Helpers\hic_updates_enrich_contacts(); }
    function hic_get_brevo_list_alias() { return \FpHic\Helpers\hic_get_brevo_list_alias(); }
    function hic_brevo_double_optin_on_enrich() { return \FpHic\Helpers\hic_brevo_double_optin_on_enrich(); }
    function hic_realtime_brevo_sync_enabled() { return \FpHic\Helpers\hic_realtime_brevo_sync_enabled(); }
    function hic_get_brevo_event_endpoint() { return \FpHic\Helpers\hic_get_brevo_event_endpoint(); }
    function hic_reliable_polling_enabled() { return \FpHic\Helpers\hic_reliable_polling_enabled(); }
    function hic_get_admin_email() { return \FpHic\Helpers\hic_get_admin_email(); }
    function hic_get_log_file() { return \FpHic\Helpers\hic_get_log_file(); }
    function hic_is_gtm_enabled() { return \FpHic\Helpers\hic_is_gtm_enabled(); }
    function hic_get_gtm_container_id() { return \FpHic\Helpers\hic_get_gtm_container_id(); }
    function hic_get_tracking_mode() { return \FpHic\Helpers\hic_get_tracking_mode(); }
    function hic_get_fb_pixel_id() { return \FpHic\Helpers\hic_get_fb_pixel_id(); }
    function hic_get_fb_access_token() { return \FpHic\Helpers\hic_get_fb_access_token(); }
    function hic_get_connection_type() { return \FpHic\Helpers\hic_get_connection_type(); }
    function hic_get_webhook_token() { return \FpHic\Helpers\hic_get_webhook_token(); }
    function hic_get_api_url() { return \FpHic\Helpers\hic_get_api_url(); }
    function hic_get_api_key() { return \FpHic\Helpers\hic_get_api_key(); }
    function hic_get_api_email() { return \FpHic\Helpers\hic_get_api_email(); }
    function hic_get_api_password() { return \FpHic\Helpers\hic_get_api_password(); }
    function hic_get_property_id() { return \FpHic\Helpers\hic_get_property_id(); }
    function hic_has_basic_auth_credentials() { return \FpHic\Helpers\hic_has_basic_auth_credentials(); }
    function hic_get_currency() { return \FpHic\Helpers\hic_get_currency(); }
    function hic_use_net_value() { return \FpHic\Helpers\hic_use_net_value(); }
    function hic_process_invalid() { return \FpHic\Helpers\hic_process_invalid(); }
    function hic_allow_status_updates() { return \FpHic\Helpers\hic_allow_status_updates(); }
    function hic_refund_tracking_enabled() { return \FpHic\Helpers\hic_refund_tracking_enabled(); }
    function hic_get_polling_range_extension_days() { return \FpHic\Helpers\hic_get_polling_range_extension_days(); }
    function hic_get_polling_interval() { return \FpHic\Helpers\hic_get_polling_interval(); }
    function hic_acquire_polling_lock($timeout = 300) { return \FpHic\Helpers\hic_acquire_polling_lock($timeout); }
    function hic_release_polling_lock() { return \FpHic\Helpers\hic_release_polling_lock(); }
    function hic_should_schedule_retry_event() { return \FpHic\Helpers\hic_should_schedule_retry_event(); }
    function hic_get_tracking_ids_by_sid($sid) { return \FpHic\Helpers\hic_get_tracking_ids_by_sid($sid); }
    function hic_get_utm_params_by_sid($sid) { return \FpHic\Helpers\hic_get_utm_params_by_sid($sid); }
    function hic_normalize_price($value) { return \FpHic\Helpers\hic_normalize_price($value); }
    function hic_is_valid_email($email) { return \FpHic\Helpers\hic_is_valid_email($email); }
    function hic_is_ota_alias_email($e) { return \FpHic\Helpers\hic_is_ota_alias_email($e); }
    function hic_booking_uid($reservation) { return \FpHic\Helpers\hic_booking_uid($reservation); }
    function hic_mask_sensitive_data($message) { return \FpHic\Helpers\hic_mask_sensitive_data($message); }
    function hic_default_log_message_filter($message, $level) { return \FpHic\Helpers\hic_default_log_message_filter($message, $level); }
    function hic_log($msg, $level = HIC_LOG_LEVEL_INFO, $context = []) { return \FpHic\Helpers\hic_log($msg, $level, $context); }
    function fp_normalize_bucket($gclid, $fbclid) { return \FpHic\Helpers\fp_normalize_bucket($gclid, $fbclid); }
    function hic_get_bucket($gclid, $fbclid) { return \FpHic\Helpers\hic_get_bucket($gclid, $fbclid); }
    function hic_send_admin_email($data, $gclid, $fbclid, $sid) { return \FpHic\Helpers\hic_send_admin_email($data, $gclid, $fbclid, $sid); }
    function hic_test_email_configuration($recipient_email = null) { return \FpHic\Helpers\hic_test_email_configuration($recipient_email); }
    function hic_diagnose_email_issues() { return \FpHic\Helpers\hic_diagnose_email_issues(); }
    function hic_mark_email_enriched($reservation_id, $real_email) { return \FpHic\Helpers\hic_mark_email_enriched($reservation_id, $real_email); }
    function hic_get_reservation_email($reservation_id) { return \FpHic\Helpers\hic_get_reservation_email($reservation_id); }
    function hic_extract_reservation_id($data) { return \FpHic\Helpers\hic_extract_reservation_id($data); }
    function hic_mark_reservation_processed_by_id($reservation_id) { return \FpHic\Helpers\hic_mark_reservation_processed_by_id($reservation_id); }
    function hic_acquire_reservation_lock($reservation_id, $timeout = 30) { return \FpHic\Helpers\hic_acquire_reservation_lock($reservation_id, $timeout); }
    function hic_release_reservation_lock($reservation_id) { return \FpHic\Helpers\hic_release_reservation_lock($reservation_id); }
    function hic_is_reservation_already_processed($reservation_id) { return \FpHic\Helpers\hic_is_reservation_already_processed($reservation_id); }
    function hic_get_processing_statistics() { return \FpHic\Helpers\hic_get_processing_statistics(); }
    function hic_safe_wp_next_scheduled($hook) { return \FpHic\Helpers\hic_safe_wp_next_scheduled($hook); }
    function hic_safe_wp_schedule_event($timestamp, $recurrence, $hook, $args = array()) { return \FpHic\Helpers\hic_safe_wp_schedule_event($timestamp, $recurrence, $hook, $args); }
    function hic_safe_wp_clear_scheduled_hook($hook, $args = array()) { return \FpHic\Helpers\hic_safe_wp_clear_scheduled_hook($hook, $args); }
}
