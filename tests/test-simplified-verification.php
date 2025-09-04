<?php
/**
 * Simplified System Performance and Function Verification Tests
 * Tests core functionality without requiring full WordPress environment
 */

require_once __DIR__ . '/bootstrap.php';

class HICSimplifiedSystemTest {
    
    private $test_results = [];
    
    /**
     * Test all core functions are available and working
     */
    public function testCoreFunctions() {
        echo "🔍 Testing core functions availability and performance...\n";
        
        $start_time = microtime(true);
        
        // Test configuration helper functions
        $config_functions = [
            'hic_get_measurement_id', 'hic_get_api_secret', 'hic_is_brevo_enabled',
            'hic_get_brevo_api_key', 'hic_get_fb_pixel_id', 'hic_get_connection_type',
            'hic_get_property_id', 'hic_get_api_email', 'hic_get_api_password'
        ];
        
        foreach ($config_functions as $func) {
            if (function_exists($func)) {
                $result = $func();
                assert(is_string($result) || is_bool($result), "Function {$func} should return string or boolean");
            }
        }
        
        // Test data processing functions
        if (function_exists('hic_normalize_price')) {
            $test_prices = ['1.234,56', '1,234.56', '1234', '0', '-10'];
            foreach ($test_prices as $price) {
                $normalized = hic_normalize_price($price);
                assert(is_float($normalized), 'Price normalization should return float');
                assert($normalized >= 0, 'Normalized price should be non-negative');
            }
        }
        
        if (function_exists('hic_is_valid_email')) {
            $valid_emails = ['test@example.com', 'user.name+tag@domain.co.uk'];
            $invalid_emails = ['invalid-email', '', 'test@'];
            
            foreach ($valid_emails as $email) {
                assert(hic_is_valid_email($email) === true, "Valid email {$email} should pass validation");
            }
            
            foreach ($invalid_emails as $email) {
                assert(hic_is_valid_email($email) === false, "Invalid email {$email} should fail validation");
            }
        }
        
        if (function_exists('fp_normalize_bucket')) {
            assert(fp_normalize_bucket('CL123', null) === 'gads', 'Should return gads for gclid');
            assert(fp_normalize_bucket(null, 'FB123') === 'fbads', 'Should return fbads for fbclid');
            assert(fp_normalize_bucket('CL123', 'FB123') === 'gads', 'Should prioritize gads when both present');
            assert(fp_normalize_bucket(null, null) === 'organic', 'Should return organic when neither present');
        }
        
        $processing_time = microtime(true) - $start_time;
        assert($processing_time < 0.1, 'Core functions should process within 100ms');
        
        echo "✅ Core functions verified (${processing_time}s)\n";
    }
    
    /**
     * Test performance of data processing functions
     */
    public function testDataProcessingPerformance() {
        echo "🔍 Testing data processing performance...\n";
        
        $start_time = microtime(true);
        
        // Test price normalization performance with large dataset
        if (function_exists('hic_normalize_price')) {
            $test_prices = [];
            for ($i = 0; $i < 1000; $i++) {
                $test_prices[] = number_format(rand(1, 10000), 2, ',', '.');
            }
            
            $process_start = microtime(true);
            foreach ($test_prices as $price) {
                hic_normalize_price($price);
            }
            $process_time = microtime(true) - $process_start;
            
            assert($process_time < 0.5, 'Processing 1000 prices should take less than 500ms');
            echo "  💰 Price normalization: 1000 prices in " . round($process_time, 3) . "s\n";
        }
        
        // Test email validation performance
        if (function_exists('hic_is_valid_email')) {
            $test_emails = [];
            for ($i = 0; $i < 1000; $i++) {
                $test_emails[] = "user{$i}@example.com";
            }
            
            $process_start = microtime(true);
            foreach ($test_emails as $email) {
                hic_is_valid_email($email);
            }
            $process_time = microtime(true) - $process_start;
            
            assert($process_time < 0.3, 'Processing 1000 emails should take less than 300ms');
            echo "  📧 Email validation: 1000 emails in " . round($process_time, 3) . "s\n";
        }
        
        // Test bucket normalization performance
        if (function_exists('fp_normalize_bucket')) {
            $test_cases = [];
            for ($i = 0; $i < 1000; $i++) {
                $test_cases[] = [rand(0, 1) ? "CL{$i}" : null, rand(0, 1) ? "FB{$i}" : null];
            }
            
            $process_start = microtime(true);
            foreach ($test_cases as $case) {
                fp_normalize_bucket($case[0], $case[1]);
            }
            $process_time = microtime(true) - $process_start;
            
            assert($process_time < 0.2, 'Processing 1000 bucket normalizations should take less than 200ms');
            echo "  🎯 Bucket normalization: 1000 cases in " . round($process_time, 3) . "s\n";
        }
        
        $total_time = microtime(true) - $start_time;
        echo "✅ Data processing performance verified (${total_time}s total)\n";
    }
    
