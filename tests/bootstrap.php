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

// Mock WordPress action/filter system for testing
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = array()) {
        return false;
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = array()) {
        return true;
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) {
        return 'test_nonce_' . md5($action);
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) {
        return true;
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args = array()) {
        return true;
    }
}

if (!function_exists('__return_true')) {
    function __return_true() {
        return true;
    }
}

if (!function_exists('wp_get_schedules')) {
    function wp_get_schedules() {
        return array(
            'hourly' => array('interval' => 3600, 'display' => 'Once Hourly'),
            'daily' => array('interval' => 86400, 'display' => 'Once Daily')
        );
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) {
        static $transients = array();
        $transients[$transient] = array('value' => $value, 'expires' => time() + $expiration);
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        static $transients = array();
        if (isset($transients[$transient])) {
            $data = $transients[$transient];
            if ($data['expires'] > time() || $data['expires'] === 0) {
                return $data['value'];
            }
            unset($transients[$transient]);
        }
        return false;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        static $transients = array();
        unset($transients[$transient]);
        return true;
    }
}

if (!function_exists('human_time_diff')) {
    function human_time_diff($from, $to = null) {
        if ($to === null) $to = time();
        $diff = abs($to - $from);
        if ($diff < 60) return $diff . ' seconds';
        if ($diff < 3600) return round($diff / 60) . ' minutes';
        if ($diff < 86400) return round($diff / 3600) . ' hours';
        return round($diff / 86400) . ' days';
    }
}

if (!function_exists('is_ssl')) {
    function is_ssl() {
        return false; // Mock as non-SSL for testing
    }
}

if (!function_exists('home_url')) {
    function home_url($path = '', $scheme = null) {
        return 'http://example.com' . $path;
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '', $scheme = 'admin') {
        return 'http://example.com/wp-admin/' . $path;
    }
}

if (!function_exists('rest_url')) {
    function rest_url($path = '', $scheme = 'rest') {
        return 'http://example.com/wp-json/' . $path;
    }
}

if (!function_exists('wp_unschedule_event')) {
    function wp_unschedule_event($timestamp, $hook, $args = array()) {
        return true;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook, $args = array()) {
        return true;
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) {
        return parse_url($url, $component);
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = array()) {
        return array('response' => array('code' => 200), 'body' => '{"success": true}');
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = array()) {
        return array('response' => array('code' => 200), 'body' => '{"success": true}');
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return false; // Mock as no errors for testing
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        return isset($response['response']['code']) ? $response['response']['code'] : 200;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        return isset($response['body']) ? $response['body'] : '';
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url, $protocols = null, $_context = 'display') {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '', $filter = 'raw') {
        switch ($show) {
            case 'version':
                return '6.0';
            case 'name':
                return 'Test Site';
            case 'url':
                return 'http://example.com';
            default:
                return '';
        }
    }
}

if (!function_exists('wp_mail')) {
    function wp_mail($to, $subject, $message, $headers = '', $attachments = array()) {
        return true; // Mock successful email sending
    }
}

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir($time = null, $create_dir = true, $refresh_cache = false) {
        return array(
            'path' => sys_get_temp_dir(),
            'url' => 'http://example.com/uploads',
            'subdir' => '',
            'basedir' => sys_get_temp_dir(),
            'baseurl' => 'http://example.com/uploads',
            'error' => false
        );
    }
}

if (!function_exists('wp_filesystem')) {
    function wp_filesystem() {
        return true;
    }
}

// Mock WordPress constants for testing
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir());
}

// Include the plugin constants first
require_once dirname(__DIR__) . '/includes/constants.php';

// Include the plugin files
require_once dirname(__DIR__) . '/includes/functions.php';