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
if (!function_exists('wp_schedule_event')) { function wp_schedule_event(...$args) { return true; } }
if (!function_exists('wp_unschedule_event')) { function wp_unschedule_event(...$args) { return true; } }
if (!function_exists('wp_clear_scheduled_hook')) { function wp_clear_scheduled_hook(...$args) { return true; } }
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
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($str) { return filter_var($str, FILTER_SANITIZE_STRING); } }
if (!function_exists('wp_date')) {
    function wp_date($format, $timestamp = null, $timezone = null) {
        return date($format, $timestamp ?? time());
    }
}
