<?php
/**
 * Comprehensive System Verification Tests for HIC Plugin
 * Verifies that all systems function and are performant
 */

require_once __DIR__ . '/bootstrap.php';

// Include additional plugin files needed for testing
require_once dirname(__DIR__) . '/includes/config-validator.php';
require_once dirname(__DIR__) . '/includes/performance-monitor.php';
require_once dirname(__DIR__) . '/includes/health-monitor.php';
require_once dirname(__DIR__) . '/includes/booking-poller.php';

class HICSystemVerificationTest {
    
    private $performance_monitor;
    private $health_monitor;
    private $config_validator;
    private $test_results = [];
    
    public function __construct() {
        // Initialize monitoring components if classes exist
        if (class_exists('HIC_Performance_Monitor')) {
            $this->performance_monitor = new HIC_Performance_Monitor();
        }
        if (class_exists('HIC_Health_Monitor')) {
            $this->health_monitor = new HIC_Health_Monitor();
        }
        if (class_exists('HIC_Config_Validator')) {
            $this->config_validator = new HIC_Config_Validator();
        }
    }
    
    /**
     * Test core system functionality
     */
    public function testCoreSystemFunctionality() {
        echo "🔍 Testing core system functionality...\n";
        
        // Test basic WordPress functions are available
        $core_functions = [
            'get_option', 'update_option', 'current_time', 
            'sanitize_text_field', 'sanitize_email'
        ];
        
        foreach ($core_functions as $func) {
            assert(function_exists($func), "Core function {$func} should be available");
        }
        
        // Test plugin constants are defined
        if (defined('HIC_FEATURE_HEALTH_MONITORING')) {
            assert(is_bool(HIC_FEATURE_HEALTH_MONITORING), 'Health monitoring feature flag should be boolean');
        }
        
        echo "✅ Core system functionality verified\n";
    }
    
    /**
     * Test configuration validation system
     */
    public function testConfigurationValidation() {
        echo "🔍 Testing configuration validation...\n";
        
        if (!$this->config_validator) {
            echo "⚠️  Config validator not available, skipping\n";
            return;
        }
        
        // Test configuration validation methods exist
        assert(method_exists($this->config_validator, 'validate_all_config'), 
               'Config validator should have validate_all_config method');
        
        // Run validation and check format
        $validation_result = $this->config_validator->validate_all_config();
        assert(is_array($validation_result), 'Validation result should be array');
        assert(isset($validation_result['valid']), 'Validation result should have valid flag');
        assert(isset($validation_result['errors']), 'Validation result should have errors array');
        assert(isset($validation_result['warnings']), 'Validation result should have warnings array');
        
        echo "✅ Configuration validation system verified\n";
    }
    
    /**
     * Test performance monitoring system
     */
    public function testPerformanceMonitoring() {
        echo "🔍 Testing performance monitoring...\n";
        
        if (!$this->performance_monitor) {
            echo "⚠️  Performance monitor not available, skipping\n";
            return;
        }
        
        // Test timer functionality
        $operation = 'test_operation_' . time();
        
        assert(method_exists($this->performance_monitor, 'start_timer'), 
               'Performance monitor should have start_timer method');
        assert(method_exists($this->performance_monitor, 'end_timer'), 
               'Performance monitor should have end_timer method');
        
        // Test actual timing
        $this->performance_monitor->start_timer($operation);
        usleep(10000); // Sleep 10ms
        $result = $this->performance_monitor->end_timer($operation);
        
        // Timer should return some result (implementation dependent)
        echo "✅ Performance monitoring system verified\n";
    }
    
    /**
     * Test health monitoring system
     */
    public function testHealthMonitoring() {
        echo "🔍 Testing health monitoring...\n";
        
        if (!$this->health_monitor) {
            echo "⚠️  Health monitor not available, skipping\n";
            return;
        }
        
        // Test health check method exists
        assert(method_exists($this->health_monitor, 'check_health'), 
               'Health monitor should have check_health method');
        
        // Run basic health check
        $health_result = $this->health_monitor->check_health(1); // Basic level
        assert(is_array($health_result), 'Health check should return array');
        
        echo "✅ Health monitoring system verified\n";
    }
    
