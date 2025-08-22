<?php
/**
 * Admin Settings Page for HIC Plugin
 */

if (!defined('ABSPATH')) exit;

/* ============ Admin Settings Page ============ */
add_action('admin_menu', 'hic_add_admin_menu');
add_action('admin_init', 'hic_settings_init');

function hic_add_admin_menu() {
    add_options_page(
        'HIC Monitoring Settings',
        'HIC Monitoring',
        'manage_options',
        'hic-monitoring',
        'hic_options_page'
    );
}

function hic_settings_init() {
    register_setting('hic_settings', 'hic_measurement_id');
    register_setting('hic_settings', 'hic_api_secret');
    register_setting('hic_settings', 'hic_brevo_enabled');
    register_setting('hic_settings', 'hic_brevo_api_key');
    register_setting('hic_settings', 'hic_brevo_list_ita');
    register_setting('hic_settings', 'hic_brevo_list_eng');
    register_setting('hic_settings', 'hic_fb_pixel_id');
    register_setting('hic_settings', 'hic_fb_access_token');
    register_setting('hic_settings', 'hic_webhook_token');
    register_setting('hic_settings', 'hic_admin_email');
    register_setting('hic_settings', 'hic_log_file');
    register_setting('hic_settings', 'hic_connection_type');
    register_setting('hic_settings', 'hic_api_url');
    register_setting('hic_settings', 'hic_api_key');

    add_settings_section('hic_main_section', 'Configurazione Principale', null, 'hic_settings');
    add_settings_section('hic_ga4_section', 'Google Analytics 4', null, 'hic_settings');
    add_settings_section('hic_brevo_section', 'Brevo Settings', null, 'hic_settings');
    add_settings_section('hic_fb_section', 'Facebook Meta', null, 'hic_settings');
    add_settings_section('hic_hic_section', 'Hotel in Cloud', null, 'hic_settings');

    // Main settings
    add_settings_field('hic_admin_email', 'Email Amministratore', 'hic_admin_email_render', 'hic_settings', 'hic_main_section');
    add_settings_field('hic_log_file', 'File di Log', 'hic_log_file_render', 'hic_settings', 'hic_main_section');
    
    // GA4 settings
    add_settings_field('hic_measurement_id', 'Measurement ID', 'hic_measurement_id_render', 'hic_settings', 'hic_ga4_section');
    add_settings_field('hic_api_secret', 'API Secret', 'hic_api_secret_render', 'hic_settings', 'hic_ga4_section');
    
    // Brevo settings
    add_settings_field('hic_brevo_enabled', 'Abilita Brevo', 'hic_brevo_enabled_render', 'hic_settings', 'hic_brevo_section');
    add_settings_field('hic_brevo_api_key', 'API Key', 'hic_brevo_api_key_render', 'hic_settings', 'hic_brevo_section');
    add_settings_field('hic_brevo_list_ita', 'Lista Italiana', 'hic_brevo_list_ita_render', 'hic_settings', 'hic_brevo_section');
    add_settings_field('hic_brevo_list_eng', 'Lista Inglese', 'hic_brevo_list_eng_render', 'hic_settings', 'hic_brevo_section');
    
    // Facebook settings
    add_settings_field('hic_fb_pixel_id', 'Pixel ID', 'hic_fb_pixel_id_render', 'hic_settings', 'hic_fb_section');
    add_settings_field('hic_fb_access_token', 'Access Token', 'hic_fb_access_token_render', 'hic_settings', 'hic_fb_section');
    
    // Hotel in Cloud settings
    add_settings_field('hic_connection_type', 'Tipo Connessione', 'hic_connection_type_render', 'hic_settings', 'hic_hic_section');
    add_settings_field('hic_webhook_token', 'Webhook Token', 'hic_webhook_token_render', 'hic_settings', 'hic_hic_section');
    add_settings_field('hic_api_url', 'API URL', 'hic_api_url_render', 'hic_settings', 'hic_hic_section');
    add_settings_field('hic_api_key', 'API Key', 'hic_api_key_render', 'hic_settings', 'hic_hic_section');
}

function hic_options_page() {
    ?>
    <div class="wrap">
        <h1>Hotel in Cloud - Monitoraggio Conversioni</h1>
        <form action='options.php' method='post'>
            <?php
            settings_fields('hic_settings');
            do_settings_sections('hic_settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Render functions for settings fields
function hic_admin_email_render() {
    echo '<input type="email" name="hic_admin_email" value="' . esc_attr(hic_get_admin_email()) . '" class="regular-text" />';
}

function hic_log_file_render() {
    echo '<input type="text" name="hic_log_file" value="' . esc_attr(hic_get_log_file()) . '" class="regular-text" />';
}

function hic_measurement_id_render() {
    echo '<input type="text" name="hic_measurement_id" value="' . esc_attr(hic_get_measurement_id()) . '" class="regular-text" />';
}

function hic_api_secret_render() {
    echo '<input type="text" name="hic_api_secret" value="' . esc_attr(hic_get_api_secret()) . '" class="regular-text" />';
}

function hic_brevo_enabled_render() {
    $checked = hic_is_brevo_enabled() ? 'checked' : '';
    echo '<input type="checkbox" name="hic_brevo_enabled" value="1" ' . $checked . ' /> Abilita integrazione Brevo';
}

function hic_brevo_api_key_render() {
    echo '<input type="password" name="hic_brevo_api_key" value="' . esc_attr(hic_get_brevo_api_key()) . '" class="regular-text" />';
}

function hic_brevo_list_ita_render() {
    echo '<input type="number" name="hic_brevo_list_ita" value="' . esc_attr(hic_get_brevo_list_ita()) . '" />';
}

function hic_brevo_list_eng_render() {
    echo '<input type="number" name="hic_brevo_list_eng" value="' . esc_attr(hic_get_brevo_list_eng()) . '" />';
}

function hic_fb_pixel_id_render() {
    echo '<input type="text" name="hic_fb_pixel_id" value="' . esc_attr(hic_get_fb_pixel_id()) . '" class="regular-text" />';
}

function hic_fb_access_token_render() {
    echo '<input type="password" name="hic_fb_access_token" value="' . esc_attr(hic_get_fb_access_token()) . '" class="regular-text" />';
}

function hic_connection_type_render() {
    $type = hic_get_connection_type();
    echo '<select name="hic_connection_type">';
    echo '<option value="webhook"' . selected($type, 'webhook', false) . '>Webhook</option>';
    echo '<option value="api"' . selected($type, 'api', false) . '>API Polling</option>';
    echo '</select>';
}

function hic_webhook_token_render() {
    echo '<input type="text" name="hic_webhook_token" value="' . esc_attr(hic_get_webhook_token()) . '" class="regular-text" />';
    echo '<p class="description">Token per autenticare il webhook</p>';
}

function hic_api_url_render() {
    echo '<input type="url" name="hic_api_url" value="' . esc_attr(hic_get_api_url()) . '" class="regular-text" />';
    echo '<p class="description">URL delle API Hotel in Cloud (solo se si usa API Polling)</p>';
}

function hic_api_key_render() {
    echo '<input type="password" name="hic_api_key" value="' . esc_attr(hic_get_api_key()) . '" class="regular-text" />';
    echo '<p class="description">API Key per Hotel in Cloud (solo se si usa API Polling)</p>';
}