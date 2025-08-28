<?php
/**
 * Plugin Name: HIC GA4 + Brevo + Meta (bucket strategy)
 * Description: Tracciamento prenotazioni Hotel in Cloud → GA4 (purchase), Meta CAPI (Purchase) e Brevo (contact+event), con bucket gads/fbads/organic. Salvataggio gclid/fbclid↔sid e append sid ai link HIC.
 * Version: 1.4.0
 * Author: Francesco Passeri
 */

if (!defined('ABSPATH')) exit;

// Include core functionality files
require_once plugin_dir_path(__FILE__) . 'includes/functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/database.php';
require_once plugin_dir_path(__FILE__) . 'includes/booking-processor.php';

// Include integration files
require_once plugin_dir_path(__FILE__) . 'includes/integrations/ga4.php';
require_once plugin_dir_path(__FILE__) . 'includes/integrations/facebook.php';
require_once plugin_dir_path(__FILE__) . 'includes/integrations/brevo.php';

// Include API handlers
require_once plugin_dir_path(__FILE__) . 'includes/api/webhook.php';
require_once plugin_dir_path(__FILE__) . 'includes/api/polling.php';

// Include admin interface (only in admin area)
if (is_admin()) {
    require_once plugin_dir_path(__FILE__) . 'includes/admin/admin-settings.php';
    require_once plugin_dir_path(__FILE__) . 'includes/admin/diagnostics.php';
}

// Plugin activation hook
register_activation_hook(__FILE__, 'hic_create_database_table');

// Initialize tracking parameters capture
add_action('init', 'hic_capture_tracking_params');

// Enqueue frontend JavaScript
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_script(
        'hic-frontend', 
        plugin_dir_url(__FILE__) . 'assets/js/frontend.js',
        array(),
        '1.4.0',
        true
    );
});