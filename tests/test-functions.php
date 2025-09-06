<?php
/**
 * Unit Tests for HIC Core Functions
 */

require_once __DIR__ . '/bootstrap.php';

use FpHic\Helpers as Helpers;

class HICFunctionsTest {
    
    public function testBucketNormalization() {
        // Test with gclid only
        $result = Helpers\fp_normalize_bucket('CL123456', null);
        assert($result === 'gads', 'Should return "gads" when gclid is present');
        
        // Test with fbclid only  
        $result = Helpers\fp_normalize_bucket(null, 'FB123456');
        assert($result === 'fbads', 'Should return "fbads" when only fbclid is present');
        
        // Test with both (gclid should take priority)
        $result = Helpers\fp_normalize_bucket('CL123456', 'FB123456');
        assert($result === 'gads', 'Should return "gads" when both are present (priority)');
        
        // Test with neither
        $result = Helpers\fp_normalize_bucket(null, null);
        assert($result === 'organic', 'Should return "organic" when neither is present');
        
        // Test with empty strings
        $result = Helpers\fp_normalize_bucket('', '');
        assert($result === 'organic', 'Should return "organic" for empty strings');
        
        echo "✅ Bucket normalization tests passed\n";
    }
    
    public function testEmailValidation() {
        // Test valid emails
        assert(Helpers\hic_is_valid_email('test@example.com') === true, 'Valid email should pass');
        assert(Helpers\hic_is_valid_email('user.name+tag@domain.co.uk') === true, 'Complex valid email should pass');
        
        // Test invalid emails
        assert(Helpers\hic_is_valid_email('invalid-email') === false, 'Invalid email should fail');
        assert(Helpers\hic_is_valid_email('') === false, 'Empty email should fail');
        assert(Helpers\hic_is_valid_email(null) === false, 'Null email should fail');
        
        echo "✅ Email validation tests passed\n";
    }
    
    public function testGTMHelperFunctions() {
        // Configure options for testing
        update_option('hic_gtm_enabled', '1');
        update_option('hic_gtm_container_id', 'GTM-TEST123');
        update_option('hic_tracking_mode', 'hybrid');

        // Test GTM helper functions
        assert(Helpers\hic_is_gtm_enabled() === true, 'GTM should be enabled when option is "1"');
        assert(Helpers\hic_get_gtm_container_id() === 'GTM-TEST123', 'Should return correct GTM container ID');
        assert(Helpers\hic_get_tracking_mode() === 'hybrid', 'Should return correct tracking mode');
        
        echo "✅ GTM helper function tests passed\n";
    }

    public function testPriceNormalization() {
        // European format with thousands separator
        assert(abs(Helpers\hic_normalize_price('1.234,56') - 1234.56) < 0.001, 'Should handle European format with thousands');

        // US format with thousands separator
        assert(abs(Helpers\hic_normalize_price('1,234.56') - 1234.56) < 0.001, 'Should handle US format with thousands');

        // Plain integer and decimal
        assert(abs(Helpers\hic_normalize_price('1234') - 1234.0) < 0.001, 'Should handle integer string');
        assert(abs(Helpers\hic_normalize_price('1234.00') - 1234.0) < 0.001, 'Should handle decimal string');

        // Negative value should return 0
        assert(Helpers\hic_normalize_price('-10') === 0.0, 'Negative price should return 0');

        echo "✅ Price normalization tests passed\n";
    }
    
    public function testOTAEmailDetection() {
        // Test OTA alias emails
        assert(Helpers\hic_is_ota_alias_email('guest123@guest.booking.com') === true, 'Booking.com alias should be detected');
        assert(Helpers\hic_is_ota_alias_email('user@guest.airbnb.com') === true, 'Airbnb alias should be detected');
        assert(Helpers\hic_is_ota_alias_email('test@expedia.com') === true, 'Expedia alias should be detected');
        
        // Test normal emails
        assert(Helpers\hic_is_ota_alias_email('user@gmail.com') === false, 'Gmail should not be detected as OTA');
        assert(Helpers\hic_is_ota_alias_email('user@hotel.com') === false, 'Hotel domain should not be detected as OTA');
        
        echo "✅ OTA email detection tests passed\n";
    }
    
    public function testConfigurationHelpers() {
        // Test that configuration functions return expected types
        assert(is_string(Helpers\hic_get_measurement_id()), 'Measurement ID should return string');
        assert(is_string(Helpers\hic_get_api_secret()), 'API Secret should return string');
        assert(is_bool(Helpers\hic_is_brevo_enabled()), 'Brevo enabled should return boolean');
        assert(is_bool(Helpers\hic_is_debug_verbose()), 'Debug verbose should return boolean');

        echo "✅ Configuration helper tests passed\n";
    }

