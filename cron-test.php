<?php
/**
 * HIC Cron Test Script
 * 
 * This file can be used to test if the system cron is working properly.
 * Usage: wget -q -O - "https://yoursite.com/wp-content/plugins/FP-Hotel-In-Cloud-Monitoraggio-Conversioni/cron-test.php"
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    // Include WordPress if not already loaded
    $wp_load = dirname(__FILE__) . '/../../../wp-load.php';
    if (file_exists($wp_load)) {
        require_once($wp_load);
    } else {
        die('WordPress not found');
    }
}

// Check if plugin is active
if (!function_exists('hic_log')) {
    die('HIC Plugin not active');
}

// Log the cron test execution
hic_log('Cron test script executed - system cron is working');

// Output for cron monitoring (optional)
echo "HIC Cron Test OK - " . date('Y-m-d H:i:s');
?>