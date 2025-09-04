<?php
/**
 * Bootstrap file for HIC Plugin Tests
 */

// Define WordPress test environment
define('ABSPATH', dirname(__DIR__) . '/');
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir());
}

// Include WordPress test functions if available
if (file_exists('/tmp/wordpress-tests-lib/includes/functions.php')) {
    require_once '/tmp/wordpress-tests-lib/includes/functions.php';
}

// Mock WordPress functions for basic testing
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        static $options = [];
        return isset($options[$option]) ? $options[$option] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        static $options = [];
        $options[$option] = $value;
        return true;
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        return date($type === 'mysql' ? 'Y-m-d H:i:s' : 'U');
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return filter_var($str, FILTER_SANITIZE_STRING);
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }
}

if (!function_exists('is_email')) {
    function is_email($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false;
    }
}

if (!function_exists('absint')) {
    function absint($maybeint) {
        return abs(intval($maybeint));
    }
}

// Include the plugin files
require_once dirname(__DIR__) . '/includes/functions.php';