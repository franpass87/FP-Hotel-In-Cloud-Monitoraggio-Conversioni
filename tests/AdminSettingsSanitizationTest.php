<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/admin/admin-settings.php';

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
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action, $arg = false, $die = true) {
        return true;
    }
}
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data) {
        $response = ['success' => true, 'data' => $data];
        $GLOBALS['hic_last_json_response'] = $response;
        $GLOBALS['ajax_response'] = $response;
    }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data) {
        $response = ['success' => false, 'data' => $data];
        $GLOBALS['hic_last_json_response'] = $response;
        $GLOBALS['ajax_response'] = $response;
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

    public function testApiPasswordPreservesSpecialCharacters() {
        global $hic_registered_settings;
        $raw = 'p&ssw%rd<secure>';
        $sanitized = call_user_func($hic_registered_settings['hic_api_password'], $raw);
        update_option('hic_api_password', $sanitized);
        $this->assertSame('p&ssw%rd<secure>', get_option('hic_api_password'));
    }

    public function testHealthTokenSanitizeRequiresMinimumLength(): void {
        global $hic_registered_settings, $hic_settings_errors;
        $hic_settings_errors = [];

        update_option('hic_health_token', 'existingtokenvalueforhealthcheck123');

        $sanitized = call_user_func($hic_registered_settings['hic_health_token'], 'short');

        $this->assertSame('existingtokenvalueforhealthcheck123', $sanitized);
        $this->assertNotEmpty($hic_settings_errors);
        $this->assertSame('health_token_short', $hic_settings_errors[0]['code']);
    }

    public function testHealthTokenSanitizeStripsInvalidCharacters(): void {
        global $hic_registered_settings;

        $raw = '  token-VALID_value_1234567890!@#$  ';
        $sanitized = call_user_func($hic_registered_settings['hic_health_token'], $raw);

        $this->assertSame('token-VALID_value_1234567890', $sanitized);
    }

    public function testAjaxHealthTokenGeneration(): void {
        global $hic_last_json_response;

        $hic_last_json_response = null;
        $_POST['nonce'] = 'test';

        hic_ajax_generate_health_token();

        $this->assertIsArray($hic_last_json_response);
        $this->assertTrue($hic_last_json_response['success']);
        $this->assertArrayHasKey('token', $hic_last_json_response['data']);

        $token = $hic_last_json_response['data']['token'];

        $this->assertSame($token, get_option('hic_health_token'));
        $this->assertGreaterThanOrEqual(24, strlen($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);

        unset($_POST['nonce'], $GLOBALS['hic_last_json_response']);
    }

    public function testSecurityHeadersAppliedOnPluginPage(): void {
        $_GET['page'] = 'hic-monitoring-settings';
        $_SERVER['PHP_SELF'] = '/wp-admin/admin.php';

        $headers = hic_filter_admin_security_headers([]);

        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertArrayHasKey('Referrer-Policy', $headers);
        $this->assertArrayHasKey('Permissions-Policy', $headers);
        $this->assertArrayHasKey('Content-Security-Policy', $headers);

        unset($_GET['page'], $_SERVER['PHP_SELF']);
    }

    public function testSecurityHeadersNotAppliedOutsidePluginPages(): void {
        $_GET['page'] = 'another-page';
        $_SERVER['PHP_SELF'] = '/wp-admin/admin.php';

        $headers = hic_filter_admin_security_headers([]);

        $this->assertArrayNotHasKey('X-Frame-Options', $headers);

        unset($_GET['page'], $_SERVER['PHP_SELF']);
    }
}
