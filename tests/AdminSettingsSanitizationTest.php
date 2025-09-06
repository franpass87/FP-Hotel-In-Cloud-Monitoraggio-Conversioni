<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';

if (!function_exists('register_setting')) {
    $GLOBALS['hic_registered_settings'] = [];
    function register_setting($option_group, $option_name, $args = []) {
        global $hic_registered_settings;
        $hic_registered_settings[$option_name] = $args['sanitize_callback'] ?? null;
    }
}
if (!function_exists('add_settings_section')) { function add_settings_section(...$args) {} }
if (!function_exists('add_settings_field')) { function add_settings_field(...$args) {} }
if (!function_exists('rest_sanitize_boolean')) {
    function rest_sanitize_boolean($value) {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}

final class AdminSettingsSanitizationTest extends TestCase {
    protected function setUp(): void {
        global $hic_registered_settings, $hic_test_options;
        $hic_registered_settings = [];
        $hic_test_options = [];
        hic_settings_init();
    }

    public function testSanitizeTextField() {
        global $hic_registered_settings;
        $raw = '<script>alert(1)</script>';
        $sanitized = call_user_func($hic_registered_settings['hic_measurement_id'], $raw);
        update_option('hic_measurement_id', $sanitized);
        $this->assertSame('alert(1)', get_option('hic_measurement_id'));
    }

    public function testSanitizeInteger() {
        global $hic_registered_settings;
        $raw = '123abc';
        $sanitized = call_user_func($hic_registered_settings['hic_property_id'], $raw);
        update_option('hic_property_id', $sanitized);
        $this->assertSame(123, get_option('hic_property_id'));
    }

    public function testSanitizeBoolean() {
        global $hic_registered_settings;
        $raw = 'notbool';
        $sanitized = call_user_func($hic_registered_settings['hic_brevo_enabled'], $raw);
        update_option('hic_brevo_enabled', $sanitized);
        $this->assertFalse(get_option('hic_brevo_enabled'));
    }
}
