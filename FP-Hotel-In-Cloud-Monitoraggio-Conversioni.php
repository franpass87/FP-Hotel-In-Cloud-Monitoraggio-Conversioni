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
// Ensure plugin constants are loaded before usage
require_once __DIR__ . '/includes/constants.php';
require_once __DIR__ . '/includes/booking-poller.php';

// Plugin activation handler
function hic_activate($network_wide)
{
    if ($network_wide) {
        $sites = \get_sites();
        foreach ($sites as $site) {
            \switch_to_blog($site->blog_id);
            \hic_maybe_upgrade_db();
            $role = \get_role('administrator');
            if ($role && !$role->has_cap('hic_manage')) {
                $role->add_cap('hic_manage');
            }
            \restore_current_blog();
        }
    } else {
        \hic_maybe_upgrade_db();
        $role = \get_role('administrator');
        if ($role && !$role->has_cap('hic_manage')) {
            $role->add_cap('hic_manage');
        }
    }
}

// Plugin activation hook
\register_activation_hook(__FILE__, __NAMESPACE__ . '\\hic_activate');
\register_deactivation_hook(__FILE__, 'hic_deactivate');
\add_action('plugins_loaded', '\\hic_maybe_upgrade_db');

// Add settings link in plugin list
\add_filter('plugin_action_links_' . \plugin_basename(__FILE__), function ($links) {
    $settings_link = '<a href="' . \admin_url('options-general.php?page=hic-monitoring') . '">' . \__('Impostazioni', 'hotel-in-cloud') . '</a>';
    \array_unshift($links, $settings_link);
    return $links;
});

// Initialize tracking parameters capture
\add_action('init', function () {
    if (!\is_admin() && \php_sapi_name() !== 'cli') {
        \hic_capture_tracking_params();
    }
});

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
        HIC_PLUGIN_VERSION,
        true
    );
});
