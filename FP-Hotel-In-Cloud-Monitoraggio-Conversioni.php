<?php declare(strict_types=1);
/**
 * Plugin Name: FP HIC Monitor
 * Description: Monitoraggio conversioni Hotel in Cloud con tracciamento avanzato verso GA4, Meta CAPI e Brevo. Sistema sicuro enterprise-grade con cache intelligente e validazione input.
 * Version: 3.1.0
 * Author: Francesco Passeri
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: hotel-in-cloud
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace FpHic;

if (!defined('ABSPATH')) {
    exit;
}

\add_action('plugins_loaded', function () {
    \load_plugin_textdomain('hotel-in-cloud', false, \dirname(\plugin_basename(__FILE__)) . '/languages');
});

// Load Composer autoloader or fallback to manual loading
$vendor_available = false;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
    $vendor_available = true;
}

// Ensure plugin constants and core files are loaded
require_once __DIR__ . '/includes/constants.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/log-manager.php';
require_once __DIR__ . '/includes/http-security.php';
require_once __DIR__ . '/includes/input-validator.php';
require_once __DIR__ . '/includes/cache-manager.php';
require_once __DIR__ . '/includes/booking-poller.php';
require_once __DIR__ . '/includes/intelligent-polling-manager.php';
require_once __DIR__ . '/includes/database-optimizer.php';
require_once __DIR__ . '/includes/realtime-dashboard.php';
require_once __DIR__ . '/includes/automated-reporting.php';
require_once __DIR__ . '/includes/google-ads-enhanced.php';
require_once __DIR__ . '/includes/circuit-breaker.php';
require_once __DIR__ . '/includes/enterprise-management-suite.php';
require_once __DIR__ . '/includes/helpers-logging.php';
require_once __DIR__ . '/includes/helpers-tracking.php';
require_once __DIR__ . '/includes/helpers-scheduling.php';
require_once __DIR__ . '/includes/database.php';

// Log vendor autoloader status after all includes are loaded
if (!$vendor_available) {
    Helpers\hic_log('HIC Plugin: vendor/autoload.php non trovato, utilizzando caricamento manuale.', HIC_LOG_LEVEL_WARNING);
}

// Initialize helper hooks immediately after loading core files
Helpers\hic_init_helper_hooks();

// Plugin activation handler
function hic_activate($network_wide)
{
    if (\version_compare(PHP_VERSION, HIC_MIN_PHP_VERSION, '<') || \version_compare(\get_bloginfo('version'), HIC_MIN_WP_VERSION, '<')) {
        \deactivate_plugins(\plugin_basename(__FILE__));
        \wp_die(\sprintf(\__('Richiede almeno PHP %s e WordPress %s', 'hotel-in-cloud'), HIC_MIN_PHP_VERSION, HIC_MIN_WP_VERSION));
    }
    if ($network_wide) {
        $sites = \get_sites();
        foreach ($sites as $site) {
            \switch_to_blog($site->blog_id);
            \hic_maybe_upgrade_db();
            \FpHic\CircuitBreaker\CircuitBreakerManager::activate();
            $role = \get_role('administrator');
            if ($role) {
                if (!$role->has_cap('hic_manage')) {
                    $role->add_cap('hic_manage');
                }
                if (!$role->has_cap('hic_view_logs')) {
                    $role->add_cap('hic_view_logs');
                }
            }
            \restore_current_blog();
        }
    } else {
        \hic_maybe_upgrade_db();
        \FpHic\CircuitBreaker\CircuitBreakerManager::activate();
        $role = \get_role('administrator');
        if ($role) {
            if (!$role->has_cap('hic_manage')) {
                $role->add_cap('hic_manage');
            }
            if (!$role->has_cap('hic_view_logs')) {
                $role->add_cap('hic_view_logs');
            }
        }
    }

    // Clear potential orphaned scheduled events
    \wp_clear_scheduled_hook('hic_db_database_optimization');
    \wp_clear_scheduled_hook('hic_reconciliation');
    \wp_clear_scheduled_hook('hic_capture_tracking_params');

    $log_dir = WP_CONTENT_DIR . '/uploads/hic-logs';
    $dir_ok  = true;
    if (!file_exists($log_dir)) {
        if (function_exists('wp_mkdir_p')) {
            $dir_ok = \wp_mkdir_p($log_dir);
        } else {
            $dir_ok = \mkdir($log_dir, 0755, true);
        }
        if (!$dir_ok) {
            $error = \error_get_last();
            \hic_log(
                \sprintf(
                    'Impossibile creare la cartella dei log %s: %s',
                    $log_dir,
                    $error['message'] ?? 'errore sconosciuto'
                ),
                HIC_LOG_LEVEL_ERROR
            );
            \add_action('admin_notices', function () use ($log_dir) {
                echo '<div class="notice notice-error"><p>' .
                    \esc_html(
                        \sprintf(
                            \__('Impossibile creare la cartella dei log %s. Verifica i permessi.', 'hotel-in-cloud'),
                            $log_dir
                        )
                    ) .
                    '</p></div>';
            });
        }
    } else {
        $dir_ok = \is_dir($log_dir);
    }

    if ($dir_ok) {
        $htaccess = $log_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            $content = "Order allow,deny\nDeny from all\n";
            if (false === \file_put_contents($htaccess, $content)) {
                $error = \error_get_last();
                \hic_log(
                    \sprintf(
                        'Impossibile creare il file %s: %s',
                        $htaccess,
                        $error['message'] ?? 'errore sconosciuto'
                    ),
                    HIC_LOG_LEVEL_ERROR
                );
                \add_action('admin_notices', function () use ($htaccess) {
                    echo '<div class="notice notice-error"><p>' .
                        \esc_html(
                            \sprintf(
                                \__('Impossibile creare il file %s. Verifica i permessi.', 'hotel-in-cloud'),
                                $htaccess
                            )
                        ) .
                        '</p></div>';
                });
            }
        }
    }
}

// Plugin activation hook
\register_activation_hook(__FILE__, __NAMESPACE__ . '\\hic_activate');
\register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\hic_deactivate');
\add_action('plugins_loaded', '\\hic_maybe_upgrade_db');

// Add settings link in plugin list
\add_filter('plugin_action_links_' . \plugin_basename(__FILE__), function ($links) {
    $settings_link = '<a href="' . \admin_url('admin.php?page=hic-monitoring') . '">' . \__('Impostazioni', 'hotel-in-cloud') . '</a>';
    \array_unshift($links, $settings_link);
    return $links;
});

// Initialize tracking parameters capture
\add_action('init', function () {
    if (
        ! \is_admin()
        && ! \wp_doing_cron()
        && ! \wp_doing_ajax()
        && ! ( \defined('REST_REQUEST') && \REST_REQUEST )
        && ( ! \defined('WP_CLI') || ! \WP_CLI )
    ) {
        \hic_capture_tracking_params();
    }
});

// Initialize enhanced systems when WordPress is ready
\add_action('init', function() {
    // Load additional plugin files after WordPress is ready
    require_once __DIR__ . '/includes/booking-processor.php';
    require_once __DIR__ . '/includes/integrations/ga4.php';
    require_once __DIR__ . '/includes/integrations/gtm.php';
    require_once __DIR__ . '/includes/integrations/facebook.php';
    require_once __DIR__ . '/includes/integrations/brevo.php';
    require_once __DIR__ . '/includes/api/webhook.php';
    require_once __DIR__ . '/includes/api/polling.php';
    require_once __DIR__ . '/includes/cli.php';
    require_once __DIR__ . '/includes/config-validator.php';
    require_once __DIR__ . '/includes/performance-monitor.php';
    require_once __DIR__ . '/includes/health-monitor.php';

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
    if (Helpers\hic_get_tracking_mode() === 'gtm_only') {
        \wp_enqueue_script(
            'hic-frontend',
            \plugin_dir_url(__FILE__) . 'assets/js/frontend.js',
            array(),
            HIC_PLUGIN_VERSION,
            true
        );
    }
});

// Load admin functionality only in dashboard
if (\is_admin()) {
    require_once __DIR__ . '/includes/admin/admin-settings.php';
    require_once __DIR__ . '/includes/admin/diagnostics.php';
    require_once __DIR__ . '/includes/site-health.php';
    
    // Initialize admin-only classes that create submenus
    // This ensures the parent menu exists before submenus are added
    new \FpHic\GoogleAdsEnhanced\GoogleAdsEnhancedConversions();
    \FpHic\CircuitBreaker\hic_get_circuit_breaker_manager();
    new \FpHic\ReconAndSetup\EnterpriseManagementSuite();
    new \FpHic\AutomatedReporting\AutomatedReportingManager();

    if (!\wp_doing_cron()) {
        new \FpHic\RealtimeDashboard\RealtimeDashboard();
    }
}
