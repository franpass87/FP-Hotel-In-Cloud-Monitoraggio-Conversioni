<?php declare(strict_types=1);
/**
 * Admin Settings Page for HIC Plugin
 */

if (!defined('ABSPATH')) exit;

/* ============ Admin Settings Page ============ */
add_action('admin_menu', 'hic_add_admin_menu');
add_action('admin_init', 'hic_settings_init');
add_action('admin_enqueue_scripts', 'hic_admin_enqueue_scripts');

// Add AJAX handler for API connection test
add_action('wp_ajax_hic_test_api_connection', 'hic_ajax_test_api_connection');

// Add AJAX handler for email configuration test
add_action('wp_ajax_hic_test_email_ajax', 'hic_ajax_test_email');

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

function hic_add_admin_menu() {
    add_menu_page(
        'HIC Monitoring Settings',
        'HIC Monitoring',
        'hic_manage',
        'hic-monitoring',
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
    // Only load on our plugin pages
    if ($hook === 'hic-monitoring_page_hic-diagnostics' || $hook === 'toplevel_page_hic-monitoring') {
        // Ensure jQuery is loaded
        wp_enqueue_script('jquery');
    }

    if ($hook === 'toplevel_page_hic-monitoring') {
        wp_enqueue_style(
            'hic-admin-settings',
            plugin_dir_url(__FILE__) . '../../assets/css/admin-settings.css',
            array(),
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
        ));
    }

    if ($hook === 'hic-monitoring_page_hic-diagnostics') {
        wp_enqueue_style(
            'hic-diagnostics',
            plugin_dir_url(__FILE__) . '../../assets/css/diagnostics.css',
            array(),
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
        ));
    }
}

