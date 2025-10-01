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

if (!defined('HIC_S2S_DISABLE_LEGACY_WEBHOOK_ROUTE')) {
    define('HIC_S2S_DISABLE_LEGACY_WEBHOOK_ROUTE', true);
}

// Include WordPress test functions if available
if (file_exists('/tmp/wordpress-tests-lib/includes/functions.php')) {
    require_once '/tmp/wordpress-tests-lib/includes/functions.php';
}

// Mock WordPress options handling for basic testing
if (!function_exists('get_option')) {
    $GLOBALS['hic_test_options'] = [];
    $GLOBALS['hic_test_option_autoload'] = [];

    function get_option($option, $default = false) {
        global $hic_test_options;
        return array_key_exists($option, $hic_test_options) ? $hic_test_options[$option] : $default;
    }
}

if (!function_exists('add_option')) {
    function add_option($option, $value = '', $deprecated = '', $autoload = 'yes') {
        global $hic_test_options, $hic_test_option_autoload;

        if (!is_array($hic_test_options ?? null)) {
            $hic_test_options = [];
        }

        if (array_key_exists($option, $hic_test_options)) {
            return false;
        }

        $hic_test_options[$option] = $value;

        if (!is_array($hic_test_option_autoload ?? null)) {
            $hic_test_option_autoload = [];
        }
        $hic_test_option_autoload[$option] = $autoload;

        if (function_exists('do_action')) {
            do_action('add_option', $option, $value);
            do_action('added_option', $option, $value);
        }

        return true;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        global $hic_test_options, $hic_test_option_autoload;

        $option_exists = is_array($hic_test_options ?? null) && array_key_exists($option, $hic_test_options);

        if (!$option_exists) {
            return add_option($option, $value, '', $autoload);
        }

        $old_value = $hic_test_options[$option];
        $hic_test_options[$option] = $value;

        if (!is_array($hic_test_option_autoload ?? null)) {
            $hic_test_option_autoload = [];
        }
        $hic_test_option_autoload[$option] = $autoload;

        if (function_exists('do_action')) {
            do_action('update_option', $option, $value, $old_value);
            do_action('updated_option', $option, $old_value, $value);
        }

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option) {
        global $hic_test_options, $hic_test_option_autoload;

        unset($hic_test_options[$option], $hic_test_option_autoload[$option]);

        if (function_exists('do_action')) {
            do_action('delete_option', $option);
            do_action('deleted_option', $option);
        }

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
            $override = $GLOBALS['hic_test_current_time'];

            if (is_array($override)) {
                $key = null;

                if ($type === 'timestamp') {
                    $key = $gmt ? 'timestamp_gmt' : 'timestamp_local';
                } elseif ($type === 'mysql') {
                    $key = $gmt ? 'mysql_gmt' : 'mysql_local';
                }

                if ($key !== null && array_key_exists($key, $override)) {
                    return $override[$key];
                }

                if ($gmt && array_key_exists('gmt', $override)) {
                    return $override['gmt'];
                }

                if (!$gmt && array_key_exists('local', $override)) {
                    return $override['local'];
                }

                if (array_key_exists('value', $override)) {
                    return $override['value'];
                }
            }

            return $override;
        }

        if ($type === 'timestamp') {
            if ($gmt) {
                return time();
            }

            $offset = (float) get_option('gmt_offset', 0);
            return time() + (int) round($offset * 3600);
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

        if ($type === 'mysql') {
            return $datetime->format('Y-m-d H:i:s');
        }

        return $datetime->format('U');
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

if (!function_exists('wp_generate_password')) {
    function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) {
        $length = (int) $length;
        if ($length < 1) {
            $length = 12;
        }

        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $alphabet_length = strlen($alphabet);
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $index = random_int(0, $alphabet_length - 1);
            $password .= $alphabet[$index];
        }

        return $password;
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }

        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('add_settings_error')) {
    function add_settings_error($setting, $code, $message, $type = 'error') {
        $GLOBALS['hic_settings_errors'][] = compact('setting', 'code', 'message', 'type');
    }
}

if (!function_exists('is_email')) {
    function is_email($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false;
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4() {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
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
        if (isset($GLOBALS['hic_test_wp_remote_post']) && is_callable($GLOBALS['hic_test_wp_remote_post'])) {
            return call_user_func($GLOBALS['hic_test_wp_remote_post'], $url, $args);
        }

        return array('body' => '{}', 'response' => array('code' => 200));
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, $depth);
    }
}

