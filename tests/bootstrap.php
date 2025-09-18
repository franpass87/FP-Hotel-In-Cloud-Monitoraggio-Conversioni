<?php
/**
 * Bootstrap file for HIC Plugin Tests
 */

// Define WordPress test environment
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir());
}

// Include WordPress test functions if available
if (file_exists('/tmp/wordpress-tests-lib/includes/functions.php')) {
    require_once '/tmp/wordpress-tests-lib/includes/functions.php';
}

// Mock WordPress options handling for basic testing
if (!function_exists('get_option')) {
    $GLOBALS['hic_test_options'] = [];

    function get_option($option, $default = false) {
        global $hic_test_options;
        return array_key_exists($option, $hic_test_options) ? $hic_test_options[$option] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        global $hic_test_options;
        $hic_test_options[$option] = $value;
        return true;
    }
}

if (!class_exists('WP_UnitTestCase')) {
    class WP_UnitTestCase extends \PHPUnit\Framework\TestCase {}
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        // Allow tests to override the time
        if (isset($GLOBALS['hic_test_current_time'])) {
            return $GLOBALS['hic_test_current_time'];
        }

        $timezone_string = get_option('timezone_string', 'UTC');
        try {
            $timezone = new DateTimeZone($timezone_string);
        } catch (Exception $e) {
            $timezone = new DateTimeZone('UTC');
        }

        $datetime = new DateTime('now', $timezone);

        if ($gmt) {
            $datetime->setTimezone(new DateTimeZone('UTC'));
        }

        return $datetime->format($type === 'mysql' ? 'Y-m-d H:i:s' : 'U');
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        if (!is_scalar($str)) {
            return '';
        }

        $value = (string) $str;
        $value = strip_tags($value);
        $value = preg_replace('/[\r\n\t\0\x0B]+/', '', $value);
        if ($value === null) {
            $value = '';
        }

        return trim($value);
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

// Mock WordPress hook system for testing
if (!function_exists('add_action')) {
    function add_action($hook, $function_to_add, $priority = 10, $accepted_args = 1) {
        // In test environment, just return true
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $function_to_add, $priority = 10, $accepted_args = 1) {
        // In test environment, just return true
        return true;
    }
}

if (!function_exists('remove_action')) {
    function remove_action($hook, $function_to_remove, $priority = 10) {
        return true;
    }
}

if (!function_exists('do_action')) {
    function do_action($hook, ...$args) {
        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) {
        return $value;
    }
}

if (!function_exists('wp_doing_cron')) {
    function wp_doing_cron() {
        return false;
    }
}

if (!function_exists('is_admin')) {
    function is_admin() {
        return false;
    }
}

if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() {
        return (object)['ID' => 1, 'user_login' => 'testuser'];
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        return true; // For testing purposes
    }
}

// Mock HTTP functions
if (!function_exists('wp_http_validate_url')) {
    function wp_http_validate_url($url) {
        if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return $url; // Return the URL itself if valid
        }
        return false;
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = array()) {
        return array('body' => '{}', 'response' => array('code' => 200));
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = array()) {
        return array('body' => '{}', 'response' => array('code' => 200));
    }
}

if (!function_exists('wp_safe_remote_request')) {
    function wp_safe_remote_request($url, $args = array()) {
        global $hic_last_request;
        $hic_last_request = ['url' => $url, 'args' => $args];
        return array('body' => '{}', 'response' => array('code' => 200));
    }
}

if (!function_exists('wp_safe_remote_get')) {
    function wp_safe_remote_get($url, $args = array()) {
        return array('body' => '{}', 'response' => array('code' => 200));
    }
}

if (!function_exists('wp_safe_remote_post')) {
    function wp_safe_remote_post($url, $args = array()) {
        return array('body' => '{}', 'response' => array('code' => 200));
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        return isset($response['response']['code']) ? $response['response']['code'] : 200;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        return isset($response['body']) ? $response['body'] : '{}';
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

// Mock WP_Error class
if (!class_exists('WP_Error')) {
    class WP_Error {
        public $errors = array();
        public $error_data = array();
        
        public function __construct($code = '', $message = '', $data = '') {
            if (empty($code)) return;
            $this->errors[$code][] = $message;
            if (!empty($data)) $this->error_data[$code] = $data;
        }
        
        public function get_error_message($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            if (isset($this->errors[$code])) {
                return $this->errors[$code][0];
            }
            return '';
        }
        
        public function get_error_code() {
            $codes = array_keys($this->errors);
            if (empty($codes)) return '';
            return $codes[0];
        }
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return htmlspecialchars(strip_tags(trim($str)), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($data) {
        return strip_tags($data);
    }
}

// More WordPress scheduling functions
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

if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args = array()) {
        return true;
    }
}

// Include the plugin files
require_once dirname(__DIR__) . '/includes/constants.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/booking-processor.php';
