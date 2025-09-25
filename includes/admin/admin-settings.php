<?php declare(strict_types=1);
/**
 * Admin Settings Page for HIC Plugin
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../rate-limiter.php';

use FpHic\HIC_Rate_Limiter;
use function FpHic\Helpers\hic_log;

/* ============ Admin Settings Page ============ */
add_action('admin_menu', 'hic_add_admin_menu', 40);
add_action('admin_init', 'hic_settings_init');
add_action('admin_enqueue_scripts', 'hic_admin_enqueue_scripts');
add_filter('wp_headers', 'hic_filter_admin_security_headers', 10, 2);

if (!function_exists('hic_admin_hook_matches_page')) {
    /**
     * Determine whether the current admin screen matches the provided submenu slug.
     *
     * @param mixed         $hook          The hook string received via admin_enqueue_scripts.
     * @param string        $page_slug     The expected submenu slug.
     * @param array<string> $hook_prefixes Expected hook prefixes (network/user variations).
     */
    function hic_admin_hook_matches_page($hook, string $page_slug, array $hook_prefixes): bool {
        $page_slug = strtolower(trim($page_slug));
        $page_slug = preg_replace('/[^a-z0-9_-]/', '', $page_slug) ?? '';

        if ($page_slug === '') {
            return false;
        }

        $requested_page = $_GET['page'] ?? '';
        if (is_string($requested_page) && $requested_page !== '') {
            if (function_exists('wp_unslash')) {
                $requested_page = wp_unslash($requested_page);
            }

            $requested_page = strtolower(trim($requested_page));
            $requested_page = preg_replace('/[^a-z0-9_-]/', '', $requested_page) ?? '';

            if ($requested_page === $page_slug) {
                return true;
            }
        }

        if (!is_string($hook) || $hook === '') {
            return false;
        }

        foreach ($hook_prefixes as $prefix) {
            if (strpos($hook, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }
}

// Add AJAX handler for API connection test
add_action('wp_ajax_hic_test_api_connection', 'hic_ajax_test_api_connection');

// Add AJAX handler for email configuration test
add_action('wp_ajax_hic_test_email_ajax', 'hic_ajax_test_email');

// Add AJAX handler for health token generation
add_action('wp_ajax_hic_generate_health_token', 'hic_ajax_generate_health_token');

/**
 * Build a unique rate limit key for the current administrator request.
 */
function hic_get_ajax_rate_limit_key(string $action): string {
    $normalized_action = strtolower(trim($action));
    $normalized_action = preg_replace('/[^a-z0-9_-]/', '', $normalized_action ?? '') ?? '';

    if ($normalized_action === '') {
        $normalized_action = 'generic';
    }

    $parts = [$normalized_action];

    if (function_exists('get_current_user_id')) {
        $user_id = (int) get_current_user_id();
        if ($user_id > 0) {
            $parts[] = 'user-' . $user_id;
        }
    }

    if (count($parts) === 1) {
        $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
        if (is_string($remote_addr) && $remote_addr !== '') {
            $sanitized_ip = preg_replace('/[^0-9a-fA-F:.]/', '', $remote_addr) ?? '';
            if ($sanitized_ip !== '') {
                $parts[] = 'ip-' . $sanitized_ip;
            }
        }
    }

    if (count($parts) === 1) {
        $parts[] = 'anonymous';
    }

    return implode(':', $parts);
}

/**
 * Enforce an AJAX rate limit and return whether the request can proceed.
 */
function hic_enforce_ajax_rate_limit(string $action, int $max_attempts, int $window): bool {
    if ($max_attempts <= 0 || $window <= 0) {
        return true;
    }

    $key = hic_get_ajax_rate_limit_key($action);
    if ($key === '') {
        return true;
    }

    $result = HIC_Rate_Limiter::attempt($key, $max_attempts, $window);

    if ($result['allowed']) {
        if (!headers_sent()) {
            header('X-RateLimit-Remaining: ' . max(0, (int) $result['remaining']));
        }
        return true;
    }

    $retry_after = max(1, (int) $result['retry_after']);

    if (!headers_sent()) {
        header('Retry-After: ' . $retry_after);
        header('X-RateLimit-Remaining: 0');
    }

    if (function_exists('status_header')) {
        status_header(429);
    }

    hic_log(
        sprintf('Rate limit triggered for %s (key: %s)', $action, $key),
        HIC_LOG_LEVEL_WARNING,
        [
            'retry_after' => $retry_after,
            'max_attempts' => $max_attempts,
            'window' => $window,
        ]
    );

    wp_send_json_error(
        [
            'message' => sprintf(
                __('Hai effettuato troppe richieste. Riprova tra %d secondi.', 'hotel-in-cloud'),
                $retry_after
            ),
            'retry_after' => $retry_after,
            'code' => HIC_ERROR_RATE_LIMITED,
        ],
        429
    );

    return false;
}

function hic_ajax_test_email() {
    // Verify nonce for security
    if (!check_ajax_referer('hic_test_email', 'nonce', false)) {
        wp_send_json_error(array(
            'message' => 'Nonce di sicurezza non valido.'
        ));
    }
    
    // Check user permissions
    if (!current_user_can('hic_manage')) {
        wp_send_json_error(array(
            'message' => 'Permessi insufficienti.'
        ));
    }

    if (!hic_enforce_ajax_rate_limit('test_email_ajax', 5, 300)) {
        return;
    }
    
    // Get email from request
    $test_email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
    
    if (empty($test_email) || !is_email($test_email)) {
        wp_send_json_error(array(
            'message' => 'Indirizzo email non valido.'
        ));
    }
    
    // Run email configuration test
    $test_result = \FpHic\Helpers\hic_test_email_configuration($test_email);

    $success = !empty($test_result['success']);
    unset($test_result['success']);

    if ($success) {
        wp_send_json_success($test_result);
    } else {
        wp_send_json_error($test_result);
    }
}

function hic_ajax_test_api_connection() {
    // Verify nonce for security
    if (!check_ajax_referer('hic_test_api_nonce', 'nonce', false)) {
        wp_send_json_error(array(
            'message' => 'Nonce di sicurezza non valido.'
        ));
    }
    
    // Check user permissions
    if (!current_user_can('hic_manage')) {
        wp_send_json_error(array(
            'message' => 'Permessi insufficienti.'
        ));
    }

    if (!hic_enforce_ajax_rate_limit('test_api_connection', 5, 300)) {
        return;
    }
    
    // Get credentials from AJAX request or settings
    $prop_id = sanitize_text_field( wp_unslash( $_POST['prop_id'] ?? '' ) );
    $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
    // Allow special characters in password; only unslash without sanitization.
    $password = wp_unslash( $_POST['password'] ?? '' );
    if ( strlen( $password ) === 0 ) {
        $password = '';
    }
    
    // Test the API connection
    $result = \FpHic\hic_test_api_connection($prop_id, $email, $password);

    $success = !empty($result['success']);
    unset($result['success']);

    if ($success) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

function hic_ajax_generate_health_token() {
    if (!check_ajax_referer('hic_generate_health_token', 'nonce', false)) {
        wp_send_json_error(array(
            'message' => 'Nonce di sicurezza non valido.'
        ));
    }

    if (!current_user_can('hic_manage')) {
        wp_send_json_error(array(
            'message' => 'Permessi insufficienti.'
        ));
    }

    if (!hic_enforce_ajax_rate_limit('generate_health_token', 3, 900)) {
        return;
    }

    $token = hic_generate_health_token_value();

    if ($token === '') {
        wp_send_json_error(array(
            'message' => 'Impossibile generare un token sicuro.'
        ));
    }

    update_option('hic_health_token', $token);
    \FpHic\Helpers\hic_clear_option_cache('health_token');

    wp_send_json_success(array(
        'token' => $token,
        'message' => 'Token rigenerato con successo. Ricorda di salvare le impostazioni.'
    ));
}

function hic_add_admin_menu() {
    add_submenu_page(
        'hic-monitoring',
        'HIC Monitoring Settings',
        'Impostazioni',
        'hic_manage',
        'hic-monitoring-settings',
        'hic_options_page'
    );

    // Add diagnostics submenu
    add_submenu_page(
        'hic-monitoring',
        'Diagnostics',
        'Diagnostics',
        'hic_manage',
        'hic-diagnostics',
        'hic_diagnostics_page'
    );
}

function hic_settings_init() {
    register_setting('hic_settings', 'hic_measurement_id', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('hic_settings', 'hic_api_secret', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('hic_settings', 'hic_brevo_enabled', array('sanitize_callback' => 'rest_sanitize_boolean'));
    register_setting('hic_settings', 'hic_brevo_api_key', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('hic_settings', 'hic_brevo_list_it', array('sanitize_callback' => 'absint'));
    register_setting('hic_settings', 'hic_brevo_list_en', array('sanitize_callback' => 'absint'));
    register_setting('hic_settings', 'hic_fb_pixel_id', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('hic_settings', 'hic_fb_access_token', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('hic_settings', 'hic_webhook_token', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('hic_settings', 'hic_webhook_secret', array('sanitize_callback' => 'hic_sanitize_webhook_secret'));
    register_setting('hic_settings', 'hic_health_token', array('sanitize_callback' => 'hic_sanitize_health_token'));
    register_setting('hic_settings', 'hic_admin_email', array(
        'sanitize_callback' => 'hic_validate_admin_email'
    ));
    register_setting(
        'hic_settings',
        'hic_log_file',
        array('sanitize_callback' => '\\FpHic\\Helpers\\hic_validate_log_path')
    );
    register_setting('hic_settings', 'hic_log_level', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('hic_settings', 'hic_connection_type', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('hic_settings', 'hic_api_url', array('sanitize_callback' => 'sanitize_text_field'));
    // New Basic Auth settings
    register_setting('hic_settings', 'hic_api_email', array('sanitize_callback' => 'sanitize_email'));
    register_setting('hic_settings', 'hic_api_password', array('sanitize_callback' => 'hic_preserve_password_field'));
    register_setting('hic_settings', 'hic_property_id', array('sanitize_callback' => 'absint'));
    register_setting('hic_settings', 'hic_polling_interval', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('hic_settings', 'hic_reliable_polling_enabled', array('sanitize_callback' => 'rest_sanitize_boolean'));

    // New HIC Extended Integration settings
    register_setting('hic_settings', 'hic_currency', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('hic_settings', 'hic_ga4_use_net_value', array('sanitize_callback' => 'rest_sanitize_boolean'));
    register_setting('hic_settings', 'hic_process_invalid', array('sanitize_callback' => 'rest_sanitize_boolean'));
    register_setting('hic_settings', 'hic_allow_status_updates', array('sanitize_callback' => 'rest_sanitize_boolean'));
    register_setting('hic_settings', 'hic_refund_tracking', array('sanitize_callback' => 'rest_sanitize_boolean'));
    register_setting('hic_settings', 'hic_brevo_list_default', array('sanitize_callback' => 'absint'));
    register_setting('hic_settings', 'hic_brevo_optin_default', array('sanitize_callback' => 'rest_sanitize_boolean'));
    register_setting('hic_settings', 'hic_debug_verbose', array('sanitize_callback' => 'rest_sanitize_boolean'));

    // New email enrichment settings
    register_setting('hic_settings', 'hic_updates_enrich_contacts', array('sanitize_callback' => 'rest_sanitize_boolean'));
    register_setting('hic_settings', 'hic_realtime_brevo_sync', array('sanitize_callback' => 'rest_sanitize_boolean'));
    register_setting('hic_settings', 'hic_brevo_list_alias', array('sanitize_callback' => 'absint'));
    register_setting('hic_settings', 'hic_brevo_double_optin_on_enrich', array('sanitize_callback' => 'rest_sanitize_boolean'));
    register_setting('hic_settings', 'hic_brevo_event_endpoint', array('sanitize_callback' => 'esc_url_raw'));

    // GTM Settings
    register_setting('hic_settings', 'hic_gtm_container_id', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('hic_settings', 'hic_tracking_mode', array('sanitize_callback' => 'sanitize_text_field'));
    register_setting('hic_settings', 'hic_gtm_enabled', array('sanitize_callback' => 'rest_sanitize_boolean'));

    add_settings_section('hic_main_section', 'Configurazione Principale', null, 'hic_settings');
    add_settings_section('hic_ga4_section', 'Google Analytics 4', null, 'hic_settings');
    add_settings_section('hic_gtm_section', 'Google Tag Manager', null, 'hic_settings');
    add_settings_section('hic_brevo_section', 'Brevo Settings', null, 'hic_settings');
    add_settings_section('hic_fb_section', 'Facebook Meta', null, 'hic_settings');
    add_settings_section('hic_hic_section', 'Hotel in Cloud', null, 'hic_settings');

    // Main settings
    add_settings_field('hic_admin_email', 'Email Amministratore', 'hic_admin_email_render', 'hic_settings', 'hic_main_section');
    add_settings_field('hic_log_file', 'File di Log', 'hic_log_file_render', 'hic_settings', 'hic_main_section');
    add_settings_field('hic_log_level', 'Log Level', 'hic_log_level_render', 'hic_settings', 'hic_main_section');
    
    // GA4 settings
    add_settings_field('hic_measurement_id', 'Measurement ID', 'hic_measurement_id_render', 'hic_settings', 'hic_ga4_section');
    add_settings_field('hic_api_secret', 'API Secret', 'hic_api_secret_render', 'hic_settings', 'hic_ga4_section');
    
    // GTM settings
    add_settings_field('hic_gtm_enabled', 'Abilita GTM', 'hic_gtm_enabled_render', 'hic_settings', 'hic_gtm_section');
    add_settings_field('hic_gtm_container_id', 'GTM Container ID', 'hic_gtm_container_id_render', 'hic_settings', 'hic_gtm_section');
    add_settings_field('hic_tracking_mode', 'Modalità Tracciamento', 'hic_tracking_mode_render', 'hic_settings', 'hic_gtm_section');
    
    // Brevo settings
    add_settings_field('hic_brevo_enabled', 'Abilita Brevo', 'hic_brevo_enabled_render', 'hic_settings', 'hic_brevo_section');
    add_settings_field('hic_brevo_api_key', 'API Key', 'hic_brevo_api_key_render', 'hic_settings', 'hic_brevo_section');
    add_settings_field('hic_brevo_list_it', 'Lista Italiana', 'hic_brevo_list_it_render', 'hic_settings', 'hic_brevo_section');
    add_settings_field('hic_brevo_list_en', 'Lista Inglese', 'hic_brevo_list_en_render', 'hic_settings', 'hic_brevo_section');
    
    // Facebook settings
    add_settings_field('hic_fb_pixel_id', 'Pixel ID', 'hic_fb_pixel_id_render', 'hic_settings', 'hic_fb_section');
    add_settings_field('hic_fb_access_token', 'Access Token', 'hic_fb_access_token_render', 'hic_settings', 'hic_fb_section');
    
    // Hotel in Cloud settings
    add_settings_field('hic_connection_type', 'Tipo Connessione', 'hic_connection_type_render', 'hic_settings', 'hic_hic_section');
    add_settings_field('hic_webhook_token', 'Webhook Token', 'hic_webhook_token_render', 'hic_settings', 'hic_hic_section');
    add_settings_field('hic_webhook_secret', 'Webhook Secret', 'hic_webhook_secret_render', 'hic_settings', 'hic_hic_section');
    add_settings_field('hic_health_token', 'Health Check Token', 'hic_health_token_render', 'hic_settings', 'hic_hic_section');
    add_settings_field('hic_api_url', 'API URL', 'hic_api_url_render', 'hic_settings', 'hic_hic_section');
    // Basic Auth settings
    add_settings_field('hic_api_email', 'API Email', 'hic_api_email_render', 'hic_settings', 'hic_hic_section');
    add_settings_field('hic_api_password', 'API Password', 'hic_api_password_render', 'hic_settings', 'hic_hic_section');
    add_settings_field('hic_property_id', 'ID Struttura (propId)', 'hic_property_id_render', 'hic_settings', 'hic_hic_section');
    add_settings_field('hic_polling_interval', 'Intervallo Polling', 'hic_polling_interval_render', 'hic_settings', 'hic_hic_section');
    add_settings_field('hic_reliable_polling_enabled', 'Polling Affidabile', 'hic_reliable_polling_enabled_render', 'hic_settings', 'hic_hic_section');
    
    // Extended HIC Integration settings
    add_settings_field('hic_currency', 'Valuta (Currency)', 'hic_currency_render', 'hic_settings', 'hic_hic_section');
    add_settings_field('hic_ga4_use_net_value', 'Usa valore netto per GA4/Pixel', 'hic_ga4_use_net_value_render', 'hic_settings', 'hic_hic_section');
    add_settings_field('hic_process_invalid', 'Processa prenotazioni non valide', 'hic_process_invalid_render', 'hic_settings', 'hic_hic_section');
    add_settings_field('hic_allow_status_updates', 'Gestisci aggiornamenti stato', 'hic_allow_status_updates_render', 'hic_settings', 'hic_hic_section');
    add_settings_field('hic_refund_tracking', 'Traccia rimborsi', 'hic_refund_tracking_render', 'hic_settings', 'hic_hic_section');
    add_settings_field('hic_brevo_list_default', 'Lista Brevo Default', 'hic_brevo_list_default_render', 'hic_settings', 'hic_brevo_section');
    add_settings_field('hic_brevo_optin_default', 'Opt-in marketing di default', 'hic_brevo_optin_default_render', 'hic_settings', 'hic_brevo_section');
    
    // Email enrichment settings
    add_settings_field('hic_updates_enrich_contacts', 'Aggiorna contatti da updates', 'hic_updates_enrich_contacts_render', 'hic_settings', 'hic_brevo_section');
    add_settings_field('hic_realtime_brevo_sync', 'Sync real-time a Brevo', 'hic_realtime_brevo_sync_render', 'hic_settings', 'hic_brevo_section');
    add_settings_field('hic_brevo_list_alias', 'Lista alias Brevo', 'hic_brevo_list_alias_render', 'hic_settings', 'hic_brevo_section');
    add_settings_field('hic_brevo_double_optin_on_enrich', 'Double opt-in quando arriva email reale', 'hic_brevo_double_optin_on_enrich_render', 'hic_settings', 'hic_brevo_section');
    add_settings_field('hic_brevo_event_endpoint', 'Endpoint API Eventi Brevo', 'hic_brevo_event_endpoint_render', 'hic_settings', 'hic_brevo_section');
    
    add_settings_field('hic_debug_verbose', 'Log debug verboso', 'hic_debug_verbose_render', 'hic_settings', 'hic_main_section');
}

/**
 * Enqueue admin scripts for HIC plugin pages
 */
function hic_admin_enqueue_scripts($hook) {
    $settings_hooks = array(
        'hic-monitoring_page_hic-monitoring-settings',
        'hic-monitoring-network_page_hic-monitoring-settings',
        'hic-monitoring-user_page_hic-monitoring-settings',
        'hotel-in-cloud_page_hic-monitoring-settings',
    );

    $diagnostics_hooks = array(
        'hic-monitoring_page_hic-diagnostics',
        'hic-monitoring-network_page_hic-diagnostics',
        'hic-monitoring-user_page_hic-diagnostics',
        'hotel-in-cloud_page_hic-diagnostics',
    );

    $is_settings_page = hic_admin_hook_matches_page($hook, 'hic-monitoring-settings', $settings_hooks);
    $is_diagnostics_page = hic_admin_hook_matches_page($hook, 'hic-diagnostics', $diagnostics_hooks);

    // Only load on our plugin pages, including multisite/network variations.
    if ($is_settings_page || $is_diagnostics_page) {
        // Ensure jQuery is loaded
        wp_enqueue_script('jquery');
    }

    wp_register_style(
        'hic-admin-base',
        plugin_dir_url(__FILE__) . '../../assets/css/hic-admin.css',
        array(),
        HIC_PLUGIN_VERSION
    );

    if ($is_settings_page) {
        wp_enqueue_style('hic-admin-base');
        wp_enqueue_style(
            'hic-admin-settings',
            plugin_dir_url(__FILE__) . '../../assets/css/admin-settings.css',
            array('hic-admin-base'),
            HIC_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'hic-admin-settings',
            plugin_dir_url(__FILE__) . '../../assets/js/admin-settings.js',
            array('jquery'),
            HIC_PLUGIN_VERSION,
            true
        );
        wp_set_script_translations(
            'hic-admin-settings',
            'hotel-in-cloud',
            plugin_dir_path(__FILE__) . '../../languages'
        );
        wp_localize_script('hic-admin-settings', 'hicAdminSettings', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'api_nonce' => wp_create_nonce('hic_test_api_nonce'),
            'email_nonce' => wp_create_nonce('hic_test_email'),
            'health_nonce' => wp_create_nonce('hic_generate_health_token'),
            'i18n' => array(
                'bookings_found_suffix' => __('prenotazioni trovate negli ultimi 7 giorni', 'hotel-in-cloud'),
                'api_network_error' => __('Errore di comunicazione:', 'hotel-in-cloud'),
                'email_missing' => __('Inserisci un indirizzo email per il test.', 'hotel-in-cloud'),
                'email_sending' => __('Invio email di test in corso...', 'hotel-in-cloud'),
                'token_generating' => __('Generazione token in corso...', 'hotel-in-cloud'),
            ),
        ));
    }

    if ($is_diagnostics_page) {
        wp_enqueue_style('hic-admin-base');
        wp_enqueue_style(
            'hic-diagnostics',
            plugin_dir_url(__FILE__) . '../../assets/css/diagnostics.css',
            array('hic-admin-base'),
            HIC_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'hic-diagnostics',
            plugin_dir_url(__FILE__) . '../../assets/js/diagnostics.js',
            array('jquery'),
            HIC_PLUGIN_VERSION,
            true
        );
        wp_set_script_translations(
            'hic-diagnostics',
            'hotel-in-cloud',
            plugin_dir_path(__FILE__) . '../../languages'
        );
        wp_localize_script('hic-diagnostics', 'hicDiagnostics', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'diagnostics_nonce' => wp_create_nonce('hic_diagnostics_nonce'),
            'admin_nonce' => wp_create_nonce('hic_admin_action'),
            'monitor_nonce' => wp_create_nonce('hic_monitor_nonce'),
            'polling_metrics_nonce' => wp_create_nonce('hic_polling_metrics'),
            'optimize_db_nonce' => wp_create_nonce('hic_optimize_db'),
            'management_nonce' => wp_create_nonce('hic_management_nonce'),
            'is_api_connection' => (\FpHic\Helpers\hic_connection_uses_api()),
            'has_basic_auth' => \FpHic\Helpers\hic_has_basic_auth_credentials(),
            'has_property_id' => (bool) \FpHic\Helpers\hic_get_property_id(),
            'can_view_logs' => current_user_can('hic_view_logs'),
            'log_refresh_interval' => apply_filters('hic_live_log_refresh_interval', 10000),
        ));
    }
}

function hic_render_settings_sections(array $section_ids): void {
    global $wp_settings_sections, $wp_settings_fields;

    $page = 'hic_settings';

    foreach ($section_ids as $section_id) {
        if (!isset($wp_settings_sections[$page][$section_id])) {
            continue;
        }

        $section = $wp_settings_sections[$page][$section_id];

        echo '<div class="hic-settings-section" id="' . esc_attr($section_id) . '">';

        if (!empty($section['title'])) {
            echo '<h2 class="hic-settings-section__title">' . esc_html($section['title']) . '</h2>';
        }

        if (!empty($section['callback'])) {
            call_user_func($section['callback'], $section);
        }

        if (!empty($wp_settings_fields[$page][$section_id])) {
            echo '<div class="hic-field-grid">';

            foreach ($wp_settings_fields[$page][$section_id] as $field) {
                echo '<div class="hic-field-row" id="row-' . esc_attr($field['id']) . '">';

                if (!empty($field['title'])) {
                    echo '<label class="hic-field-label" for="' . esc_attr($field['id']) . '">' . esc_html($field['title']) . '</label>';
                } else {
                    echo '<div class="hic-field-label"></div>';
                }

                echo '<div class="hic-field-control">';
                call_user_func($field['callback'], $field['args']);
                echo '</div>';
                echo '</div>';
            }

            echo '</div>';
        }

        echo '</div>';
    }
}

function hic_options_page() {
    $tabs = array(
        'general' => array(
            'label' => __('Generale', 'hotel-in-cloud'),
            'description' => __('Configurazioni principali, log e notifiche amministrative.', 'hotel-in-cloud'),
            'sections' => array('hic_main_section'),
        ),
        'tracking' => array(
            'label' => __('Tracking & Analytics', 'hotel-in-cloud'),
            'description' => __('Impostazioni per Google Analytics 4, Google Tag Manager e Meta.', 'hotel-in-cloud'),
            'sections' => array('hic_ga4_section', 'hic_gtm_section', 'hic_fb_section'),
        ),
        'hotel' => array(
            'label' => __('Hotel in Cloud', 'hotel-in-cloud'),
            'description' => __('Connessione API, polling affidabile e sicurezza del sistema.', 'hotel-in-cloud'),
            'sections' => array('hic_hic_section'),
        ),
        'brevo' => array(
            'label' => __('Brevo', 'hotel-in-cloud'),
            'description' => __('Sincronizzazione contatti, liste e automazioni Brevo.', 'hotel-in-cloud'),
            'sections' => array('hic_brevo_section'),
        ),
    );

    $api_url_configured = (bool) \FpHic\Helpers\hic_get_api_url();
    $api_credentials_ready = $api_url_configured && hic_has_basic_auth_credentials();
    $connection_uses_api = \FpHic\Helpers\hic_connection_uses_api();

    $ga_measurement_id = (string) \FpHic\Helpers\hic_get_measurement_id();
    $ga_api_secret = (string) \FpHic\Helpers\hic_get_api_secret();
    $ga_ready = $ga_measurement_id !== '' && $ga_api_secret !== '';

    $gtm_container_id = (string) \FpHic\Helpers\hic_get_gtm_container_id();
    $gtm_ready = $gtm_container_id !== '';

    $brevo_enabled = \FpHic\Helpers\hic_is_brevo_enabled() && (string) \FpHic\Helpers\hic_get_brevo_api_key() !== '';
    $brevo_sync = $brevo_enabled && \FpHic\Helpers\hic_realtime_brevo_sync_enabled();

    $hero_overview = array(
        array(
            'label' => __('Connessione API', 'hotel-in-cloud'),
            'value' => $api_credentials_ready
                ? __('Credenziali verificate', 'hotel-in-cloud')
                : ($connection_uses_api ? __('Richiede completamento', 'hotel-in-cloud') : __('Solo Webhook', 'hotel-in-cloud')),
            'description' => $api_credentials_ready
                ? __('Polling API attivo e pronto per il monitoraggio.', 'hotel-in-cloud')
                : ($connection_uses_api
                    ? __('Aggiungi URL API, email e password per completare la connessione.', 'hotel-in-cloud')
                    : __('Abilita la modalità API per utilizzare il polling affidabile.', 'hotel-in-cloud')),
            'state' => $api_credentials_ready ? 'active' : ($connection_uses_api ? 'warning' : 'inactive'),
        ),
        array(
            'label' => __('Google Analytics 4', 'hotel-in-cloud'),
            'value' => $ga_ready
                ? sprintf(__('ID %s', 'hotel-in-cloud'), substr($ga_measurement_id, -4))
                : __('Non configurato', 'hotel-in-cloud'),
            'description' => $ga_ready
                ? __('Misurazione e API secret configurati.', 'hotel-in-cloud')
                : __('Aggiungi Measurement ID e API secret per inviare gli eventi.', 'hotel-in-cloud'),
            'state' => $ga_ready ? 'active' : (($ga_measurement_id !== '' || $ga_api_secret !== '') ? 'warning' : 'inactive'),
        ),
        array(
            'label' => __('Google Tag Manager', 'hotel-in-cloud'),
            'value' => $gtm_ready ? $gtm_container_id : __('In attesa', 'hotel-in-cloud'),
            'description' => $gtm_ready
                ? __('Container configurato per lanciare i tag marketing.', 'hotel-in-cloud')
                : __('Inserisci l\'ID container per pubblicare i tag.', 'hotel-in-cloud'),
            'state' => $gtm_ready ? 'active' : 'inactive',
        ),
        array(
            'label' => __('Brevo', 'hotel-in-cloud'),
            'value' => $brevo_enabled ? __('API attiva', 'hotel-in-cloud') : __('Non collegato', 'hotel-in-cloud'),
            'description' => $brevo_enabled
                ? ($brevo_sync
                    ? __('Sincronizzazione real-time e automazioni pronte.', 'hotel-in-cloud')
                    : __('Invio contatti disponibile, abilita la sync real-time se necessario.', 'hotel-in-cloud'))
                : __('Configura la chiave API Brevo per abilitare l\'invio.', 'hotel-in-cloud'),
            'state' => $brevo_enabled ? 'active' : 'inactive',
        ),
    );

    ?>
    <div class="wrap hic-admin-page hic-settings-page">
        <div class="hic-page-hero">
            <div class="hic-page-header">
                <div class="hic-page-header__content">
                    <h1 class="hic-page-header__title"><span>⚙️</span><?php esc_html_e('Monitoraggio Conversioni', 'hotel-in-cloud'); ?></h1>
                    <p class="hic-page-header__subtitle"><?php esc_html_e('Configura le integrazioni chiave del plugin mantenendo la stessa esperienza visiva della Dashboard Real-Time.', 'hotel-in-cloud'); ?></p>
                </div>
                <div class="hic-page-actions">
                    <a class="hic-button hic-button--ghost hic-button--inverted" href="<?php echo esc_url(admin_url('admin.php?page=hic-monitoring')); ?>">
                        <span class="dashicons dashicons-chart-area"></span>
                        <?php esc_html_e('Apri Dashboard Real-Time', 'hotel-in-cloud'); ?>
                    </a>
                    <a class="hic-button hic-button--ghost hic-button--inverted" href="<?php echo esc_url(admin_url('admin.php?page=hic-diagnostics')); ?>">
                        <span class="dashicons dashicons-admin-tools"></span>
                        <?php esc_html_e('Vai alla Diagnostica', 'hotel-in-cloud'); ?>
                    </a>
                </div>
            </div>

            <div class="hic-page-meta">
                <?php foreach ($hero_overview as $overview_item): ?>
                    <div class="hic-page-meta__item">
                        <span class="hic-page-meta__status is-<?php echo esc_attr($overview_item['state']); ?>"></span>
                        <div class="hic-page-meta__content">
                            <p class="hic-page-meta__label"><?php echo esc_html($overview_item['label']); ?></p>
                            <p class="hic-page-meta__value"><?php echo esc_html($overview_item['value']); ?></p>
                            <?php if (!empty($overview_item['description'])): ?>
                                <p class="hic-page-meta__description"><?php echo esc_html($overview_item['description']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php settings_errors(); ?>

        <form action="options.php" method="post" class="hic-card hic-settings-form" id="hic-settings-form">
            <?php settings_fields('hic_settings'); ?>

            <div class="hic-tablist" role="tablist" aria-label="<?php esc_attr_e('Categorie impostazioni', 'hotel-in-cloud'); ?>">
                <?php $first = true; ?>
                <?php foreach ($tabs as $tab_id => $tab_data): ?>
                    <button
                        type="button"
                        class="hic-tab<?php echo $first ? ' is-active' : ''; ?>"
                        role="tab"
                        data-tab="<?php echo esc_attr($tab_id); ?>"
                        id="tab-toggle-<?php echo esc_attr($tab_id); ?>"
                        aria-controls="tab-panel-<?php echo esc_attr($tab_id); ?>"
                        aria-selected="<?php echo $first ? 'true' : 'false'; ?>"
                    >
                        <?php echo esc_html($tab_data['label']); ?>
                    </button>
                    <?php $first = false; ?>
                <?php endforeach; ?>
            </div>

            <div class="hic-tab-panels">
                <?php $first_panel = true; ?>
                <?php foreach ($tabs as $tab_id => $tab_data): ?>
                    <section
                        id="tab-panel-<?php echo esc_attr($tab_id); ?>"
                        class="hic-tab-panel<?php echo $first_panel ? ' is-active' : ''; ?>"
                        role="tabpanel"
                        data-tab="<?php echo esc_attr($tab_id); ?>"
                        aria-labelledby="tab-toggle-<?php echo esc_attr($tab_id); ?>"
                        <?php echo $first_panel ? '' : 'hidden'; ?>
                    >
                        <?php if (!empty($tab_data['description'])): ?>
                            <p class="hic-section-hint"><?php echo esc_html($tab_data['description']); ?></p>
                        <?php endif; ?>
                        <?php hic_render_settings_sections($tab_data['sections']); ?>
                    </section>
                    <?php $first_panel = false; ?>
                <?php endforeach; ?>
            </div>

            <div class="hic-form-actions">
                <?php submit_button(__('Salva impostazioni', 'hotel-in-cloud'), 'primary hic-button hic-button--primary', 'submit', false); ?>
            </div>
        </form>

        <?php if (\FpHic\Helpers\hic_connection_uses_api()): ?>
            <div class="hic-card hic-api-test-card">
                <div class="hic-card__header">
                    <div>
                        <h2 class="hic-card__title"><?php esc_html_e('Test Connessione API', 'hotel-in-cloud'); ?></h2>
                        <p class="hic-card__subtitle"><?php esc_html_e('Verifica la connessione alle API Hotel in Cloud utilizzando le credenziali configurate.', 'hotel-in-cloud'); ?></p>
                    </div>
                    <div class="hic-page-actions">
                        <button type="button" id="hic-test-api-btn" class="button hic-button hic-button--secondary">
                            <span class="dashicons dashicons-admin-tools"></span>
                            <?php esc_html_e('Testa Connessione API', 'hotel-in-cloud'); ?>
                        </button>
                    </div>
                </div>
                <div class="hic-card__body">
                    <div id="hic-test-loading" class="hic-inline-loader" hidden>
                        <span class="spinner is-active"></span>
                        <span><?php esc_html_e('Test in corso... attendere...', 'hotel-in-cloud'); ?></span>
                    </div>
                    <div id="hic-test-result" class="hic-feedback" hidden></div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

function hic_sanitize_webhook_secret($value) {
    if (!is_string($value)) {
        return '';
    }

    $sanitized = trim($value);

    if ($sanitized === '') {
        return '';
    }

    // Limit to reasonable length to avoid accidental huge values.
    if (strlen($sanitized) > 255) {
        $sanitized = substr($sanitized, 0, 255);
    }

    // Allow hexadecimal and base64 characters plus separators used by common formats.
    return preg_replace('/[^A-Za-z0-9=+\/_-]/', '', $sanitized);
}

function hic_sanitize_health_token($value) {
    if (!is_string($value)) {
        return '';
    }

    $sanitized = trim($value);

    if ($sanitized === '') {
        return '';
    }

    $sanitized = preg_replace('/[^A-Za-z0-9_-]/', '', $sanitized);
    if ($sanitized === null) {
        $sanitized = '';
    }

    if ($sanitized === '') {
        return '';
    }

    if (strlen($sanitized) > 128) {
        $sanitized = substr($sanitized, 0, 128);
    }

    if (strlen($sanitized) < 24) {
        add_settings_error(
            'hic_health_token',
            'health_token_short',
            'Il token di health check deve contenere almeno 24 caratteri alfanumerici.',
            'error'
        );

        return \FpHic\Helpers\hic_get_health_token();
    }

    return $sanitized;
}

function hic_generate_health_token_value($length = 48) {
    $length = (int) $length;
    if ($length < 24) {
        $length = 24;
    }
    if ($length > 128) {
        $length = 128;
    }

    if (function_exists('wp_generate_password')) {
        $token = wp_generate_password($length, false, false);
        return hic_sanitize_health_token($token);
    }

    try {
        $bytes = random_bytes((int) ceil($length / 2));
        $token = substr(bin2hex($bytes), 0, $length);
    } catch (\Exception $e) {
        $token = substr(hash('sha256', uniqid('', true)), 0, $length);
    }

    return hic_sanitize_health_token($token);
}

// Render functions for settings fields
function hic_admin_email_render() {
    $current_email = \FpHic\Helpers\hic_get_admin_email();
    $custom_email = \FpHic\Helpers\hic_get_option('admin_email', '');
    $wp_admin_email = get_option('admin_email');

    ?>
    <div class="hic-input-group">
        <input type="email" name="hic_admin_email" id="hic_admin_email" class="regular-text" value="<?php echo esc_attr($current_email); ?>" />
        <button type="button" class="button hic-button hic-button--secondary" id="hic-test-email-btn">
            <span class="dashicons dashicons-email-alt"></span>
            <?php esc_html_e('Test Email', 'hotel-in-cloud'); ?>
        </button>
    </div>
    <div id="hic_email_test_result" class="hic-feedback" hidden></div>

    <p class="description"><?php esc_html_e('Email per ricevere notifiche di nuove prenotazioni.', 'hotel-in-cloud'); ?></p>
    <p class="hic-secondary-text">
        <?php
        if (empty($custom_email)) {
            printf(
                esc_html__("Attualmente viene utilizzata l'email amministratore di WordPress: %s", 'hotel-in-cloud'),
                esc_html($wp_admin_email)
            );
        } else {
            printf(
                esc_html__('Email personalizzata configurata: %s', 'hotel-in-cloud'),
                esc_html($custom_email)
            );
        }
        ?>
    </p>

    <div class="hic-callout hic-callout--muted hic-email-guidance">
        <h4>🔧 <?php esc_html_e('Risoluzione problemi email', 'hotel-in-cloud'); ?></h4>
        <details class="hic-details">
            <summary><?php esc_html_e('Se le email non arrivano, segui questi passi:', 'hotel-in-cloud'); ?></summary>
            <ol>
                <li><strong><?php esc_html_e('Testa la configurazione:', 'hotel-in-cloud'); ?></strong> <?php esc_html_e('Usa il pulsante "Test Email" per inviare un messaggio di prova.', 'hotel-in-cloud'); ?></li>
                <li><strong><?php esc_html_e('Controlla lo spam:', 'hotel-in-cloud'); ?></strong> <?php esc_html_e('Verifica la cartella spam o indesiderata della casella email.', 'hotel-in-cloud'); ?></li>
                <li><strong><?php esc_html_e("Verifica l'email:", 'hotel-in-cloud'); ?></strong> <?php esc_html_e("Assicurati che l'indirizzo configurato sia corretto e funzionante.", 'hotel-in-cloud'); ?></li>
                <li><strong><?php esc_html_e('Monitora i log:', 'hotel-in-cloud'); ?></strong> <?php esc_html_e('Apri la pagina Diagnostics per analizzare gli ultimi invii.', 'hotel-in-cloud'); ?></li>
                <li><strong><?php esc_html_e('Configura SMTP se necessario:', 'hotel-in-cloud'); ?></strong> <?php esc_html_e("Plugin come WP Mail SMTP o Easy WP SMTP migliorano l'affidabilità.", 'hotel-in-cloud'); ?></li>
                <li><strong><?php esc_html_e("Contatta l'hosting:", 'hotel-in-cloud'); ?></strong> <?php esc_html_e('Se tutto il resto è corretto, verifica eventuali blocchi lato server.', 'hotel-in-cloud'); ?></li>
            </ol>
            <p><strong><?php esc_html_e('Cause comuni dei problemi:', 'hotel-in-cloud'); ?></strong></p>
            <ul>
                <li><?php esc_html_e('Funzione mail() di PHP disabilitata.', 'hotel-in-cloud'); ?></li>
                <li><?php esc_html_e("Provider hosting che limita o blocca l'invio email.", 'hotel-in-cloud'); ?></li>
                <li><?php esc_html_e('Mancata configurazione SMTP o autenticazione insufficiente.', 'hotel-in-cloud'); ?></li>
                <li><?php esc_html_e('Indirizzi finiti in blacklist o marcati come spam.', 'hotel-in-cloud'); ?></li>
            </ul>
        </details>
    </div>
    <?php
}



function hic_log_file_render() {
    echo '<input type="text" name="hic_log_file" id="hic_log_file" value="' . esc_attr(hic_get_log_file()) . '" class="regular-text" />';
}

function hic_log_level_render() {
    $level = hic_get_option('log_level', HIC_LOG_LEVEL_INFO);
    echo '<select name="hic_log_level" id="hic_log_level" class="regular-text">';
    echo '<option value="' . esc_attr(HIC_LOG_LEVEL_ERROR) . '"' . selected($level, HIC_LOG_LEVEL_ERROR, false) . '>Error</option>';
    echo '<option value="' . esc_attr(HIC_LOG_LEVEL_WARNING) . '"' . selected($level, HIC_LOG_LEVEL_WARNING, false) . '>Warning</option>';
    echo '<option value="' . esc_attr(HIC_LOG_LEVEL_INFO) . '"' . selected($level, HIC_LOG_LEVEL_INFO, false) . '>Info</option>';
    echo '<option value="' . esc_attr(HIC_LOG_LEVEL_DEBUG) . '"' . selected($level, HIC_LOG_LEVEL_DEBUG, false) . '>Debug</option>';
    echo '</select>';
}

function hic_measurement_id_render() {
    echo '<input type="text" name="hic_measurement_id" id="hic_measurement_id" value="' . esc_attr(\FpHic\Helpers\hic_get_measurement_id()) . '" class="regular-text" />';
}

function hic_api_secret_render() {
    echo '<input type="text" name="hic_api_secret" id="hic_api_secret" value="' . esc_attr(\FpHic\Helpers\hic_get_api_secret()) . '" class="regular-text" />';
}

// GTM render functions
function hic_gtm_enabled_render() {
    $checked = \FpHic\Helpers\hic_is_gtm_enabled();
    echo '<label class="hic-toggle" for="hic_gtm_enabled">';
    echo '<input type="checkbox" name="hic_gtm_enabled" id="hic_gtm_enabled" value="1"' . checked($checked, true, false) . ' />';
    echo '<span>' . esc_html__('Abilita integrazione Google Tag Manager', 'hotel-in-cloud') . '</span>';
    echo '</label>';
    echo '<p class="description">' . esc_html__('Abilita il tracciamento tramite Google Tag Manager per gestire i tag dal container web.', 'hotel-in-cloud') . '</p>';
}

function hic_gtm_container_id_render() {
    echo '<input type="text" name="hic_gtm_container_id" id="hic_gtm_container_id" value="' . esc_attr(\FpHic\Helpers\hic_get_gtm_container_id()) . '" class="regular-text" placeholder="GTM-XXXXXXX" />';
    echo '<p class="description">' . esc_html__('ID del container GTM (formato: GTM-XXXXXXX). Disponibile in Google Tag Manager nella sezione dettagli container.', 'hotel-in-cloud') . '</p>';
}

function hic_tracking_mode_render() {
    $mode = \FpHic\Helpers\hic_get_tracking_mode();
    echo '<select name="hic_tracking_mode" id="hic_tracking_mode" class="regular-text">';
    echo '<option value="ga4_only"' . selected($mode, 'ga4_only', false) . '>' . esc_html__('Solo GA4 Measurement Protocol (server-side)', 'hotel-in-cloud') . '</option>';
    echo '<option value="gtm_only"' . selected($mode, 'gtm_only', false) . '>' . esc_html__('Solo Google Tag Manager (client-side)', 'hotel-in-cloud') . '</option>';
    echo '<option value="hybrid"' . selected($mode, 'hybrid', false) . '>' . esc_html__('Ibrido (GTM + GA4 di backup)', 'hotel-in-cloud') . '</option>';
    echo '</select>';
    echo '<p class="description">' . esc_html__('Scegli come distribuire gli eventi di conversione: server-side, client-side o con una strategia ibrida per massima affidabilità.', 'hotel-in-cloud') . '</p>';
}

function hic_brevo_enabled_render() {
    $checked = \FpHic\Helpers\hic_is_brevo_enabled();
    echo '<label class="hic-toggle" for="hic_brevo_enabled">';
    echo '<input type="checkbox" name="hic_brevo_enabled" id="hic_brevo_enabled" value="1"' . checked($checked, true, false) . ' />';
    echo '<span>' . esc_html__('Abilita integrazione Brevo', 'hotel-in-cloud') . '</span>';
    echo '</label>';
}

function hic_brevo_api_key_render() {
    echo '<input type="password" name="hic_brevo_api_key" id="hic_brevo_api_key" value="' . esc_attr(\FpHic\Helpers\hic_get_brevo_api_key()) . '" class="regular-text" />';
}

function hic_fb_pixel_id_render() {
    echo '<input type="text" name="hic_fb_pixel_id" id="hic_fb_pixel_id" value="' . esc_attr(\FpHic\Helpers\hic_get_fb_pixel_id()) . '" class="regular-text" />';
}

function hic_fb_access_token_render() {
    echo '<input type="password" name="hic_fb_access_token" id="hic_fb_access_token" value="' . esc_attr(\FpHic\Helpers\hic_get_fb_access_token()) . '" class="regular-text" />';
}

function hic_connection_type_render() {
    $type = \FpHic\Helpers\hic_get_connection_type();
    $normalized = \FpHic\Helpers\hic_normalize_connection_type($type);
    echo '<select name="hic_connection_type" id="hic_connection_type">';
    echo '<option value="webhook"' . selected($normalized, 'webhook', false) . '>' . esc_html__('Solo Webhook', 'hotel-in-cloud') . '</option>';
    echo '<option value="api"' . selected($normalized, 'api', false) . '>' . esc_html__('Solo API Polling', 'hotel-in-cloud') . '</option>';
    echo '<option value="hybrid"' . selected($normalized, 'hybrid', false) . '>' . esc_html__('Hybrid (Webhook + API)', 'hotel-in-cloud') . '</option>';
    echo '</select>';
    echo '<p class="description">' . esc_html__('La modalità ibrida combina webhook in tempo reale con polling API di backup per garantire continuità.', 'hotel-in-cloud') . '</p>';
}

function hic_webhook_token_render() {
    $token = \FpHic\Helpers\hic_get_webhook_token();
    $endpoint = '';

    if (!empty($token)) {
        $base_endpoint = rest_url('hic/v1/conversion');
        $endpoint = add_query_arg('token', rawurlencode($token), $base_endpoint);
    }

    echo '<input type="text" name="hic_webhook_token" id="hic_webhook_token" value="' . esc_attr($token) . '" class="regular-text" />';
    echo '<p class="description">' . esc_html__('Token condiviso con Hotel in Cloud per autorizzare le chiamate webhook.', 'hotel-in-cloud') . '</p>';

    if ($endpoint) {
        echo '<p class="description">' . sprintf(esc_html__('URL da fornire al supporto HIC: %s', 'hotel-in-cloud'), '<code>' . esc_html($endpoint) . '</code>') . '</p>';
    } else {
        echo '<p class="description">' . esc_html__("Dopo aver salvato comparirà l'URL completo del webhook da comunicare a Hotel in Cloud.", 'hotel-in-cloud') . '</p>';
    }
}

function hic_webhook_secret_render() {
    $secret = \FpHic\Helpers\hic_get_webhook_secret();

    echo '<input type="password" name="hic_webhook_secret" id="hic_webhook_secret" value="' . esc_attr($secret) . '" class="regular-text" autocomplete="off" />';
    $header_name = defined('HIC_WEBHOOK_SIGNATURE_HEADER') ? HIC_WEBHOOK_SIGNATURE_HEADER : 'X-HIC-Signature';

    echo '<p class="description">' . sprintf(esc_html__('Chiave condivisa opzionale usata per validare la firma HMAC del webhook (%s).', 'hotel-in-cloud'), '<code>' . esc_html($header_name) . '</code>') . '</p>';
    echo '<p class="description">' . esc_html__('Se Hotel in Cloud non può configurarla puoi lasciare il campo vuoto: le richieste saranno accettate senza verifica della firma.', 'hotel-in-cloud') . '</p>';
    echo '<p class="description">' . esc_html__('Quando possibile, inserisci lo stesso valore anche nel pannello HIC per autenticare ogni chiamata.', 'hotel-in-cloud') . '</p>';
    echo '<p class="description">' . esc_html__('Rigenera questo valore e aggiornalo sia qui che in HIC in caso di compromissione.', 'hotel-in-cloud') . '</p>';
}

function hic_health_token_render() {
    $token = \FpHic\Helpers\hic_get_health_token();

    echo '<div class="hic-health-token-tools">';
    echo '<input type="text" name="hic_health_token" id="hic_health_token" value="' . esc_attr($token) . '" class="regular-text" autocomplete="off" />';
    echo '<button type="button" class="button hic-button hic-button--secondary" id="hic-generate-health-token">' . esc_html__('Genera nuovo token', 'hotel-in-cloud') . '</button>';
    echo '</div>';
    echo '<p class="description">' . esc_html__("Il token protegge l'endpoint pubblico di health check. Condividilo solo con i sistemi di monitoraggio fidati.", 'hotel-in-cloud') . '</p>';
    echo '<p id="hic-health-token-status" class="hic-feedback" hidden></p>';
}

function hic_api_url_render() {
    echo '<input type="url" name="hic_api_url" id="hic_api_url" value="' . esc_url(\FpHic\Helpers\hic_get_api_url()) . '" class="regular-text" />';
    echo '<p class="description">' . esc_html__('URL delle API Hotel in Cloud (necessario solo per modalità API o Hybrid).', 'hotel-in-cloud') . '</p>';
}

// Basic Auth render functions
function hic_api_email_render() {
    $value = \FpHic\Helpers\hic_get_api_email();
    $is_constant = defined('HIC_API_EMAIL') && !empty(HIC_API_EMAIL);
    
    if ($is_constant) {
        echo '<input type="email" id="hic_api_email" value="' . esc_attr($value) . '" class="regular-text" disabled />';
        echo '<p class="description"><strong>' . esc_html__('Configurato tramite costante PHP HIC_API_EMAIL in wp-config.php', 'hotel-in-cloud') . '</strong></p>';
        echo '<input type="hidden" name="hic_api_email" value="' . esc_attr(\FpHic\Helpers\hic_get_option('api_email', '')) . '" />';
    } else {
        echo '<input type="email" name="hic_api_email" id="hic_api_email" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__("Email utilizzata per l'autenticazione Basic Auth verso le API Hotel in Cloud.", 'hotel-in-cloud') . '</p>';
    }
}

function hic_api_password_render() {
    $value = \FpHic\Helpers\hic_get_api_password();
    $is_constant = defined('HIC_API_PASSWORD') && !empty(HIC_API_PASSWORD);
    
    if ($is_constant) {
        echo '<input type="password" id="hic_api_password" value="********" class="regular-text" disabled />';
        echo '<p class="description"><strong>' . esc_html__('Configurato tramite costante PHP HIC_API_PASSWORD in wp-config.php', 'hotel-in-cloud') . '</strong></p>';
        echo '<input type="hidden" name="hic_api_password" value="' . esc_attr(\FpHic\Helpers\hic_get_option('api_password', '')) . '" />';
    } else {
        echo '<input type="password" name="hic_api_password" id="hic_api_password" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__("Password per l'autenticazione Basic Auth verso le API Hotel in Cloud.", 'hotel-in-cloud') . '</p>';
    }
}

function hic_property_id_render() {
    $value = \FpHic\Helpers\hic_get_property_id();
    $is_constant = defined('HIC_PROPERTY_ID') && !empty(HIC_PROPERTY_ID);
    
    if ($is_constant) {
        echo '<input type="number" id="hic_property_id" value="' . esc_attr($value) . '" class="regular-text" disabled />';
        echo '<p class="description"><strong>' . esc_html__('Configurato tramite costante PHP HIC_PROPERTY_ID in wp-config.php', 'hotel-in-cloud') . '</strong></p>';
        echo '<input type="hidden" name="hic_property_id" value="' . esc_attr(\FpHic\Helpers\hic_get_option('property_id', '')) . '" />';
    } else {
        echo '<input type="number" name="hic_property_id" id="hic_property_id" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('ID della struttura (propId) richiesto dalle chiamate API Hotel in Cloud.', 'hotel-in-cloud') . '</p>';
    }
}

function hic_polling_interval_render() {
    $interval = \FpHic\Helpers\hic_get_polling_interval();
    echo '<select name="hic_polling_interval" id="hic_polling_interval">';
    echo '<option value="every_minute"' . selected($interval, 'every_minute', false) . '>' . esc_html__('Ogni 30 secondi (quasi real-time)', 'hotel-in-cloud') . '</option>';
    echo '<option value="every_two_minutes"' . selected($interval, 'every_two_minutes', false) . '>' . esc_html__('Ogni 2 minuti (bilanciato)', 'hotel-in-cloud') . '</option>';
    echo '<option value="hic_poll_interval"' . selected($interval, 'hic_poll_interval', false) . '>' . esc_html__('Ogni 5 minuti (compatibilità)', 'hotel-in-cloud') . '</option>';
    echo '<option value="hic_reliable_interval"' . selected($interval, 'hic_reliable_interval', false) . '>' . esc_html__('Ogni 5 minuti (affidabile)', 'hotel-in-cloud') . '</option>';
    echo '</select>';
    echo '<p class="description">' . esc_html__('Frequenza del polling API per prenotazioni quasi real-time. La modalità Affidabile utilizza WP-Cron con watchdog.', 'hotel-in-cloud') . '</p>';
}

function hic_reliable_polling_enabled_render() {
    $enabled = \FpHic\Helpers\hic_get_option('reliable_polling_enabled', '1') === '1';
    echo '<label class="hic-toggle" for="hic_reliable_polling_enabled">';
    echo '<input type="checkbox" name="hic_reliable_polling_enabled" id="hic_reliable_polling_enabled" value="1"' . checked($enabled, true, false) . ' />';
    echo '<span>' . esc_html__('Attiva sistema polling affidabile', 'hotel-in-cloud') . '</span>';
    echo '</label>';
    echo '<p class="description">' . esc_html__('Sistema interno con watchdog e recupero automatico basato su WP-Cron. Consigliato per hosting condivisi.', 'hotel-in-cloud') . '</p>';
}

// Extended HIC Integration render functions
function hic_currency_render() {
    echo '<input type="text" name="hic_currency" id="hic_currency" value="' . esc_attr(\FpHic\Helpers\hic_get_currency()) . '" maxlength="3" class="regular-text" />';
    echo '<p class="description">' . esc_html__('Valuta utilizzata per GA4 e Meta Pixel (default: EUR).', 'hotel-in-cloud') . '</p>';
}

function hic_ga4_use_net_value_render() {
    $checked = \FpHic\Helpers\hic_use_net_value();
    echo '<label class="hic-toggle" for="hic_ga4_use_net_value">';
    echo '<input type="checkbox" name="hic_ga4_use_net_value" id="hic_ga4_use_net_value" value="1"' . checked($checked, true, false) . ' />';
    echo '<span>' . esc_html__('Usa price - unpaid_balance come valore per GA4/Pixel', 'hotel-in-cloud') . '</span>';
    echo '</label>';
}

function hic_process_invalid_render() {
    $checked = \FpHic\Helpers\hic_process_invalid();
    echo '<label class="hic-toggle" for="hic_process_invalid">';
    echo '<input type="checkbox" name="hic_process_invalid" id="hic_process_invalid" value="1"' . checked($checked, true, false) . ' />';
    echo '<span>' . esc_html__('Processa anche prenotazioni con valid = 0', 'hotel-in-cloud') . '</span>';
    echo '</label>';
}

function hic_allow_status_updates_render() {
    $checked = \FpHic\Helpers\hic_allow_status_updates();
    echo '<label class="hic-toggle" for="hic_allow_status_updates">';
    echo '<input type="checkbox" name="hic_allow_status_updates" id="hic_allow_status_updates" value="1"' . checked($checked, true, false) . ' />';
    echo '<span>' . esc_html__('Permetti aggiornamenti quando cambia presence', 'hotel-in-cloud') . '</span>';
    echo '</label>';
}

function hic_refund_tracking_render() {
    $checked = \FpHic\Helpers\hic_refund_tracking_enabled();
    echo '<label class="hic-toggle" for="hic_refund_tracking">';
    echo '<input type="checkbox" name="hic_refund_tracking" id="hic_refund_tracking" value="1"' . checked($checked, true, false) . ' />';
    echo '<span>' . esc_html__('Abilita tracciamento rimborsi', 'hotel-in-cloud') . '</span>';
    echo '</label>';
}

function hic_brevo_list_it_render() {
    echo '<input type="number" name="hic_brevo_list_it" id="hic_brevo_list_it" value="' . esc_attr(\FpHic\Helpers\hic_get_brevo_list_it()) . '" class="regular-text" />';
    echo '<p class="description">' . esc_html__('ID lista Brevo per contatti italiani.', 'hotel-in-cloud') . '</p>';
}

function hic_brevo_list_en_render() {
    echo '<input type="number" name="hic_brevo_list_en" id="hic_brevo_list_en" value="' . esc_attr(\FpHic\Helpers\hic_get_brevo_list_en()) . '" class="regular-text" />';
    echo '<p class="description">' . esc_html__('ID lista Brevo per contatti inglesi.', 'hotel-in-cloud') . '</p>';
}

function hic_brevo_list_default_render() {
    echo '<input type="number" name="hic_brevo_list_default" id="hic_brevo_list_default" value="' . esc_attr(\FpHic\Helpers\hic_get_brevo_list_default()) . '" class="regular-text" />';
    echo '<p class="description">' . esc_html__('ID lista Brevo per contatti in altre lingue.', 'hotel-in-cloud') . '</p>';
}

function hic_brevo_optin_default_render() {
    $checked = \FpHic\Helpers\hic_get_brevo_optin_default();
    echo '<label class="hic-toggle" for="hic_brevo_optin_default">';
    echo '<input type="checkbox" name="hic_brevo_optin_default" id="hic_brevo_optin_default" value="1"' . checked($checked, true, false) . ' />';
    echo '<span>' . esc_html__('Opt-in marketing di default per nuovi contatti', 'hotel-in-cloud') . '</span>';
    echo '</label>';
}

function hic_debug_verbose_render() {
    $checked = \FpHic\Helpers\hic_is_debug_verbose();
    echo '<label class="hic-toggle" for="hic_debug_verbose">';
    echo '<input type="checkbox" name="hic_debug_verbose" id="hic_debug_verbose" value="1"' . checked($checked, true, false) . ' />';
    echo '<span>' . esc_html__('Abilita log debug estesi (solo per test)', 'hotel-in-cloud') . '</span>';
    echo '</label>';
}

// Email enrichment render functions
function hic_updates_enrich_contacts_render() {
    $checked = \FpHic\Helpers\hic_updates_enrich_contacts();
    echo '<label class="hic-toggle" for="hic_updates_enrich_contacts">';
    echo '<input type="checkbox" name="hic_updates_enrich_contacts" id="hic_updates_enrich_contacts" value="1"' . checked($checked, true, false) . ' />';
    echo '<span>' . esc_html__('Aggiorna contatti Brevo quando arriva email reale da updates', 'hotel-in-cloud') . '</span>';
    echo '</label>';
}

function hic_realtime_brevo_sync_render() {
    $checked = \FpHic\Helpers\hic_realtime_brevo_sync_enabled();
    echo '<label class="hic-toggle" for="hic_realtime_brevo_sync">';
    echo '<input type="checkbox" name="hic_realtime_brevo_sync" id="hic_realtime_brevo_sync" value="1"' . checked($checked, true, false) . ' />';
    echo '<span>' . esc_html__('Invia eventi "reservation_created" a Brevo in tempo reale per nuove prenotazioni', 'hotel-in-cloud') . '</span>';
    echo '</label>';
    echo '<p class="description">' . esc_html__('Quando abilitato, le nuove prenotazioni rilevate dal polling updates invieranno automaticamente eventi a Brevo per automazioni e tracciamento.', 'hotel-in-cloud') . '</p>';
}

function hic_brevo_list_alias_render() {
    echo '<input type="number" name="hic_brevo_list_alias" id="hic_brevo_list_alias" value="' . esc_attr(\FpHic\Helpers\hic_get_brevo_list_alias()) . '" class="regular-text" />';
    echo '<p class="description">' . esc_html__('ID lista Brevo per contatti con email alias (Booking/Airbnb/OTA). Lascia vuoto per non iscriverli.', 'hotel-in-cloud') . '</p>';
}

function hic_brevo_double_optin_on_enrich_render() {
    $checked = \FpHic\Helpers\hic_brevo_double_optin_on_enrich();
    echo '<label class="hic-toggle" for="hic_brevo_double_optin_on_enrich">';
    echo '<input type="checkbox" name="hic_brevo_double_optin_on_enrich" id="hic_brevo_double_optin_on_enrich" value="1"' . checked($checked, true, false) . ' />';
    echo '<span>' . esc_html__('Invia double opt-in quando arriva email reale', 'hotel-in-cloud') . '</span>';
    echo '</label>';
}

function hic_brevo_event_endpoint_render() {
    $endpoint = \FpHic\Helpers\hic_get_brevo_event_endpoint();
    echo '<input type="url" name="hic_brevo_event_endpoint" id="hic_brevo_event_endpoint" value="' . esc_url($endpoint) . '" class="regular-text" />';
    echo '<p class="description">' . esc_html__('Endpoint API per eventi Brevo. Default: https://in-automate.brevo.com/api/v2/trackEvent', 'hotel-in-cloud') . '</p>';
    echo '<p class="description">' . esc_html__('Modificare solo se Brevo cambia il proprio endpoint per gli eventi o se si utilizza un endpoint personalizzato.', 'hotel-in-cloud') . '</p>';
}

/* ============ Validation Functions ============ */
/**
 * Preserve password values without stripping characters that are valid for API authentication.
 *
 * WordPress core adds slashes to incoming form values, so we only unslash the
 * value and cast it to a string. Any other data types are coerced to an empty
 * string to avoid storing unexpected structures.
 *
 * @param mixed $value Raw password value coming from user input.
 * @return string The password with original characters preserved.
 */
function hic_preserve_password_field($value) {
    if (is_array($value) || is_object($value)) {
        return '';
    }

    if ($value === null) {
        return '';
    }

    return wp_unslash((string) $value);
}

function hic_validate_admin_email($input) {
    // Allow empty value (will fall back to WordPress admin email)
    if (empty($input)) {
        delete_option('hic_admin_email');
        \FpHic\Helpers\hic_clear_option_cache('admin_email');
        add_filter('pre_update_option_hic_admin_email', '__return_false');
        return '';
    }

    // Sanitize the email
    $sanitized_email = sanitize_email($input);

    // Validate email format
    if (!is_email($sanitized_email)) {
        add_settings_error(
            'hic_admin_email',
            'invalid_email',
            'L\'indirizzo email inserito non è valido. Sono stati ripristinati i valori precedenti.',
            'error'
        );
        // Return the original value from database
        return \FpHic\Helpers\hic_get_option('admin_email', '');
    }

    // Log the email change for transparency
    $old_email = \FpHic\Helpers\hic_get_option('admin_email', '');
    if ($old_email !== $sanitized_email) {
        hic_log('Admin email changed from "' . $old_email . '" to "' . $sanitized_email . '"');

        // Show success message
        add_settings_error(
            'hic_admin_email',
            'email_updated',
            'Email amministratore aggiornato con successo: ' . $sanitized_email,
            'updated'
        );
    }

    return $sanitized_email;
}

/* ============ Admin Security Headers ============ */
/**
 * Determine if the current request targets one of the plugin admin screens.
 */
function hic_is_plugin_admin_request(): bool {
    if ((defined('DOING_AJAX') && DOING_AJAX) || (defined('REST_REQUEST') && REST_REQUEST)) {
        return false;
    }

    $page = '';
    if (isset($_GET['page'])) {
        $raw_page = wp_unslash($_GET['page']);
        if (is_string($raw_page)) {
            $page = strtolower(preg_replace('/[^a-z0-9_-]/', '', $raw_page) ?? '');
        }
    }

    if ($page === '') {
        return false;
    }

    $allowed_pages = [
        'hic-monitoring',
        'hic-monitoring-settings',
        'hic-diagnostics',
        'hic-circuit-breakers',
        'hic-reports',
        'hic-enhanced-conversions',
        'hic-setup-wizard',
        'hic-performance-monitor'
    ];
    if (!in_array($page, $allowed_pages, true)) {
        return false;
    }

    if (function_exists('is_admin') && is_admin()) {
        return true;
    }

    if (defined('WP_ADMIN') && WP_ADMIN) {
        return true;
    }

    $script_name = $_SERVER['PHP_SELF'] ?? '';
    if (is_string($script_name) && $script_name !== '') {
        $normalized = strtolower($script_name);
        if (strpos($normalized, 'wp-admin/') !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Inject security-focused HTTP headers when rendering plugin admin pages.
 *
 * @param array<string,string> $headers Existing response headers.
 * @param mixed                $wp      Optional WP instance.
 * @return array<string,string> Modified headers for secure delivery.
 */
function hic_filter_admin_security_headers(array $headers, $wp = null): array {
    if (!hic_is_plugin_admin_request()) {
        return $headers;
    }

    $headers['X-Frame-Options'] = 'SAMEORIGIN';
    $headers['X-Content-Type-Options'] = 'nosniff';

    if (!isset($headers['Referrer-Policy'])) {
        $headers['Referrer-Policy'] = 'no-referrer-when-downgrade';
    }

    if (!isset($headers['Permissions-Policy'])) {
        $headers['Permissions-Policy'] = "geolocation=(), microphone=(), camera=()";
    }

    if (!isset($headers['Content-Security-Policy'])) {
        $headers['Content-Security-Policy'] = "frame-ancestors 'self'";
    }

    if (!isset($headers['Strict-Transport-Security']) && function_exists('is_ssl') && is_ssl()) {
        $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
    }

    return $headers;
}
