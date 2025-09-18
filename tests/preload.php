<?php
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir());
}
// Basic WordPress stubs for autoloaded files
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        $GLOBALS['hic_test_hooks'][$hook][] = $callback;
    }
}
// Basic filter system for testing
if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        $GLOBALS['hic_test_filters'][$hook][$priority][] = [
            'function' => $callback,
            'accepted_args' => $accepted_args
        ];
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) {
        if (empty($GLOBALS['hic_test_filters'][$hook])) {
            return $value;
        }

        ksort($GLOBALS['hic_test_filters'][$hook]);

        foreach ($GLOBALS['hic_test_filters'][$hook] as $callbacks) {
            foreach ($callbacks as $cb) {
                $params = array_merge([$value], array_slice($args, 0, $cb['accepted_args'] - 1));
                $value = \call_user_func_array($cb['function'], $params);
            }
        }

        return $value;
    }
}
if (!function_exists('register_activation_hook')) { function register_activation_hook(...$args) {} }
if (!function_exists('register_deactivation_hook')) { function register_deactivation_hook(...$args) {} }
if (!function_exists('wp_next_scheduled')) { function wp_next_scheduled(...$args) { return false; } }
if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = array()) {
        if (!empty($GLOBALS['wp_schedule_event_invalid'][$recurrence])) {
            return new WP_Error('invalid_schedule', 'Invalid schedule');
        }

        if (!isset($GLOBALS['wp_scheduled_events'])) {
            $GLOBALS['wp_scheduled_events'] = [];
        }

        $GLOBALS['wp_scheduled_events'][] = [
            'timestamp' => $timestamp,
            'recurrence' => $recurrence,
            'hook' => $hook,
            'args' => $args,
        ];

        return true;
    }
}
if (!function_exists('wp_unschedule_event')) { function wp_unschedule_event(...$args) { return true; } }
if (!function_exists('wp_clear_scheduled_hook')) { function wp_clear_scheduled_hook(...$args) { return true; } }
if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event(...$args) {
        $GLOBALS['wp_scheduled_events'][] = $args;
        return true;
    }
}
if (!function_exists('is_admin')) { function is_admin() { return false; } }
if (!function_exists('admin_url')) { function admin_url($path = '') { return $path; } }
if (!function_exists('wp_enqueue_script')) { function wp_enqueue_script(...$args) {} }
if (!function_exists('wp_enqueue_style')) { function wp_enqueue_style(...$args) {} }
if (!function_exists('wp_localize_script')) { function wp_localize_script(...$args) {} }
if (!function_exists('wp_add_inline_script')) { function wp_add_inline_script(...$args) {} }
if (!function_exists('do_action')) {
    function do_action($hook, ...$args) {
        if (!empty($GLOBALS['hic_test_hooks'][$hook])) {
            foreach ($GLOBALS['hic_test_hooks'][$hook] as $callback) {
                \call_user_func_array($callback, $args);
            }
        }
    }
}
if (!function_exists('wp_doing_cron')) { function wp_doing_cron() { return defined('HIC_TEST_DOING_CRON') && HIC_TEST_DOING_CRON; } }
if (!function_exists('is_ssl')) { function is_ssl() { return false; } }
if (!function_exists('wp_unslash')) { function wp_unslash($value) { return $value; } }
if (!function_exists('is_wp_error')) { function is_wp_error($thing) { return $thing instanceof \WP_Error; } }
if (!function_exists('wp_error')) { function wp_error($code = '', $message = '', $data = null) { return new \WP_Error($code, $message, $data); } }
if (!isset($GLOBALS['hic_test_transients'])) {
    $GLOBALS['hic_test_transients'] = [];
    $GLOBALS['hic_test_transient_expirations'] = [];
}

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        global $hic_test_transients, $hic_test_transient_expirations;

        if (!is_array($hic_test_transients)) {
            $hic_test_transients = [];
        }

        if (!is_array($hic_test_transient_expirations)) {
            $hic_test_transient_expirations = [];
        }

        if (!array_key_exists($transient, $hic_test_transients)) {
            return false;
        }

        $expires = $hic_test_transient_expirations[$transient] ?? 0;
        if (!empty($expires) && $expires > 0 && $expires < time()) {
            unset($hic_test_transients[$transient], $hic_test_transient_expirations[$transient]);
            return false;
        }

        return $hic_test_transients[$transient];
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration) {
        global $hic_test_transients, $hic_test_transient_expirations;

        if (!is_array($hic_test_transients)) {
            $hic_test_transients = [];
        }

        if (!is_array($hic_test_transient_expirations)) {
            $hic_test_transient_expirations = [];
        }

        $hic_test_transients[$transient] = $value;
        $hic_test_transient_expirations[$transient] = $expiration > 0 ? (time() + (int) $expiration) : 0;

        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        global $hic_test_transients, $hic_test_transient_expirations;

        if (is_array($hic_test_transients) && array_key_exists($transient, $hic_test_transients)) {
            unset($hic_test_transients[$transient]);
        }

        if (is_array($hic_test_transient_expirations) && array_key_exists($transient, $hic_test_transient_expirations)) {
            unset($hic_test_transient_expirations[$transient]);
        }

        return true;
    }
}

if (!function_exists('delete_option')) { function delete_option($option) { global $hic_test_options; unset($hic_test_options[$option]); return true; } }
if (!function_exists('wp_upload_dir')) { function wp_upload_dir($path = null) { return ['basedir' => sys_get_temp_dir(), 'baseurl' => '']; } }
if (!function_exists('plugin_basename')) { function plugin_basename($file) { return $file; } }
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
if (!function_exists('wp_date')) {
    function wp_date($format, $timestamp = null, $timezone = null) {
        return date($format, $timestamp ?? time());
    }
}
if (!function_exists('esc_sql')) {
    function esc_sql($sql) {
        return $sql;
    }
}
