<?php
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir());
}
// Basic WordPress stubs for autoloaded files
if (!function_exists('add_action')) { function add_action(...$args) {} }
if (!function_exists('add_filter')) { function add_filter(...$args) {} }
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
if (!function_exists('do_action')) { function do_action(...$args) {} }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($str) { return filter_var($str, FILTER_SANITIZE_STRING); } }