    /**
     * Test error handling and edge cases
     */
    public function testErrorHandling() {
        echo "🔍 Testing error handling and edge cases...\n";
        
        // Test price normalization with invalid inputs
        if (function_exists('hic_normalize_price')) {
            $invalid_prices = [null, '', 'abc', [], new stdClass()];
            foreach ($invalid_prices as $price) {
                $result = hic_normalize_price($price);
                assert($result === 0.0, 'Invalid price should return 0.0');
            }
        }
        
        // Test email validation with edge cases
        if (function_exists('hic_is_valid_email')) {
            $edge_cases = [null, false, 0, [], new stdClass()];
            foreach ($edge_cases as $email) {
                $result = hic_is_valid_email($email);
                assert($result === false, 'Invalid email types should return false');
            }
        }
        
        // Test bucket normalization with edge cases
        if (function_exists('fp_normalize_bucket')) {
            $edge_cases = [
                ['', ''], [0, 0], [false, false], [null, ''], ['', null]
            ];
            foreach ($edge_cases as $case) {
                $result = fp_normalize_bucket($case[0], $case[1]);
                assert($result === 'organic', 'Empty/invalid tracking IDs should return organic');
            }
        }
        
        // Test OTA email detection
        if (function_exists('hic_is_ota_alias_email')) {
            $ota_emails = [
                'guest123@guest.booking.com',
                'user@guest.airbnb.com',
                'test@expedia.com'
            ];
            foreach ($ota_emails as $email) {
                assert(hic_is_ota_alias_email($email) === true, "OTA email {$email} should be detected");
            }
            
            $regular_emails = ['user@gmail.com', 'test@hotel.com', 'info@mycompany.com'];
            foreach ($regular_emails as $email) {
                assert(hic_is_ota_alias_email($email) === false, "Regular email {$email} should not be OTA");
            }
        }
        
        echo "✅ Error handling and edge cases verified\n";
    }
    
    /**
     * Test system constants and configuration
     */
    public function testSystemConfiguration() {
        echo "🔍 Testing system configuration and constants...\n";
        
        // Test that essential constants are defined
        $required_constants = [
            'HIC_CONTINUOUS_POLLING_INTERVAL',
            'HIC_DEEP_CHECK_INTERVAL',
            'HIC_API_TIMEOUT',
            'HIC_LOG_MAX_SIZE',
            'HIC_BUCKET_GADS',
            'HIC_BUCKET_FBADS',
            'HIC_BUCKET_ORGANIC'
        ];
        
        foreach ($required_constants as $constant) {
            assert(defined($constant), "Constant {$constant} should be defined");
        }
        
        // Test that constants have reasonable values
        assert(HIC_CONTINUOUS_POLLING_INTERVAL > 0, 'Polling interval should be positive');
        assert(HIC_DEEP_CHECK_INTERVAL > HIC_CONTINUOUS_POLLING_INTERVAL, 'Deep check should be longer than continuous');
        assert(HIC_API_TIMEOUT > 0, 'API timeout should be positive');
        assert(HIC_LOG_MAX_SIZE > 1024, 'Log max size should be at least 1KB');
        
        // Test bucket constants
        assert(HIC_BUCKET_GADS === 'gads', 'GADS bucket constant should be correct');
        assert(HIC_BUCKET_FBADS === 'fbads', 'FBADS bucket constant should be correct');
        assert(HIC_BUCKET_ORGANIC === 'organic', 'Organic bucket constant should be correct');
        
        echo "✅ System configuration verified\n";
    }
    
