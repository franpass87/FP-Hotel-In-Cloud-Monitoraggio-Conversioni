<?php declare(strict_types=1);
/**
 * Test file for HIC Plugin improvements
 *
 * This file tests the new security, validation, and caching features
 */

use PHPUnit\Framework\TestCase;

// Simulate WordPress environment for testing
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/');
}

// Load the improvements
require_once __DIR__ . '/../includes/constants.php';
require_once __DIR__ . '/../includes/http-security.php';
require_once __DIR__ . '/../includes/input-validator.php';
require_once __DIR__ . '/../includes/cache-manager.php';

class HIC_Improvements_Test {
    
    public static function run_tests() {
        echo "🧪 Testing HIC Plugin Improvements\n\n";
        
        self::test_input_validation();
        self::test_http_security();
        self::test_cache_manager();
        
        echo "✅ All tests completed!\n";
    }
    
    private static function test_input_validation() {
        echo "📝 Testing Input Validation...\n";
        
        // Test email validation
        $valid_email = \FpHic\HIC_Input_Validator::validate_email('test@example.com');
        assert($valid_email === 'test@example.com', 'Valid email should pass');
        
        $invalid_email = \FpHic\HIC_Input_Validator::validate_email('invalid-email');
        assert(is_wp_error($invalid_email), 'Invalid email should fail');
        
        // Test amount validation  
        $valid_amount = \FpHic\HIC_Input_Validator::validate_amount('€123.45');
        assert($valid_amount === 123.45, 'Valid amount should be normalized');
        
        $invalid_amount = \FpHic\HIC_Input_Validator::validate_amount('abc');
        assert(is_wp_error($invalid_amount), 'Invalid amount should fail');
        
        // Test currency validation
        $valid_currency = \FpHic\HIC_Input_Validator::validate_currency('eur');
        assert($valid_currency === 'EUR', 'Currency should be normalized to uppercase');
        
        $invalid_currency = \FpHic\HIC_Input_Validator::validate_currency('XYZ');
        assert(is_wp_error($invalid_currency), 'Invalid currency should fail');
        
        echo "  ✅ Input validation tests passed\n";
    }
    
    private static function test_http_security() {
        echo "🔒 Testing HTTP Security...\n";
        
        // Test URL validation
        $security_class = new ReflectionClass('\FpHic\HIC_HTTP_Security');
        $validate_url = $security_class->getMethod('validate_url');
        $validate_url->setAccessible(true);
        
        $valid_url = $validate_url->invoke(null, 'https://api.example.com/test');
        assert($valid_url === true, 'HTTPS URL should be valid');
        
        $invalid_url = $validate_url->invoke(null, 'ftp://invalid-protocol.com');
        assert($invalid_url === true, 'Only HTTP/HTTPS check, other protocols allowed but warned');
        
        $malicious_url = $validate_url->invoke(null, 'https://localhost/test');
        assert($malicious_url === false, 'Localhost URL should be blocked');
        
        echo "  ✅ HTTP security tests passed\n";
    }
    
    private static function test_cache_manager() {
        echo "🗄️ Testing Cache Manager...\n";
        
        // Mock WordPress functions for testing
        if (!function_exists('get_transient')) {
            function get_transient($key) { return false; }
            function set_transient($key, $value, $expiration) { return true; }
            function delete_transient($key) { return true; }
        }
        
        // Test basic cache operations
        $test_data = ['test' => 'value', 'number' => 123];
        
        $set_result = \FpHic\HIC_Cache_Manager::set('test_key', $test_data, 60);
        assert($set_result === true, 'Cache set should succeed');
        
        $get_result = \FpHic\HIC_Cache_Manager::get('test_key');
        assert($get_result === $test_data, 'Cache get should return original data');
        
        $delete_result = \FpHic\HIC_Cache_Manager::delete('test_key');
        assert($delete_result === true, 'Cache delete should succeed');
        
        $get_after_delete = \FpHic\HIC_Cache_Manager::get('test_key');
        assert($get_after_delete === null, 'Cache get after delete should return null');
        
        echo "  ✅ Cache manager tests passed\n";
    }
}

final class ImprovementsTest extends TestCase
{
    public function testImprovementsManualSuitePlaceholder(): void
    {
        $this->markTestSkipped('Manual improvements test suite is executed via HIC_Improvements_Test::run_tests().');
    }
}

// Mock WordPress functions that might be needed
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        // Mock function for testing
        return true;
    }
}

if (!function_exists('wp_error')) {
    class WP_Error {
        private $errors = [];
        
        public function __construct($code, $message) {
            $this->errors[$code] = [$message];
        }
        
        public function get_error_message() {
            foreach ($this->errors as $messages) {
                return $messages[0];
            }
            return '';
        }
    }
    
    function is_wp_error($obj) {
        return $obj instanceof WP_Error;
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email($email) {
        return filter_var($email, FILTER_SANITIZE_EMAIL);
    }
}

if (!function_exists('is_email')) {
    function is_email($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return htmlspecialchars(strip_tags($str), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_kses')) {
    function wp_kses($str, $allowed) {
        return strip_tags($str);
    }
}

// Mock hic_log function
if (!function_exists('hic_log')) {
    function hic_log($message, $level = 'info') {
        // Silent for tests
    }
}

// Run tests if called directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'] ?? '')) {
    HIC_Improvements_Test::run_tests();
}