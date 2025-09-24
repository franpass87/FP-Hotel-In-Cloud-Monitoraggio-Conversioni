<?php declare(strict_types=1);

namespace FpHic;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle plugin uninstallation by removing plugin data, tables and scheduled tasks.
 */
function hic_uninstall_plugin(): void
{
    if (!defined('WP_UNINSTALL_PLUGIN')) {
        return;
    }

    $is_multisite = function_exists('is_multisite') && is_multisite();

    if ($is_multisite) {
        $sites = get_sites(['fields' => 'ids']);
        foreach ($sites as $site_id) {
            switch_to_blog((int) $site_id);
            try {
                hic_uninstall_for_site();
            } finally {
                restore_current_blog();
            }
        }
    } else {
        hic_uninstall_for_site();
    }

    if ($is_multisite) {
        hic_clear_network_options();
    }

    hic_remove_log_directory();
}

/**
 * Perform uninstallation cleanup for the current site.
 */
function hic_uninstall_for_site(): void
{
    hic_clear_plugin_options();
    hic_drop_plugin_tables();
    hic_clear_scheduled_events();
    hic_remove_role_capabilities();
}

/**
 * Delete plugin data from the network options table when running on multisite.
 */
function hic_clear_network_options(): void
{
    if (!function_exists('is_multisite') || !is_multisite()) {
        return;
    }

    global $wpdb;

    if (!$wpdb instanceof \wpdb) {
        return;
    }

    $site_meta_table = $wpdb->sitemeta;
    if ($site_meta_table === '') {
        return;
    }

    $prefixes = [
        'hic_',
        '_site_transient_hic_',
        '_site_transient_timeout_hic_',
    ];

    foreach ($prefixes as $prefix) {
        $like = $wpdb->esc_like($prefix) . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$site_meta_table} WHERE meta_key LIKE %s", $like));
    }
}

/**
 * Delete all plugin options and transients stored in the options table.
 */
function hic_clear_plugin_options(): void
{
    global $wpdb;

    if (!$wpdb instanceof \wpdb) {
        return;
    }

    $option_table = $wpdb->options;
    if ($option_table === '') {
        return;
    }

    $prefixes = [
        'hic_',
        '_transient_hic_',
        '_transient_timeout_hic_',
    ];

    foreach ($prefixes as $prefix) {
        $like = $wpdb->esc_like($prefix) . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$option_table} WHERE option_name LIKE %s", $like));
    }
}

/**
 * Drop all database tables created by the plugin for the current site.
 */
function hic_drop_plugin_tables(): void
{
    global $wpdb;

    if (!$wpdb instanceof \wpdb) {
        return;
    }

    $prefix = $wpdb->prefix . 'hic_';
    $like   = $wpdb->esc_like($prefix) . '%';

    $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));

    if (empty($tables)) {
        return;
    }

    foreach ($tables as $table_name) {
        if (!is_string($table_name)) {
            continue;
        }

        if (!preg_match('/^' . preg_quote($prefix, '/') . '[0-9a-z_]+$/i', $table_name)) {
            continue;
        }

        $wpdb->query("DROP TABLE IF EXISTS `{$table_name}`");
    }
}

/**
 * Clear scheduled cron events registered by the plugin.
 */
function hic_clear_scheduled_events(): void
{
    if (!function_exists('wp_clear_scheduled_hook')) {
        return;
    }

    $hooks = [
        'hic_process_retry_queue',
        'hic_check_circuit_breaker_recovery',
        'hic_health_monitor_event',
        'hic_continuous_poll_event',
        'hic_deep_check_event',
        'hic_cleanup_event',
        'hic_booking_events_cleanup',
        'hic_self_healing_recovery',
        'hic_retry_failed_brevo_notifications',
        'hic_retry_failed_requests',
        'hic_cleanup_failed_requests',
        'hic_daily_reconciliation',
        'hic_health_check',
        'hic_intelligent_poll_event',
        'hic_cleanup_connection_pool',
        'hic_cleanup_exports',
        'hic_refresh_dashboard_data',
        'hic_performance_cleanup',
        'hic_enhanced_conversions_batch_upload',
        'hic_daily_database_maintenance',
        'hic_weekly_database_optimization',
        'hic_reliable_poll_event',
        'hic_scheduler_restart',
        'hic_fallback_poll_event',
        'hic_db_database_optimization',
        'hic_reconciliation',
        'hic_capture_tracking_params',
        'hic_daily_report',
        'hic_weekly_report',
        'hic_monthly_report',
    ];

    foreach (array_unique($hooks) as $hook) {
        wp_clear_scheduled_hook($hook);
    }
}

/**
 * Remove custom capabilities added by the plugin.
 */
function hic_remove_role_capabilities(): void
{
    if (!function_exists('wp_roles')) {
        return;
    }

    $roles = wp_roles();
    if (!$roles instanceof \WP_Roles) {
        return;
    }

    foreach ($roles->role_objects as $role) {
        if (!$role instanceof \WP_Role) {
            continue;
        }

        $role->remove_cap('hic_manage');
        $role->remove_cap('hic_view_logs');
    }
}

/**
 * Remove the plugin log directory and its files.
 */
function hic_remove_log_directory(): void
{
    if (function_exists('trailingslashit')) {
        $log_dir = trailingslashit(WP_CONTENT_DIR) . 'uploads/hic-logs';
    } else {
        $log_dir = rtrim(WP_CONTENT_DIR, '/\\') . '/uploads/hic-logs';
    }

    if (!is_dir($log_dir)) {
        return;
    }

    $iterator = new \RecursiveDirectoryIterator($log_dir, \FilesystemIterator::SKIP_DOTS);
    $files    = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($files as $file_info) {
        /** @var \SplFileInfo $file_info */
        if ($file_info->isDir()) {
            @rmdir($file_info->getPathname());
        } else {
            @unlink($file_info->getPathname());
        }
    }

    @rmdir($log_dir);
}