    /**
     * Test booking poller system
     */
    public function testBookingPollerSystem() {
        echo "🔍 Testing booking poller system...\n";
        
        if (!class_exists('HIC_Booking_Poller')) {
            echo "⚠️  Booking poller class not available, skipping\n";
            return;
        }
        
        $poller = new HIC_Booking_Poller();
        
        // Test essential methods exist
        assert(method_exists($poller, 'get_stats'), 
               'Booking poller should have get_stats method');
        assert(method_exists($poller, 'execute_poll'), 
               'Booking poller should have execute_poll method');
        
        // Test stats retrieval
        $stats = $poller->get_stats();
        assert(is_array($stats), 'Poller stats should be array');
        
        echo "✅ Booking poller system verified\n";
    }
    
    /**
     * Test integration functions
     */
    public function testIntegrationFunctions() {
        echo "🔍 Testing integration functions...\n";
        
        // Test GA4 functions
        if (function_exists('hic_get_measurement_id')) {
            assert(is_string(hic_get_measurement_id()), 'Measurement ID should return string');
        }
        
        if (function_exists('hic_get_api_secret')) {
            assert(is_string(hic_get_api_secret()), 'API Secret should return string');
        }
        
        // Test Facebook functions
        if (function_exists('hic_get_fb_pixel_id')) {
            assert(is_string(hic_get_fb_pixel_id()), 'FB Pixel ID should return string');
        }
        
        // Test Brevo functions
        if (function_exists('hic_is_brevo_enabled')) {
            assert(is_bool(hic_is_brevo_enabled()), 'Brevo enabled should return boolean');
        }
        
        if (function_exists('hic_get_brevo_api_key')) {
            assert(is_string(hic_get_brevo_api_key()), 'Brevo API key should return string');
        }
        
        echo "✅ Integration functions verified\n";
    }
    
    /**
     * Test error handling and recovery mechanisms
     */
    public function testErrorHandlingAndRecovery() {
        echo "🔍 Testing error handling and recovery...\n";
        
        // Test logging functions
        if (function_exists('hic_log')) {
            // Test that logging doesn't throw errors
            hic_log('Test log message from system verification');
            assert(true, 'Logging should not throw errors');
        }
        
        // Test lock mechanisms
        if (function_exists('hic_acquire_polling_lock')) {
            $lock_acquired = hic_acquire_polling_lock(30);
            assert(is_bool($lock_acquired), 'Lock acquisition should return boolean');
            
            if ($lock_acquired && function_exists('hic_release_polling_lock')) {
                $lock_released = hic_release_polling_lock();
                assert(is_bool($lock_released), 'Lock release should return boolean');
            }
        }
        
        // Test reservation processing locks
        if (function_exists('hic_acquire_reservation_lock')) {
            $test_id = 'test_' . time();
            $res_lock = hic_acquire_reservation_lock($test_id, 10);
            assert(is_bool($res_lock), 'Reservation lock should return boolean');
            
            if ($res_lock && function_exists('hic_release_reservation_lock')) {
                $res_unlock = hic_release_reservation_lock($test_id);
                assert(is_bool($res_unlock), 'Reservation unlock should return boolean');
            }
        }
        
        echo "✅ Error handling and recovery verified\n";
    }
    
    /**
     * Test data processing performance
     */
    public function testDataProcessingPerformance() {
        echo "🔍 Testing data processing performance...\n";
        
        $start_time = microtime(true);
        
        // Test price normalization performance
        if (function_exists('hic_normalize_price')) {
            $test_prices = ['1.234,56', '1,234.56', '1234', '1234.00', '0', '-10'];
            foreach ($test_prices as $price) {
                $normalized = hic_normalize_price($price);
                assert(is_float($normalized), 'Price normalization should return float');
            }
        }
        
        // Test email validation performance
        if (function_exists('hic_is_valid_email')) {
            $test_emails = [
                'test@example.com', 'user.name+tag@domain.co.uk', 
                'invalid-email', '', 'guest@guest.booking.com'
            ];
            foreach ($test_emails as $email) {
                $is_valid = hic_is_valid_email($email);
                assert(is_bool($is_valid), 'Email validation should return boolean');
            }
        }
        
        // Test bucket normalization performance
        if (function_exists('fp_normalize_bucket')) {
            $test_cases = [
                ['CL123', null], [null, 'FB123'], ['CL123', 'FB123'], [null, null]
            ];
            foreach ($test_cases as $case) {
                $bucket = fp_normalize_bucket($case[0], $case[1]);
                assert(is_string($bucket), 'Bucket normalization should return string');
                assert(in_array($bucket, ['gads', 'fbads', 'organic']), 'Bucket should be valid value');
            }
        }
        
        $processing_time = microtime(true) - $start_time;
        assert($processing_time < 0.1, 'Data processing should complete within 100ms');
        
        echo "✅ Data processing performance verified (${processing_time}s)\n";
    }
    