    public function testReservationPhoneFallback() {
        if (!function_exists('add_action')) {
            function add_action(...$args) {}
        }
        require_once dirname(__DIR__) . '/includes/api/polling.php';

        $res = \FpHic\hic_transform_reservation(['whatsapp' => '12345']);
        assert($res['phone'] === '12345', 'Should use whatsapp when phone is missing');

        $res2 = \FpHic\hic_transform_reservation(['phone' => '67890', 'whatsapp' => '12345']);
        assert($res2['phone'] === '67890', 'Should prioritize phone over whatsapp');

        echo "✅ Reservation phone fallback tests passed\n";
    }

    public function testEventRoomNameFallback() {
        // Ensure required WordPress stubs exist
        if (!function_exists('home_url')) {
            function home_url() { return 'https://example.com'; }
        }
        if (!function_exists('wp_generate_uuid4')) {
            function wp_generate_uuid4() { return 'uuid-4'; }
        }
        if (!function_exists('wp_json_encode')) {
            function wp_json_encode($data) { return json_encode($data); }
        }
        if (!function_exists('wp_remote_post')) {
            function wp_remote_post($url, $args) {
                global $hic_last_post;
                $hic_last_post = ['url' => $url, 'args' => $args];
                return ['response' => ['code' => 200], 'body' => '{}'];
            }
        }
        if (!function_exists('wp_remote_retrieve_response_code')) {
            function wp_remote_retrieve_response_code($res) { return $res['response']['code'] ?? 0; }
        }
        if (!function_exists('wp_remote_retrieve_body')) {
            function wp_remote_retrieve_body($res) { return $res['body'] ?? ''; }
        }
        if (!function_exists('is_wp_error')) {
            function is_wp_error($thing) { return false; }
        }

        require_once dirname(__DIR__) . '/includes/integrations/ga4.php';
        require_once dirname(__DIR__) . '/includes/integrations/facebook.php';

        // Configure required options
        update_option('hic_measurement_id', 'G-TEST');
        update_option('hic_api_secret', 'secret');
        update_option('hic_fb_pixel_id', 'FBTEST');
        update_option('hic_fb_access_token', 'FBTOKEN');
        update_option('hic_log_file', sys_get_temp_dir() . '/hic-test.log');

        global $hic_last_post;

        // GA4 room name
        $data = ['room' => 'Camera Deluxe', 'currency' => 'EUR', 'amount' => 100];
        \FpHic\hic_send_to_ga4($data, null, null);
        $payload = json_decode($hic_last_post['args']['body'], true);
        assert($payload['events'][0]['params']['items'][0]['item_name'] === 'Camera Deluxe', 'GA4 should use room name');

        // GA4 accommodation_name fallback
        $data = ['accommodation_name' => 'Suite', 'currency' => 'EUR', 'amount' => 100];
        \FpHic\hic_send_to_ga4($data, null, null);
        $payload = json_decode($hic_last_post['args']['body'], true);
        assert($payload['events'][0]['params']['items'][0]['item_name'] === 'Suite', 'GA4 should use accommodation name');

        // GA4 default
        $data = ['currency' => 'EUR', 'amount' => 100];
        \FpHic\hic_send_to_ga4($data, null, null);
        $payload = json_decode($hic_last_post['args']['body'], true);
        assert($payload['events'][0]['params']['items'][0]['item_name'] === 'Prenotazione', 'GA4 should default to Prenotazione');

        // FB room name
        $data = ['email' => 'user@example.com', 'room' => 'Camera Deluxe', 'currency' => 'EUR', 'amount' => 100];
        \FpHic\hic_send_to_fb($data, null, null);
        $payload = json_decode($hic_last_post['args']['body'], true);
        assert($payload['data'][0]['custom_data']['content_name'] === 'Camera Deluxe', 'FB should use room name');

        // FB accommodation_name fallback
        $data = ['email' => 'user@example.com', 'accommodation_name' => 'Suite', 'currency' => 'EUR', 'amount' => 100];
        \FpHic\hic_send_to_fb($data, null, null);
        $payload = json_decode($hic_last_post['args']['body'], true);
        assert($payload['data'][0]['custom_data']['content_name'] === 'Suite', 'FB should use accommodation name');

        // FB default
        $data = ['email' => 'user@example.com', 'currency' => 'EUR', 'amount' => 100];
        \FpHic\hic_send_to_fb($data, null, null);
        $payload = json_decode($hic_last_post['args']['body'], true);
        assert($payload['data'][0]['custom_data']['content_name'] === 'Prenotazione', 'FB should default to Prenotazione');

        echo "✅ Event room name fallback tests passed\n";
    }
    
    public function runAll() {
        echo "Running HIC Plugin Tests...\n\n";
        
        try {
            $this->testBucketNormalization();
            $this->testEmailValidation();
            $this->testGTMHelperFunctions();
            $this->testPriceNormalization();
            $this->testOTAEmailDetection();
            $this->testConfigurationHelpers();
            $this->testReservationPhoneFallback();
            $this->testEventRoomNameFallback();

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