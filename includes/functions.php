<?php declare(strict_types=1);

namespace FpHic\Helpers {

    if (!defined('ABSPATH')) {
        exit;
    }

    require_once __DIR__ . '/helpers/options.php';
    require_once __DIR__ . '/helpers/strings.php';
    require_once __DIR__ . '/helpers/api.php';
    require_once __DIR__ . '/helpers/booking.php';

    /**
     * Trigger a deprecation notice for legacy global shims.
     */
    function hic_trigger_deprecated_shim(string $shim, string $replacement): void
    {
        static $triggered = [];

        if (isset($triggered[$shim])) {
            return;
        }

        $triggered[$shim] = true;

        $message = sprintf('[HIC][Deprecated] %s() is deprecated. Use %s().', $shim, $replacement);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log($message);
        }

        if (function_exists(__NAMESPACE__ . '\\hic_log')) {
            try {
                hic_log($message, defined('HIC_LOG_LEVEL_DEBUG') ? HIC_LOG_LEVEL_DEBUG : 'debug');
            } catch (\Throwable $exception) {
                // Silently ignore logging failures for shim notices.
            }
        }
    }

    /**
     * Invoke a namespaced helper while recording a shim deprecation.
     *
     * @param string   $shim        Global shim name.
     * @param string   $replacement Fully-qualified helper name.
     * @param string[] $args        Arguments passed to the shim.
     *
     * @return mixed
     */
    function hic_invoke_deprecated_shim(string $shim, string $replacement, array $args = [])
    {
        hic_trigger_deprecated_shim($shim, $replacement);

        return call_user_func_array($replacement, $args);
    }
}

namespace {

    use function FpHic\Helpers\hic_invoke_deprecated_shim;

