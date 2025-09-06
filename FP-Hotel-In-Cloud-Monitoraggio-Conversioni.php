<?php
/**
 * Plugin Name: HIC GA4 + Brevo + Meta (bucket strategy)
 * Description: Tracciamento prenotazioni Hotel in Cloud → GA4 (purchase), Meta CAPI (Purchase) e Brevo (contact+event), con bucket gads/fbads/organic. Salvataggio gclid/fbclid↔sid e append sid ai link HIC.
 * Version: 1.4.0
 * Author: Francesco Passeri
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: hotel-in-cloud
 * Domain Path: /languages
 */

namespace FpHic;

if (!defined('ABSPATH')) {
    exit;
}

// Load Composer autoloader
require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/booking-poller.php';

// Plugin activation hook
\register_activation_hook(__FILE__, 'hic_create_database_table');
\register_deactivation_hook(__FILE__, 'hic_deactivate');

// Add settings link in plugin list
\add_filter('plugin_action_links_' . \plugin_basename(__FILE__), function ($links) {
    $settings_link = '<a href="' . \admin_url('options-general.php?page=hic-monitoring') . '">' . \__('Impostazioni', 'hotel-in-cloud') . '</a>';
    \array_unshift($links, $settings_link);
    return $links;
});

// Initialize tracking parameters capture
\add_action('init', 'hic_capture_tracking_params');

// Initialize enhanced systems when WordPress is ready
\add_action('init', function() {
    // Initialize log manager
    \hic_get_log_manager();

    // Initialize performance monitor
    \hic_get_performance_monitor();

    // Initialize config validator
    \hic_get_config_validator();

    // Initialize health monitor
    \hic_get_health_monitor();
});

// Enqueue frontend JavaScript
\add_action('wp_enqueue_scripts', function() {
    \wp_enqueue_script(
        'hic-frontend',
        \plugin_dir_url(__FILE__) . 'assets/js/frontend.js',
        array(),
        '1.4.0',
        true
    );
});