function hic_options_page() {
    ?>
    <div class="wrap">
        <?php settings_errors(); ?>
        <h1>Hotel in Cloud - Monitoraggio Conversioni</h1>
        <form action='options.php' method='post'>
            <?php
            settings_fields('hic_settings');
            do_settings_sections('hic_settings');
            submit_button();
            ?>
        </form>
        
        <!-- API Connection Test Section -->
        <?php if (\FpHic\Helpers\hic_connection_uses_api()): ?>
        <div class="hic-api-test-section" style="margin-top: 30px; padding: 20px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 5px;">
            <h2>Test Connessione API</h2>
            <p>Testa la connessione alle API Hotel in Cloud con le credenziali Basic Auth configurate.</p>
            
            <button type="button" id="hic-test-api-btn" class="button button-secondary">
                <span class="dashicons dashicons-admin-tools" style="margin-top: 3px;"></span>
                Testa Connessione API
            </button>
            
            <div id="hic-test-result" style="margin-top: 15px; display: none;"></div>
            
            <div id="hic-test-loading" style="margin-top: 15px; display: none;">
                <span class="spinner is-active" style="float: left; margin: 0 10px 0 0;"></span>
                <span>Test in corso... attendere...</span>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

// Render functions for settings fields
function hic_admin_email_render() {
    $current_email = \FpHic\Helpers\hic_get_admin_email();
    $custom_email = \FpHic\Helpers\hic_get_option('admin_email', '');
    $wp_admin_email = get_option('admin_email');
    
    echo '<input type="email" name="hic_admin_email" value="' . esc_attr($current_email) . '" class="regular-text" id="hic_admin_email_field" />';
    echo '<button type="button" class="button" id="hic-test-email-btn" style="margin-left: 10px;">Test Email</button>';
    echo '<div id="hic_email_test_result" style="margin-top: 10px;"></div>';
    
    echo '<p class="description">';
    echo 'Email per ricevere notifiche di nuove prenotazioni. ';
    if (empty($custom_email)) {
        echo '<strong>Attualmente usa l\'email WordPress:</strong> ' . esc_html($wp_admin_email);
    } else {
        echo '<strong>Email personalizzata configurata:</strong> ' . esc_html($custom_email);
    }
    echo '</p>';
    
    // Add troubleshooting guide
    echo '<div class="hic-email-troubleshooting" style="margin-top: 15px; padding: 15px; border: 1px solid #ddd; background-color: #f9f9f9;">';
    echo '<h4 style="margin-top: 0;">🔧 Risoluzione Problemi Email</h4>';
    echo '<details>';
    echo '<summary style="cursor: pointer; font-weight: bold;">Se le email non arrivano, segui questi passi:</summary>';
    echo '<ol style="margin: 10px 0;">';
    echo '<li><strong>Testa la configurazione:</strong> Usa il pulsante "Test Email" sopra per verificare l\'invio</li>';
    echo '<li><strong>Controlla lo spam:</strong> Verifica la cartella spam/junk della casella email</li>';
    echo '<li><strong>Verifica l\'indirizzo email:</strong> Assicurati che l\'email sia corretta e funzionante</li>';
    echo '<li><strong>Controlla i log:</strong> Vai in Diagnostics per vedere i log dettagliati degli invii</li>';
    echo '<li><strong>Configurazione SMTP:</strong> Se il test fallisce, potrebbe servire un plugin SMTP (WP Mail SMTP, Easy WP SMTP)</li>';
    echo '<li><strong>Contatta l\'hosting:</strong> Se tutto sopra è OK, il problema potrebbe essere nel server email</li>';
    echo '</ol>';
    echo '<p><strong>Configurazioni comuni che causano problemi:</strong></p>';
    echo '<ul>';
    echo '<li>Server senza funzione mail() PHP abilitata</li>';
    echo '<li>Provider hosting che blocca l\'invio email</li>';
    echo '<li>Mancanza di configurazione SMTP</li>';
    echo '<li>Email che finiscono in blacklist per spam</li>';
    echo '</ul>';
    echo '</details>';
    echo '</div>';
}



function hic_log_file_render() {
    echo '<input type="text" name="hic_log_file" value="' . esc_attr(hic_get_log_file()) . '" class="regular-text" />';
}

function hic_log_level_render() {
    $level = hic_get_option('log_level', HIC_LOG_LEVEL_INFO);
    echo '<select name="hic_log_level" class="regular-text">';
    echo '<option value="' . esc_attr(HIC_LOG_LEVEL_ERROR) . '"' . selected($level, HIC_LOG_LEVEL_ERROR, false) . '>Error</option>';
    echo '<option value="' . esc_attr(HIC_LOG_LEVEL_WARNING) . '"' . selected($level, HIC_LOG_LEVEL_WARNING, false) . '>Warning</option>';
    echo '<option value="' . esc_attr(HIC_LOG_LEVEL_INFO) . '"' . selected($level, HIC_LOG_LEVEL_INFO, false) . '>Info</option>';
    echo '<option value="' . esc_attr(HIC_LOG_LEVEL_DEBUG) . '"' . selected($level, HIC_LOG_LEVEL_DEBUG, false) . '>Debug</option>';
    echo '</select>';
}

function hic_measurement_id_render() {
    echo '<input type="text" name="hic_measurement_id" value="' . esc_attr(\FpHic\Helpers\hic_get_measurement_id()) . '" class="regular-text" />';
}

function hic_api_secret_render() {
    echo '<input type="text" name="hic_api_secret" value="' . esc_attr(\FpHic\Helpers\hic_get_api_secret()) . '" class="regular-text" />';
}

// GTM render functions
function hic_gtm_enabled_render() {
    $checked = \FpHic\Helpers\hic_is_gtm_enabled() ? 'checked' : '';
    echo '<input type="checkbox" name="hic_gtm_enabled" value="1" ' . esc_attr($checked) . ' /> Abilita integrazione Google Tag Manager';
    echo '<p class="description">Abilita il tracciamento tramite Google Tag Manager per una gestione più flessibile dei tag.</p>';
}

function hic_gtm_container_id_render() {
    echo '<input type="text" name="hic_gtm_container_id" value="' . esc_attr(\FpHic\Helpers\hic_get_gtm_container_id()) . '" class="regular-text" placeholder="GTM-XXXXXXX" />';
    echo '<p class="description">ID del container GTM (formato: GTM-XXXXXXX). Disponibile in Google Tag Manager sotto "ID container".</p>';
}

function hic_tracking_mode_render() {
    $mode = \FpHic\Helpers\hic_get_tracking_mode();
    echo '<select name="hic_tracking_mode" class="regular-text">';
    echo '<option value="ga4_only"' . selected($mode, 'ga4_only', false) . '>Solo GA4 Measurement Protocol (Server-side)</option>';
    echo '<option value="gtm_only"' . selected($mode, 'gtm_only', false) . '>Solo Google Tag Manager (Client-side)</option>';
    echo '<option value="hybrid"' . selected($mode, 'hybrid', false) . '>Ibrido (GTM + GA4 backup per server-side)</option>';
    echo '</select>';
    echo '<p class="description">';
    echo '<strong>GA4 Only:</strong> Tracciamento server-side via Measurement Protocol (attuale, più affidabile).<br>';
    echo '<strong>GTM Only:</strong> Tracciamento client-side via DataLayer (più flessibile per gestire multiple piattaforme).<br>';
    echo '<strong>Ibrido:</strong> GTM per client-side + GA4 come backup server-side (raccomandato per massima copertura).';
    echo '</p>';
}

function hic_brevo_enabled_render() {
    $checked = \FpHic\Helpers\hic_is_brevo_enabled() ? 'checked' : '';
    echo '<input type="checkbox" name="hic_brevo_enabled" value="1" ' . esc_attr($checked) . ' /> Abilita integrazione Brevo';
}

function hic_brevo_api_key_render() {
    echo '<input type="password" name="hic_brevo_api_key" value="' . esc_attr(\FpHic\Helpers\hic_get_brevo_api_key()) . '" class="regular-text" />';
}

function hic_fb_pixel_id_render() {
    echo '<input type="text" name="hic_fb_pixel_id" value="' . esc_attr(\FpHic\Helpers\hic_get_fb_pixel_id()) . '" class="regular-text" />';
}

function hic_fb_access_token_render() {
    echo '<input type="password" name="hic_fb_access_token" value="' . esc_attr(\FpHic\Helpers\hic_get_fb_access_token()) . '" class="regular-text" />';
}

function hic_connection_type_render() {
    $type = \FpHic\Helpers\hic_get_connection_type();
    $normalized = \FpHic\Helpers\hic_normalize_connection_type($type);
    echo '<select name="hic_connection_type">';
    echo '<option value="webhook"' . selected($normalized, 'webhook', false) . '>Webhook</option>';
    echo '<option value="api"' . selected($normalized, 'api', false) . '>API Polling</option>';
    echo '<option value="hybrid"' . selected($normalized, 'hybrid', false) . '>Hybrid (Webhook + API)</option>';
    echo '</select>';
    echo '<p class="description">Hybrid: combina webhook in tempo reale con API polling di backup per massima affidabilità</p>';
}

function hic_webhook_token_render() {
    echo '<input type="text" name="hic_webhook_token" value="' . esc_attr(\FpHic\Helpers\hic_get_webhook_token()) . '" class="regular-text" />';
    echo '<p class="description">Token per autenticare il webhook</p>';
}

function hic_api_url_render() {
    echo '<input type="url" name="hic_api_url" value="' . esc_url(\FpHic\Helpers\hic_get_api_url()) . '" class="regular-text" />';
    echo '<p class="description">URL delle API Hotel in Cloud (solo se si usa API Polling)</p>';
}

// Basic Auth render functions
function hic_api_email_render() {
    $value = \FpHic\Helpers\hic_get_api_email();
    $is_constant = defined('HIC_API_EMAIL') && !empty(HIC_API_EMAIL);
    
    if ($is_constant) {
        echo '<input type="email" value="' . esc_attr($value) . '" class="regular-text" disabled />';
        echo '<p class="description"><strong>Configurato tramite costante PHP HIC_API_EMAIL in wp-config.php</strong></p>';
        echo '<input type="hidden" name="hic_api_email" value="' . esc_attr(\FpHic\Helpers\hic_get_option('api_email', '')) . '" />';
    } else {
        echo '<input type="email" name="hic_api_email" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Email per autenticazione Basic Auth alle API Hotel in Cloud</p>';
    }
}

function hic_api_password_render() {
    $value = \FpHic\Helpers\hic_get_api_password();
    $is_constant = defined('HIC_API_PASSWORD') && !empty(HIC_API_PASSWORD);
    
    if ($is_constant) {
        echo '<input type="password" value="********" class="regular-text" disabled />';
        echo '<p class="description"><strong>Configurato tramite costante PHP HIC_API_PASSWORD in wp-config.php</strong></p>';
        echo '<input type="hidden" name="hic_api_password" value="' . esc_attr(\FpHic\Helpers\hic_get_option('api_password', '')) . '" />';
    } else {
        echo '<input type="password" name="hic_api_password" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Password per autenticazione Basic Auth alle API Hotel in Cloud</p>';
    }
}

function hic_property_id_render() {
    $value = \FpHic\Helpers\hic_get_property_id();
    $is_constant = defined('HIC_PROPERTY_ID') && !empty(HIC_PROPERTY_ID);
    
    if ($is_constant) {
        echo '<input type="number" value="' . esc_attr($value) . '" class="regular-text" disabled />';
        echo '<p class="description"><strong>Configurato tramite costante PHP HIC_PROPERTY_ID in wp-config.php</strong></p>';
        echo '<input type="hidden" name="hic_property_id" value="' . esc_attr(\FpHic\Helpers\hic_get_option('property_id', '')) . '" />';
    } else {
        echo '<input type="number" name="hic_property_id" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">ID della struttura (propId) per le chiamate API</p>';
    }
}

function hic_polling_interval_render() {
    $interval = \FpHic\Helpers\hic_get_polling_interval();
    echo '<select name="hic_polling_interval">';
    echo '<option value="every_minute"' . selected($interval, 'every_minute', false) . '>Ogni 30 secondi (quasi real-time)</option>';
    echo '<option value="every_two_minutes"' . selected($interval, 'every_two_minutes', false) . '>Ogni 2 minuti (bilanciato)</option>';
    echo '<option value="hic_poll_interval"' . selected($interval, 'hic_poll_interval', false) . '>Ogni 5 minuti (compatibilità)</option>';
    echo '<option value="hic_reliable_interval"' . selected($interval, 'hic_reliable_interval', false) . '>Ogni 5 minuti (affidabile)</option>';
    echo '</select>';
    echo '<p class="description">Frequenza del polling API per prenotazioni quasi real-time. "Affidabile" utilizza WP-Cron con watchdog.</p>';
}

function hic_reliable_polling_enabled_render() {
    $enabled = \FpHic\Helpers\hic_get_option('reliable_polling_enabled', '1') === '1';
    echo '<label>';
    echo '<input type="checkbox" name="hic_reliable_polling_enabled" value="1"' . checked($enabled, true, false) . ' />';
    echo ' Attiva sistema polling affidabile';
    echo '</label>';
    echo '<p class="description">Sistema interno con watchdog e recupero automatico basato su WP-Cron. <strong>Raccomandato per hosting condiviso.</strong></p>';
}

// Extended HIC Integration render functions
function hic_currency_render() {
    echo '<input type="text" name="hic_currency" value="' . esc_attr(\FpHic\Helpers\hic_get_currency()) . '" maxlength="3" />';
    echo '<p class="description">Valuta per GA4 e Meta Pixel (default: EUR)</p>';
}

function hic_ga4_use_net_value_render() {
    $checked = \FpHic\Helpers\hic_use_net_value() ? 'checked' : '';
    echo '<input type="checkbox" name="hic_ga4_use_net_value" value="1" ' . esc_attr($checked) . ' /> Usa price - unpaid_balance come valore per GA4/Pixel';
}

function hic_process_invalid_render() {
    $checked = \FpHic\Helpers\hic_process_invalid() ? 'checked' : '';
    echo '<input type="checkbox" name="hic_process_invalid" value="1" ' . esc_attr($checked) . ' /> Processa anche prenotazioni con valid=0';
}

function hic_allow_status_updates_render() {
    $checked = \FpHic\Helpers\hic_allow_status_updates() ? 'checked' : '';
    echo '<input type="checkbox" name="hic_allow_status_updates" value="1" ' . esc_attr($checked) . ' /> Permetti aggiornamenti quando cambia presence';
}

function hic_refund_tracking_render() {
    $checked = \FpHic\Helpers\hic_refund_tracking_enabled() ? 'checked' : '';
    echo '<input type="checkbox" name="hic_refund_tracking" value="1" ' . esc_attr($checked) . ' /> Abilita tracciamento rimborsi';
}

function hic_brevo_list_it_render() {
    echo '<input type="number" name="hic_brevo_list_it" value="' . esc_attr(\FpHic\Helpers\hic_get_brevo_list_it()) . '" />';
    echo '<p class="description">ID lista Brevo per contatti italiani</p>';
}

function hic_brevo_list_en_render() {
    echo '<input type="number" name="hic_brevo_list_en" value="' . esc_attr(\FpHic\Helpers\hic_get_brevo_list_en()) . '" />';
    echo '<p class="description">ID lista Brevo per contatti inglesi</p>';
}

function hic_brevo_list_default_render() {
    echo '<input type="number" name="hic_brevo_list_default" value="' . esc_attr(\FpHic\Helpers\hic_get_brevo_list_default()) . '" />';
    echo '<p class="description">ID lista Brevo per altre lingue</p>';
}

function hic_brevo_optin_default_render() {
    $checked = \FpHic\Helpers\hic_get_brevo_optin_default() ? 'checked' : '';
    echo '<input type="checkbox" name="hic_brevo_optin_default" value="1" ' . esc_attr($checked) . ' /> Opt-in marketing di default per nuovi contatti';
}

function hic_debug_verbose_render() {
    $checked = \FpHic\Helpers\hic_is_debug_verbose() ? 'checked' : '';
    echo '<input type="checkbox" name="hic_debug_verbose" value="1" ' . esc_attr($checked) . ' /> Abilita log debug estesi (solo per test)';
}

// Email enrichment render functions
function hic_updates_enrich_contacts_render() {
    $checked = \FpHic\Helpers\hic_updates_enrich_contacts() ? 'checked' : '';
    echo '<input type="checkbox" name="hic_updates_enrich_contacts" value="1" ' . esc_attr($checked) . ' /> Aggiorna contatti Brevo quando arriva email reale da updates';
}

function hic_realtime_brevo_sync_render() {
    $checked = \FpHic\Helpers\hic_realtime_brevo_sync_enabled() ? 'checked' : '';
    echo '<input type="checkbox" name="hic_realtime_brevo_sync" value="1" ' . esc_attr($checked) . ' /> Invia eventi "reservation_created" a Brevo in tempo reale per nuove prenotazioni';
    echo '<p class="description">Quando abilitato, le nuove prenotazioni rilevate dal polling updates invieranno automaticamente eventi a Brevo per automazioni e tracciamento.</p>';
}

function hic_brevo_list_alias_render() {
    echo '<input type="number" name="hic_brevo_list_alias" value="' . esc_attr(\FpHic\Helpers\hic_get_brevo_list_alias()) . '" />';
    echo '<p class="description">ID lista Brevo per contatti con email alias (Booking/Airbnb/OTA). Lascia vuoto per non iscriverli a nessuna lista.</p>';
}

function hic_brevo_double_optin_on_enrich_render() {
    $checked = \FpHic\Helpers\hic_brevo_double_optin_on_enrich() ? 'checked' : '';
    echo '<input type="checkbox" name="hic_brevo_double_optin_on_enrich" value="1" ' . esc_attr($checked) . ' /> Invia double opt-in quando arriva email reale';
}

function hic_brevo_event_endpoint_render() {
    $endpoint = \FpHic\Helpers\hic_get_brevo_event_endpoint();
    echo '<input type="url" name="hic_brevo_event_endpoint" value="' . esc_url($endpoint) . '" style="width: 100%;" />';
    echo '<p class="description">Endpoint API per eventi Brevo. Default: https://in-automate.brevo.com/api/v2/trackEvent<br>';
    echo 'Modificare solo se Brevo cambia il proprio endpoint per gli eventi o se si utilizza un endpoint personalizzato.</p>';
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