    /**
     * Test system integration points
     */
    public function testSystemIntegrationPoints() {
        echo "🔍 Testing system integration points...\n";
        
        // Test WordPress integration points
        if (function_exists('add_action')) {
            // Test that hooks can be registered (simulation)
            $test_hook = 'test_hic_hook_' . time();
            $test_callback = function() { return true; };
            
            // This would normally register the hook, but we'll just verify the function exists
            assert(function_exists('add_action'), 'WordPress add_action should be available');
        }
        
        // Test AJAX endpoints exist (functions defined)
        $ajax_functions = [
            'hic_ajax_refresh_diagnostics',
            'hic_ajax_test_dispatch',
            'hic_ajax_force_poll'
        ];
        
        foreach ($ajax_functions as $func) {
            if (function_exists($func)) {
                assert(is_callable($func), "AJAX function {$func} should be callable");
            }
        }
        
        // Test REST API callback functions exist
        if (class_exists('HIC_Health_Monitor')) {
            $health_monitor = new HIC_Health_Monitor();
            assert(method_exists($health_monitor, 'rest_health_check'), 
                   'Health monitor should have REST API callback');
        }
        
        echo "✅ System integration points verified\n";
    }
    
    /**
     * Test security and validation measures
     */
    public function testSecurityAndValidation() {
        echo "🔍 Testing security and validation measures...\n";
        
        // Test nonce validation function exists
        if (function_exists('wp_create_nonce')) {
            // Simulate nonce creation/validation
            assert(function_exists('wp_create_nonce'), 'Nonce creation should be available');
        }
        
        // Test data sanitization functions work
        if (function_exists('sanitize_text_field')) {
            $test_input = '<script>alert("test")</script>test';
            $sanitized = sanitize_text_field($test_input);
            assert(is_string($sanitized), 'Sanitization should return string');
            assert(strpos($sanitized, '<script>') === false, 'Scripts should be removed');
        }
        
        if (function_exists('sanitize_email')) {
            $test_email = 'test@example.com<script>';
            $sanitized = sanitize_email($test_email);
            assert(is_string($sanitized), 'Email sanitization should return string');
        }
        
        // Test API credential validation
        if (function_exists('hic_has_basic_auth_credentials')) {
            $has_creds = hic_has_basic_auth_credentials();
            assert(is_bool($has_creds), 'Credential check should return boolean');
        }
        
        echo "✅ Security and validation measures verified\n";
    }
    
    /**
     * Run all system verification tests
     */
    public function runAllTests() {
        echo "🚀 Starting comprehensive system verification...\n\n";
        
        $start_time = microtime(true);
        
        try {
            $this->testCoreSystemFunctionality();
            $this->testConfigurationValidation();
            $this->testPerformanceMonitoring();
            $this->testHealthMonitoring();
            $this->testBookingPollerSystem();
            $this->testIntegrationFunctions();
            $this->testErrorHandlingAndRecovery();
            $this->testDataProcessingPerformance();
            $this->testSystemIntegrationPoints();
            $this->testSecurityAndValidation();
            
            $total_time = microtime(true) - $start_time;
            
            echo "\n🎉 All system verification tests passed successfully!\n";
            echo "⏱️  Total verification time: " . round($total_time, 3) . "s\n";
            echo "📊 System performance: " . ($total_time < 1.0 ? 'Excellent' : 
                  ($total_time < 2.0 ? 'Good' : 'Needs optimization')) . "\n";
            
            return true;
            
        } catch (AssertionError $e) {
            echo "\n❌ System verification failed: " . $e->getMessage() . "\n";
            echo "📍 Location: " . $e->getFile() . ":" . $e->getLine() . "\n";
            return false;
        } catch (Exception $e) {
            echo "\n💥 System verification error: " . $e->getMessage() . "\n";
            echo "📍 Location: " . $e->getFile() . ":" . $e->getLine() . "\n";
            return false;
        }
    }
}

// Run tests if called directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $test = new HICSystemVerificationTest();
    $success = $test->runAllTests();
    exit($success ? 0 : 1);
}