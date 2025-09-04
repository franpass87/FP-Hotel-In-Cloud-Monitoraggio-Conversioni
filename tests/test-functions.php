<?php
/**
 * Unit Tests for HIC Core Functions
 */

require_once __DIR__ . '/bootstrap.php';

class HICFunctionsTest {
    
    public function testBucketNormalization() {
        // Test with gclid only
        $result = fp_normalize_bucket('CL123456', null);
        assert($result === 'gads', 'Should return "gads" when gclid is present');
        
        // Test with fbclid only  
        $result = fp_normalize_bucket(null, 'FB123456');
        assert($result === 'fbads', 'Should return "fbads" when only fbclid is present');
        
        // Test with both (gclid should take priority)
        $result = fp_normalize_bucket('CL123456', 'FB123456');
        assert($result === 'gads', 'Should return "gads" when both are present (priority)');
        
        // Test with neither
        $result = fp_normalize_bucket(null, null);
        assert($result === 'organic', 'Should return "organic" when neither is present');
        
        // Test with empty strings
        $result = fp_normalize_bucket('', '');
        assert($result === 'organic', 'Should return "organic" for empty strings');
        
        echo "✅ Bucket normalization tests passed\n";
    }
    
    public function testEmailValidation() {
        // Test valid emails
        assert(hic_is_valid_email('test@example.com') === true, 'Valid email should pass');
        assert(hic_is_valid_email('user.name+tag@domain.co.uk') === true, 'Complex valid email should pass');
        
        // Test invalid emails
        assert(hic_is_valid_email('invalid-email') === false, 'Invalid email should fail');
        assert(hic_is_valid_email('') === false, 'Empty email should fail');
        assert(hic_is_valid_email(null) === false, 'Null email should fail');
        
        echo "✅ Email validation tests passed\n";
    }
    
    public function testOTAEmailDetection() {
        // Test OTA alias emails
        assert(hic_is_ota_alias_email('guest123@guest.booking.com') === true, 'Booking.com alias should be detected');
        assert(hic_is_ota_alias_email('user@guest.airbnb.com') === true, 'Airbnb alias should be detected');
        assert(hic_is_ota_alias_email('test@expedia.com') === true, 'Expedia alias should be detected');
        
        // Test normal emails
        assert(hic_is_ota_alias_email('user@gmail.com') === false, 'Gmail should not be detected as OTA');
        assert(hic_is_ota_alias_email('user@hotel.com') === false, 'Hotel domain should not be detected as OTA');
        
        echo "✅ OTA email detection tests passed\n";
    }
    
    public function testConfigurationHelpers() {
        // Test that configuration functions return expected types
        assert(is_string(hic_get_measurement_id()), 'Measurement ID should return string');
        assert(is_string(hic_get_api_secret()), 'API Secret should return string');
        assert(is_bool(hic_is_brevo_enabled()), 'Brevo enabled should return boolean');
        assert(is_bool(hic_is_debug_verbose()), 'Debug verbose should return boolean');
        
        echo "✅ Configuration helper tests passed\n";
    }
    
    public function runAll() {
        echo "Running HIC Plugin Tests...\n\n";
        
        try {
            $this->testBucketNormalization();
            $this->testEmailValidation();
            $this->testOTAEmailDetection();
            $this->testConfigurationHelpers();
            
            echo "\n🎉 All tests passed successfully!\n";
        } catch (AssertionError $e) {
            echo "\n❌ Test failed: " . $e->getMessage() . "\n";
            exit(1);
        } catch (Exception $e) {
            echo "\n💥 Test error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}

// Run tests if called directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $test = new HICFunctionsTest();
    $test->runAll();
}