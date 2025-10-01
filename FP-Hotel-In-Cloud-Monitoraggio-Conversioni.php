<?php declare(strict_types=1);
/**
 * Plugin Name: FP HIC Monitor
 * Description: Monitoraggio conversioni Hotel in Cloud con tracciamento avanzato verso GA4, Meta CAPI e Brevo. Sistema sicuro enterprise-grade con cache intelligente e validazione input.
 * Version: 3.4.1
 * Author: Francesco Passeri
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: hotel-in-cloud
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace FpHic;

use FpHic\Bootstrap\Lifecycle;
use FpHic\Bootstrap\ModuleLoader;
use FpHic\Bootstrap\UpgradeManager;
use function FpHic\Helpers\hic_should_bootstrap_feature;

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('HIC_PLUGIN_BASENAME')) {
    define('HIC_PLUGIN_BASENAME', \plugin_basename(__FILE__));
}

if (!defined('HIC_S2S_DISABLE_LEGACY_WEBHOOK_ROUTE')) {
    define('HIC_S2S_DISABLE_LEGACY_WEBHOOK_ROUTE', true);
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

require_once __DIR__ . '/includes/bootstrap/module-loader.php';
require_once __DIR__ . '/includes/bootstrap/lifecycle.php';
require_once __DIR__ . '/includes/bootstrap/upgrade-manager.php';

$module_loader = ModuleLoader::instance(__DIR__);
$module_loader->loadCore();

UpgradeManager::register();

// Log vendor autoloader status after all includes are loaded
if (!$vendor_available) {
    Helpers\hic_log('HIC Plugin: vendor/autoload.php non trovato, utilizzando caricamento manuale.', HIC_LOG_LEVEL_WARNING);
}

// Initialize helper hooks immediately after loading core files
Helpers\hic_init_helper_hooks();

if (\function_exists('register_uninstall_hook')) {
    \register_uninstall_hook(__FILE__, __NAMESPACE__ . '\\hic_uninstall_plugin');
}

function hic_for_each_site(callable $callback): void
{
    Lifecycle::forEachSite($callback);
}

/**
 * Plugin activation handler.
 */
function hic_activate($network_wide)
{
    if (\version_compare(PHP_VERSION, HIC_MIN_PHP_VERSION, '<') || \version_compare(\get_bloginfo('version'), HIC_MIN_WP_VERSION, '<')) {
        \deactivate_plugins(\plugin_basename(__FILE__));
        \wp_die(\sprintf(\__('Richiede almeno PHP %s e WordPress %s', 'hotel-in-cloud'), HIC_MIN_PHP_VERSION, HIC_MIN_WP_VERSION));
    }

    Lifecycle::activate((bool) $network_wide);
}

// Plugin activation hook
\register_activation_hook(__FILE__, __NAMESPACE__ . '\\hic_activate');
\register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\hic_deactivate');
\add_action('plugins_loaded', '\\hic_maybe_upgrade_db');

// Add settings link in plugin list
\add_filter('plugin_action_links_' . \plugin_basename(__FILE__), function ($links) {
    $settings_link = '<a href="' . \admin_url('admin.php?page=hic-monitoring-settings') . '">' . \__('Impostazioni', 'hotel-in-cloud') . '</a>';
    \array_unshift($links, $settings_link);
    return $links;
});

/**
 * Ensure administrator roles keep the capabilities required to access the unified HIC Monitor menu.
 *
 * When the plugin is updated on an existing installation we can no longer rely on the activation hook
 * to grant the custom capabilities. Calling this helper on each request keeps the role configuration
 * consistent without requiring a manual reactivation.
 */
function hic_ensure_admin_capabilities(): void
{
    Lifecycle::ensureAdminCapabilities();
}

// Apply the capability synchronization immediately and on subsequent requests.
hic_ensure_admin_capabilities();
\add_action('init', __NAMESPACE__ . '\\hic_ensure_admin_capabilities');
\add_action('admin_init', __NAMESPACE__ . '\\hic_ensure_admin_capabilities');

Lifecycle::registerNetworkProvisioningHook();

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
\add_action('init', function () use ($module_loader): void {
    $module_loader->loadInit();

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
    $tracking_mode = Helpers\hic_get_tracking_mode();

    if (\in_array($tracking_mode, ['gtm_only', 'ga4_only', 'hybrid'], true)) {
        \wp_enqueue_script(
            'hic-frontend',
            \plugin_dir_url(__FILE__) . 'assets/js/frontend.js',
            array(),
            HIC_PLUGIN_VERSION,
            true
        );

        \wp_localize_script('hic-frontend', 'hicFrontend', [
            'gtmEnabled'        => Helpers\hic_is_gtm_enabled(),
            'gtmEventsEndpoint' => esc_url_raw(rest_url('hic/v1/gtm-events')),
        ]);
    }
});

// Ensure the Enterprise Management Suite hooks are available when enabled
if (hic_should_bootstrap_feature('enterprise_suite') && class_exists('FpHic\\ReconAndSetup\\EnterpriseManagementSuite')) {
    if (!did_action('hic_enterprise_management_suite_loaded')) {
        $GLOBALS['hic_enterprise_management_suite'] = new \FpHic\ReconAndSetup\EnterpriseManagementSuite();
        do_action('hic_enterprise_management_suite_loaded', $GLOBALS['hic_enterprise_management_suite']);
    }
}

// Ensure Google Ads Enhanced Conversions hooks are available when enabled
if (hic_should_bootstrap_feature('google_ads_enhanced') && class_exists('FpHic\\GoogleAdsEnhanced\\GoogleAdsEnhancedConversions')) {
    if (!isset($GLOBALS['hic_google_ads_enhanced']) || !($GLOBALS['hic_google_ads_enhanced'] instanceof \FpHic\GoogleAdsEnhanced\GoogleAdsEnhancedConversions)) {
        $GLOBALS['hic_google_ads_enhanced'] = new \FpHic\GoogleAdsEnhanced\GoogleAdsEnhancedConversions();
    }
}

// Load admin functionality only in dashboard
if (\is_admin()) {
    $module_loader->loadAdmin();
}

// Ensure the circuit breaker manager is initialized in every context
\FpHic\CircuitBreaker\hic_get_circuit_breaker_manager();

\FpHic\AutomatedReporting\AutomatedReportingManager::instance();

// Initialize the real-time dashboard only when enabled for the current context
if (hic_should_bootstrap_feature('realtime_dashboard') && class_exists('FpHic\\RealtimeDashboard\\RealtimeDashboard')) {
    if (!isset($GLOBALS['hic_realtime_dashboard']) || !($GLOBALS['hic_realtime_dashboard'] instanceof \FpHic\RealtimeDashboard\RealtimeDashboard)) {
        $GLOBALS['hic_realtime_dashboard'] = new \FpHic\RealtimeDashboard\RealtimeDashboard();
    }
}