    if (!function_exists('rest_get_server')) {
        if (!defined('HIC_REST_API_FALLBACK')) {
            define('HIC_REST_API_FALLBACK', true);
        }

        \FpHic\Helpers\hic_include_rest_route_fallback_files();

        function rest_get_server()
        {
            static $server = null;

            if ($server === null) {
                $server = new class {
                    public function get_routes(): array
                    {
                        return \FpHic\Helpers\hic_get_registered_rest_routes();
                    }

                    public function reset_registrations(): void
                    {
                        \FpHic\Helpers\hic_reset_registered_rest_routes();
                    }
                };
            }

            return $server;
        }
    }

/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_require_cap()
 */
function hic_require_cap($capability) {
    hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_require_cap', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_sanitize_identifier()
 */
function hic_sanitize_identifier($identifier, $type = 'identifier') {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_sanitize_identifier', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_http_request()
 */
function hic_http_request($url, $args = array(), $suppress_failed_storage = false) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_http_request', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_option()
 */
function hic_get_option($key, $default = '') {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_option', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_clear_option_cache()
 */
function hic_clear_option_cache($key = null) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_clear_option_cache', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_measurement_id()
 */
function hic_get_measurement_id() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_measurement_id', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_api_secret()
 */
function hic_get_api_secret() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_api_secret', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_brevo_api_key()
 */
function hic_get_brevo_api_key() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_brevo_api_key', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_brevo_list_it()
 */
function hic_get_brevo_list_it() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_brevo_list_it', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_brevo_list_en()
 */
function hic_get_brevo_list_en() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_brevo_list_en', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_brevo_list_default()
 */
function hic_get_brevo_list_default() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_brevo_list_default', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_brevo_optin_default()
 */
function hic_get_brevo_optin_default() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_brevo_optin_default', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_is_brevo_enabled()
 */
function hic_is_brevo_enabled() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_is_brevo_enabled', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_is_debug_verbose()
 */
function hic_is_debug_verbose() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_is_debug_verbose', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_health_token()
 */
function hic_get_health_token() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_health_token', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_updates_enrich_contacts()
 */
function hic_updates_enrich_contacts() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_updates_enrich_contacts', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_brevo_list_alias()
 */
function hic_get_brevo_list_alias() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_brevo_list_alias', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_brevo_double_optin_on_enrich()
 */
function hic_brevo_double_optin_on_enrich() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_brevo_double_optin_on_enrich', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_realtime_brevo_sync_enabled()
 */
function hic_realtime_brevo_sync_enabled() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_realtime_brevo_sync_enabled', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_brevo_event_endpoint()
 */
function hic_get_brevo_event_endpoint() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_brevo_event_endpoint', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_reliable_polling_enabled()
 */
function hic_reliable_polling_enabled() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_reliable_polling_enabled', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_admin_email()
 */
function hic_get_admin_email() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_admin_email', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_log_file()
 */
function hic_get_log_file() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_log_file', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_is_gtm_enabled()
 */
function hic_is_gtm_enabled() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_is_gtm_enabled', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_gtm_container_id()
 */
function hic_get_gtm_container_id() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_gtm_container_id', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_tracking_mode()
 */
function hic_get_tracking_mode() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_tracking_mode', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_fb_pixel_id()
 */
function hic_get_fb_pixel_id() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_fb_pixel_id', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_fb_access_token()
 */
function hic_get_fb_access_token() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_fb_access_token', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_connection_type()
 */
function hic_get_connection_type() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_connection_type', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_normalize_connection_type()
 */
function hic_normalize_connection_type($type = null) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_normalize_connection_type', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_connection_uses_api()
 */
function hic_connection_uses_api($type = null) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_connection_uses_api', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_webhook_token()
 */
function hic_get_webhook_token() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_webhook_token', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_webhook_secret()
 */
function hic_get_webhook_secret() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_webhook_secret', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_api_url()
 */
function hic_get_api_url() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_api_url', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_api_key()
 */
function hic_get_api_key() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_api_key', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_api_email()
 */
function hic_get_api_email() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_api_email', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_api_password()
 */
function hic_get_api_password() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_api_password', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_property_id()
 */
function hic_get_property_id() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_property_id', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_has_basic_auth_credentials()
 */
function hic_has_basic_auth_credentials() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_has_basic_auth_credentials', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_currency()
 */
function hic_get_currency() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_currency', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_use_net_value()
 */
function hic_use_net_value() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_use_net_value', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_process_invalid()
 */
function hic_process_invalid() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_process_invalid', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_allow_status_updates()
 */
function hic_allow_status_updates() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_allow_status_updates', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_refund_tracking_enabled()
 */
function hic_refund_tracking_enabled() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_refund_tracking_enabled', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_polling_range_extension_days()
 */
function hic_get_polling_range_extension_days() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_polling_range_extension_days', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_polling_interval()
 */
function hic_get_polling_interval() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_polling_interval', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_acquire_polling_lock()
 */
function hic_acquire_polling_lock($timeout = 300) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_acquire_polling_lock', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_release_polling_lock()
 */
function hic_release_polling_lock() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_release_polling_lock', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_should_schedule_retry_event()
 */
function hic_should_schedule_retry_event() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_should_schedule_retry_event', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_tracking_ids_by_sid()
 */
function hic_get_tracking_ids_by_sid($sid) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_tracking_ids_by_sid', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_utm_params_by_sid()
 */
function hic_get_utm_params_by_sid($sid) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_utm_params_by_sid', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_normalize_price()
 */
function hic_normalize_price($value) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_normalize_price', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_is_valid_email()
 */
function hic_is_valid_email($email) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_is_valid_email', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_is_ota_alias_email()
 */
function hic_is_ota_alias_email($e) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_is_ota_alias_email', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_detect_phone_language()
 */
function hic_detect_phone_language($phone) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_detect_phone_language', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_normalize_phone_for_hash()
 */
function hic_normalize_phone_for_hash($phone, $context = []) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_normalize_phone_for_hash', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_hash_normalized_phone()
 */
function hic_hash_normalized_phone($phone, $context = []) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_hash_normalized_phone', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_booking_uid()
 */
function hic_booking_uid($reservation) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_booking_uid', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_mask_sensitive_data()
 */
function hic_mask_sensitive_data($message) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_mask_sensitive_data', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_default_log_message_filter()
 */
function hic_default_log_message_filter($message, $level) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_default_log_message_filter', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_log()
 */
function hic_log($msg, $level = HIC_LOG_LEVEL_INFO, $context = []) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_log', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_bucket()
 */
function hic_get_bucket($gclid, $fbclid, $gbraid = null, $wbraid = null) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_bucket', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_send_admin_email()
 */
function hic_send_admin_email($data, $gclid, $fbclid, $sid) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_send_admin_email', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_test_email_configuration()
 */
function hic_test_email_configuration($recipient_email = null) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_test_email_configuration', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_diagnose_email_issues()
 */
function hic_diagnose_email_issues() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_diagnose_email_issues', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_mark_email_enriched()
 */
function hic_mark_email_enriched($reservation_id, $real_email) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_mark_email_enriched', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_reservation_email()
 */
function hic_get_reservation_email($reservation_id) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_reservation_email', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_normalize_reservation_id()
 */
function hic_normalize_reservation_id($value) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_normalize_reservation_id', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_collect_reservation_ids()
 */
function hic_collect_reservation_ids(array $reservation) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_collect_reservation_ids', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_find_processed_reservation_alias()
 */
function hic_find_processed_reservation_alias(array $reservation) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_find_processed_reservation_alias', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_create_integration_result()
 */
function hic_create_integration_result($overrides = []) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_create_integration_result', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_append_integration_result()
 */
function hic_append_integration_result(array &$result, $integration, $status, $note = '') {
    hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_append_integration_result', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_finalize_integration_result()
 */
function hic_finalize_integration_result($result, $tracking_skipped = false) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_finalize_integration_result', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_queue_integration_retry()
 */
function hic_queue_integration_retry($reservation_uid, $failed_details, $context = []) {
    hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_queue_integration_retry', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_extract_reservation_id()
 */
function hic_extract_reservation_id($data) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_extract_reservation_id', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_mark_reservation_processed_by_id()
 */
function hic_mark_reservation_processed_by_id($reservation_id) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_mark_reservation_processed_by_id', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_acquire_reservation_lock()
 */
function hic_acquire_reservation_lock($reservation_id, $timeout = 30) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_acquire_reservation_lock', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_release_reservation_lock()
 */
function hic_release_reservation_lock($reservation_id) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_release_reservation_lock', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_is_reservation_already_processed()
 */
function hic_is_reservation_already_processed($reservation_id) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_is_reservation_already_processed', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_get_processing_statistics()
 */
function hic_get_processing_statistics() {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_get_processing_statistics', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_safe_wp_next_scheduled()
 */
function hic_safe_wp_next_scheduled($hook) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_safe_wp_next_scheduled', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_safe_wp_schedule_event()
 */
function hic_safe_wp_schedule_event($timestamp, $recurrence, $hook, $args = array()) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_safe_wp_schedule_event', func_get_args());
}
/**
 * @deprecated 2.x Use \FpHic\Helpers\hic_safe_wp_clear_scheduled_hook()
 */
function hic_safe_wp_clear_scheduled_hook($hook, $args = array()) {
    return hic_invoke_deprecated_shim(__FUNCTION__, '\FpHic\Helpers\hic_safe_wp_clear_scheduled_hook', func_get_args());
}
}