    /**
     * Test lock and synchronization mechanisms
     */
    public function testLockMechanisms() {
        echo "🔍 Testing lock and synchronization mechanisms...\n";
        
        // Test polling lock functions
        if (function_exists('hic_acquire_polling_lock') && function_exists('hic_release_polling_lock')) {
            $lock_acquired = hic_acquire_polling_lock(30);
            assert(is_bool($lock_acquired), 'Lock acquisition should return boolean');
            
            if ($lock_acquired) {
                // Try to acquire again - should fail
                $second_lock = hic_acquire_polling_lock(30);
                assert($second_lock === false, 'Second lock acquisition should fail');
                
                // Release lock
                $lock_released = hic_release_polling_lock();
                assert(is_bool($lock_released), 'Lock release should return boolean');
                
                // Should be able to acquire again
                $third_lock = hic_acquire_polling_lock(30);
                assert($third_lock !== false, 'Lock acquisition after release should succeed');
                
                if ($third_lock) {
                    hic_release_polling_lock();
                }
            }
        }
        
        // Test reservation processing locks
        if (function_exists('hic_acquire_reservation_lock') && function_exists('hic_release_reservation_lock')) {
            $test_id = 'test_' . time();
            
            $res_lock = hic_acquire_reservation_lock($test_id, 10);
            assert(is_bool($res_lock), 'Reservation lock should return boolean');
            
            if ($res_lock) {
                // Try to acquire same reservation again - should fail
                $second_res_lock = hic_acquire_reservation_lock($test_id, 10);
                assert($second_res_lock === false, 'Second reservation lock should fail');
                
                // Release lock
                $res_unlock = hic_release_reservation_lock($test_id);
                assert(is_bool($res_unlock), 'Reservation unlock should return boolean');
            }
        }
        
        echo "✅ Lock mechanisms verified\n";
    }
    
    /**
     * Test memory usage and resource efficiency
     */
    public function testResourceEfficiency() {
        echo "🔍 Testing memory usage and resource efficiency...\n";
        
        $initial_memory = memory_get_usage(true);
        
        // Perform memory-intensive operations
        $large_dataset = [];
        for ($i = 0; $i < 10000; $i++) {
            $large_dataset[] = [
                'id' => $i,
                'email' => "user{$i}@example.com",
                'amount' => rand(100, 1000),
                'gclid' => rand(0, 1) ? "CL{$i}" : null,
                'fbclid' => rand(0, 1) ? "FB{$i}" : null
            ];
        }
        
        // Process the dataset
        $processed = 0;
        $start_time = microtime(true);
        
        foreach ($large_dataset as $item) {
            if (function_exists('hic_is_valid_email')) {
                hic_is_valid_email($item['email']);
            }
            if (function_exists('hic_normalize_price')) {
                hic_normalize_price($item['amount']);
            }
            if (function_exists('fp_normalize_bucket')) {
                fp_normalize_bucket($item['gclid'], $item['fbclid']);
            }
            $processed++;
        }
        
        $processing_time = microtime(true) - $start_time;
        $final_memory = memory_get_usage(true);
        $memory_used = $final_memory - $initial_memory;
        
        // Clean up
        unset($large_dataset);
        
        assert($processing_time < 2.0, 'Processing 10k items should take less than 2 seconds');
        assert($memory_used < 50 * 1024 * 1024, 'Memory usage should be less than 50MB');
        
        echo "  📊 Processed {$processed} items in " . round($processing_time, 3) . "s\n";
        echo "  🧠 Memory used: " . round($memory_used / 1024 / 1024, 2) . "MB\n";
        
        echo "✅ Resource efficiency verified\n";
    }
    
    /**
     * Run all simplified tests
     */
    public function runAllTests() {
        echo "🚀 Starting simplified system verification...\n\n";
        
        $start_time = microtime(true);
        
        try {
            $this->testCoreFunctions();
            $this->testDataProcessingPerformance();
            $this->testErrorHandling();
            $this->testSystemConfiguration();
            $this->testLockMechanisms();
            $this->testResourceEfficiency();
            
            $total_time = microtime(true) - $start_time;
            
            echo "\n🎉 All simplified system verification tests passed!\n";
            echo "⏱️  Total verification time: " . round($total_time, 3) . "s\n";
            echo "📊 Overall system performance: " . ($total_time < 3.0 ? 'Excellent' : 
                  ($total_time < 5.0 ? 'Good' : 'Needs optimization')) . "\n";
            echo "✅ All systems are functioning and performing well!\n";
            
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
    $test = new HICSimplifiedSystemTest();
    $success = $test->runAllTests();
    exit($success ? 0 : 1);
}