if (!function_exists('wp_safe_remote_request')) {
    function wp_safe_remote_request($url, $args = array()) {
        global $hic_last_request, $hic_test_http_error, $hic_test_http_error_urls, $hic_test_http_mock;
        $hic_last_request = ['url' => $url, 'args' => $args];

        $preempt = apply_filters('pre_http_request', false, $args, $url);
        if ($preempt !== false) {
            return $preempt;
        }

        if (isset($hic_test_http_mock) && is_callable($hic_test_http_mock)) {
            $mock_response = call_user_func($hic_test_http_mock, $url, $args);
            if ($mock_response !== null) {
                return $mock_response;
            }
        }

        $should_fail = false;
        if (!empty($hic_test_http_error)) {
            $should_fail = true;
        } elseif (!empty($hic_test_http_error_urls) && is_array($hic_test_http_error_urls)) {
            foreach ($hic_test_http_error_urls as $pattern) {
                if (is_string($pattern) && strpos($url, $pattern) !== false) {
                    $should_fail = true;
                    break;
                }
                if (is_callable($pattern) && (bool) call_user_func($pattern, $url, $args)) {
                    $should_fail = true;
                    break;
                }
            }
        }

        if ($should_fail) {
            return new WP_Error('hic_test_http_error', 'Simulated HTTP failure');
        }

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

        public function get_error_data($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }

            return $this->error_data[$code] ?? null;
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
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null) {
        return esc_html($text);
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
        $normalized_route = '/' . trim($namespace, '/') . '/' . ltrim($route, '/');
        $normalized_route = preg_replace('#/+#', '/', $normalized_route ?? '');

        if (!isset($GLOBALS['hic_registered_rest_routes_store']) || !is_array($GLOBALS['hic_registered_rest_routes_store'])) {
            $GLOBALS['hic_registered_rest_routes_store'] = [];
        }

        $GLOBALS['hic_registered_rest_routes_store'][$normalized_route] = $args;

        if (function_exists('\\FpHic\\Helpers\\hic_register_rest_route_fallback')) {
            \FpHic\Helpers\hic_register_rest_route_fallback($namespace, $route, is_array($args) ? $args : []);
        }

        return true;
    }
}

if (!function_exists('rest_do_request')) {
    function rest_do_request($request)
    {
        if ($request instanceof WP_REST_Request) {
            $route = $request->get_route();
            $method = strtoupper($request->get_method());
        } elseif (is_string($request)) {
            $route = $request;
            $method = 'GET';
        } else {
            return new WP_Error('rest_invalid_request', 'Invalid REST request.');
        }

        $route = '/' . ltrim($route, '/');
        $route = preg_replace('#/+#', '/', $route ?? '');

        $routes = $GLOBALS['hic_registered_rest_routes_store'] ?? [];

        if (!isset($routes[$route])) {
            return new WP_Error('rest_no_route', 'No route found.', array('status' => 404));
        }

        $args = $routes[$route];
        $methods = $args['methods'] ?? 'GET';
        $methods = is_array($methods) ? $methods : array($methods);
        $methods = array_map('strtoupper', $methods);

        if (!in_array($method, $methods, true)) {
            return new WP_Error('rest_no_route', 'No route found for method.', array('status' => 404));
        }

        $callback = $args['callback'] ?? null;

        if (!is_callable($callback)) {
            return new WP_Error('rest_invalid_handler', 'Invalid route callback.', array('status' => 500));
        }

        return call_user_func($callback, $request);
    }
}

// Include the plugin files
require_once dirname(__DIR__) . '/includes/constants.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/helpers-logging.php';
require_once dirname(__DIR__) . '/includes/log-manager.php';
require_once dirname(__DIR__) . '/includes/booking-processor.php';
require_once dirname(__DIR__) . '/includes/rate-limiter.php';
require_once dirname(__DIR__) . '/includes/integrations/ga4.php';
require_once dirname(__DIR__) . '/includes/integrations/gtm.php';
require_once dirname(__DIR__) . '/includes/integrations/facebook.php';
require_once dirname(__DIR__) . '/includes/integrations/brevo.php';
