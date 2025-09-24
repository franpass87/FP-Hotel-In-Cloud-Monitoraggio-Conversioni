<?php
/**
 * Unit Tests for HIC Core Functions
 */

if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

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

    public function testPhoneLanguageDetection() {
        $res = Helpers\hic_detect_phone_language('+39 333 1234567');
        assert($res['language'] === 'it', 'Should detect Italian prefix');
        assert($res['phone'] === '+393331234567', 'Should normalize Italian phone');

        $res = Helpers\hic_detect_phone_language('00393331234567');
        assert($res['language'] === 'it', 'Should detect 00 Italian prefix');

        $res = Helpers\hic_detect_phone_language('+44 1234567890');
        assert($res['language'] === 'en', 'Should detect foreign prefix');
        assert($res['phone'] === '+441234567890', 'Should normalize foreign phone');

        $res = Helpers\hic_detect_phone_language('3331234567');
        assert($res['language'] === 'it', 'Should detect Italian mobile without prefix');
        assert($res['phone'] === '3331234567', 'Should keep phone without prefix');

        $res = Helpers\hic_detect_phone_language('031234567');
        assert($res['language'] === 'it', 'Should detect Italian landline without prefix');

        echo "✅ Phone language detection tests passed\n";
    }

    public function testBrevoPhoneLanguageOverride() {
        // Ensure required WordPress stubs exist
        if (!function_exists('wp_json_encode')) { function wp_json_encode($data) { return json_encode($data); } }
        if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code($res) { return $res['response']['code'] ?? 0; } }
        if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body($res) { return $res['body'] ?? ''; } }
        if (!function_exists('is_wp_error')) { function is_wp_error($thing) { return false; } }
        if (!function_exists('wp_date')) { function wp_date($format, $ts = null) { return date($format, $ts ?? time()); } }

        require_once dirname(__DIR__) . '/includes/integrations/brevo.php';
        update_option('hic_brevo_api_key', 'test');

        global $hic_last_request;

        // Reservation with Italian phone
        $hic_last_request = null;
        \FpHic\hic_dispatch_brevo_reservation(['email' => 'it@example.com', 'phone' => '+39 333 1234567', 'language' => 'en', 'transaction_id' => 't1']);
        $payload = json_decode($hic_last_request['args']['body'], true);
        assert($payload['attributes']['LANGUAGE'] === 'it', 'Italian phone should force language it');
        assert($payload['attributes']['PHONE'] === '+393331234567', 'Phone should be normalized');

        // Reservation with foreign phone
        $hic_last_request = null;
        \FpHic\hic_dispatch_brevo_reservation(['email' => 'en@example.com', 'phone' => '+44 1234567890', 'language' => 'it', 'transaction_id' => 't2']);
        $payload = json_decode($hic_last_request['args']['body'], true);
        assert($payload['attributes']['LANGUAGE'] === 'en', 'Foreign phone should force language en');
        assert($payload['attributes']['PHONE'] === '+441234567890', 'Phone should be normalized');

        // Contact with Italian phone and guest name mapping
        $hic_last_request = null;
        \FpHic\hic_send_brevo_contact([
            'email' => 'c@example.com',
            'phone' => '+39 3331234567',
            'whatsapp' => '+39 3331234567',
            'lang' => 'en',
            'guest_first_name' => 'Mario',
            'guest_last_name' => 'Rossi'
        ], '', '');
        $payload = json_decode($hic_last_request['args']['body'], true);
        assert($payload['attributes']['LINGUA'] === 'it', 'Contact Italian phone forces language it');
        assert($payload['attributes']['LANGUAGE'] === 'it', 'Contact should include LANGUAGE attribute');
        assert($payload['attributes']['PHONE'] === '+393331234567', 'Phone should be normalized');
        assert($payload['attributes']['WHATSAPP'] === '+393331234567', 'WhatsApp should be normalized');
        assert($payload['attributes']['FIRSTNAME'] === 'Mario', 'Guest first name should map to FIRSTNAME');
        assert($payload['attributes']['LASTNAME'] === 'Rossi', 'Guest last name should map to LASTNAME');

        // Contact with phone only should populate WHATSAPP
        $hic_last_request = null;
        \FpHic\hic_send_brevo_contact([
            'email' => 'onlyphone@example.com',
            'phone' => '+39 3331234567',
            'lingua' => 'en'
        ], '', '');
        $payload = json_decode($hic_last_request['args']['body'], true);
        assert($payload['attributes']['PHONE'] === '+393331234567', 'Phone should be normalized');
        assert($payload['attributes']['WHATSAPP'] === '+393331234567', 'WhatsApp should fall back to phone');

        // Contact with foreign phone
        $hic_last_request = null;
        \FpHic\hic_send_brevo_contact([
            'email' => 'c2@example.com',
            'phone' => '+44 3331234567',
            'whatsapp' => '+44 3331234567',
            'lingua' => 'it'
        ], '', '');
        $payload = json_decode($hic_last_request['args']['body'], true);
        assert($payload['attributes']['LINGUA'] === 'en', 'Contact foreign phone forces language en');
        assert($payload['attributes']['LANGUAGE'] === 'en', 'Contact should include LANGUAGE attribute');
        assert($payload['attributes']['PHONE'] === '+443331234567', 'Phone should be normalized');

        echo "✅ Brevo phone language override tests passed\n";
    }

    public function testBrevoContactLanguageAndTags() {
        // Ensure required WordPress stubs exist
        if (!function_exists('wp_json_encode')) { function wp_json_encode($data) { return json_encode($data); } }
        if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code($res) { return $res['response']['code'] ?? 0; } }
        if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body($res) { return $res['body'] ?? ''; } }
        if (!function_exists('is_wp_error')) { function is_wp_error($thing) { return false; } }
        if (!function_exists('wp_date')) { function wp_date($format, $ts = null) { return date($format, $ts ?? time()); } }

        require_once dirname(__DIR__) . '/includes/integrations/brevo.php';
        update_option('hic_brevo_api_key', 'test');

        global $hic_last_request;

        $hic_last_request = null;
        \FpHic\hic_send_brevo_contact([
            'email' => 'tags@example.com',
            'language' => 'en',
            'tags' => ['one', 'two']
        ], '', '');

        $payload = json_decode($hic_last_request['args']['body'], true);
        assert($payload['attributes']['LANGUAGE'] === 'en', 'Contact should include LANGUAGE attribute');
        assert($payload['attributes']['TAGS'] === 'one,two', 'Contact should include TAGS attribute');
        assert($payload['tags'] === ['one','two'], 'Tags array should be preserved');

        echo "✅ Brevo contact language and tags tests passed\n";
    }

    public function testBrevoLanguageListFiltering() {
        // Ensure required WordPress stubs exist
        if (!function_exists('wp_json_encode')) { function wp_json_encode($data) { return json_encode($data); } }
        if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code($res) { return $res['response']['code'] ?? 0; } }
        if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body($res) { return $res['body'] ?? ''; } }
        if (!function_exists('is_wp_error')) { function is_wp_error($thing) { return false; } }
        if (!function_exists('wp_date')) { function wp_date($format, $ts = null) { return date($format, $ts ?? time()); } }

        require_once dirname(__DIR__) . '/includes/integrations/brevo.php';
        update_option('hic_brevo_api_key', 'test');
        update_option('hic_brevo_list_en', '123');
        update_option('hic_brevo_list_it', '456');
        update_option('hic_brevo_list_default', '789');
        \FpHic\Helpers\hic_clear_option_cache();

        global $hic_last_request;

        // Language en should map to English list
        $hic_last_request = null;
        \FpHic\hic_send_brevo_contact([
            'email' => 'c@example.com',
            'language' => 'en'
        ], '', '');
        $payload = json_decode($hic_last_request['args']['body'], true);
        assert($payload['listIds'] === [123], 'Language en should map to English list');

        // Missing language should fall back to default list
        $hic_last_request = null;
        \FpHic\hic_send_brevo_contact([
            'email' => 'c-missing@example.com'
        ], '', '');
        $payload = json_decode($hic_last_request['args']['body'], true);
        assert($payload['listIds'] === [789], 'Missing language should map to default list');

        // Unknown language should fall back to default list
        $hic_last_request = null;
        \FpHic\hic_send_brevo_contact([
            'email' => 'c-unknown@example.com',
            'language' => 'de'
        ], '', '');
        $payload = json_decode($hic_last_request['args']['body'], true);
        assert($payload['listIds'] === [789], 'Unknown language should map to default list');

        // List ID 0 should be filtered out
        update_option('hic_brevo_list_en', '0');
        \FpHic\Helpers\hic_clear_option_cache('hic_brevo_list_en');
        $hic_last_request = null;
        \FpHic\hic_send_brevo_contact([
            'email' => 'c2@example.com',
            'language' => 'en'
        ], '', '');
        $payload = json_decode($hic_last_request['args']['body'], true);
        assert(empty($payload['listIds']), 'List ID 0 should be filtered out');

        echo "✅ Brevo language list filtering tests passed\n";
    }

    public function testBrevoRoomNamePropagation() {
        // Ensure required WordPress stubs exist
        if (!function_exists('wp_json_encode')) { function wp_json_encode($data) { return json_encode($data); } }
        if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code($res) { return $res['response']['code'] ?? 0; } }
        if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body($res) { return $res['body'] ?? ''; } }
        if (!function_exists('is_wp_error')) { function is_wp_error($thing) { return false; } }
        if (!function_exists('wp_date')) { function wp_date($format, $ts = null) { return date($format, $ts ?? time()); } }

        require_once dirname(__DIR__) . '/includes/integrations/brevo.php';
        update_option('hic_brevo_api_key', 'test');
        update_option('hic_realtime_brevo_sync', '1');

        global $hic_last_request;

        // Contact dispatch should include HIC_ROOM_NAME
        $hic_last_request = null;
        \FpHic\hic_dispatch_brevo_reservation([
            'email' => 'room@example.com',
            'transaction_id' => 'TR',
            'room_name' => 'Camera Deluxe',
            'original_price' => 100,
            'currency' => 'EUR'
        ]);
        $payload = json_decode($hic_last_request['args']['body'], true);
        assert($payload['attributes']['HIC_ROOM_NAME'] === 'Camera Deluxe', 'Contact should include HIC_ROOM_NAME');

        // Event should include room_name property
        $hic_last_request = null;
        \FpHic\hic_send_brevo_reservation_created_event([
            'email' => 'room@example.com',
            'transaction_id' => 'TR',
            'original_price' => 100,
            'currency' => 'EUR',
            'room_name' => 'Camera Deluxe'
        ]);
        $payload = json_decode($hic_last_request['args']['body'], true);
        assert($payload['properties']['room_name'] === 'Camera Deluxe', 'Event should include room_name');

        echo "✅ Brevo room name propagation tests passed\n";
    }

    public function testBrevoReservationCreatedPhoneLanguageOverride() {
        // Ensure required WordPress stubs exist
        if (!function_exists('wp_json_encode')) { function wp_json_encode($data) { return json_encode($data); } }
        if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code($res) { return $res['response']['code'] ?? 0; } }
        if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body($res) { return $res['body'] ?? ''; } }
        if (!function_exists('is_wp_error')) { function is_wp_error($thing) { return false; } }
        if (!function_exists('wp_date')) { function wp_date($format, $ts = null) { return date($format, $ts ?? time()); } }

        require_once dirname(__DIR__) . '/includes/integrations/brevo.php';
        update_option('hic_brevo_api_key', 'test');
        update_option('hic_realtime_brevo_sync', '1');

        global $hic_last_request;

        // Event with Italian phone
        $hic_last_request = null;
        \FpHic\hic_send_brevo_reservation_created_event([
            'email' => 'it@example.com',
            'phone' => '+39 333 1234567',
            'language' => 'en',
            'transaction_id' => 't1',
            'original_price' => 100,
            'currency' => 'EUR'
        ]);
        $payload = json_decode($hic_last_request['args']['body'], true);
        assert($payload['properties']['language'] === 'it', 'Italian phone should force language it');
        assert($payload['properties']['phone'] === '+393331234567', 'Phone should be normalized');

        // Event with foreign phone
        $hic_last_request = null;
        \FpHic\hic_send_brevo_reservation_created_event([
            'email' => 'en@example.com',
            'phone' => '+44 1234567890',
            'language' => 'it',
            'transaction_id' => 't2',
            'original_price' => 100,
            'currency' => 'EUR'
        ]);
        $payload = json_decode($hic_last_request['args']['body'], true);
        assert($payload['properties']['language'] === 'en', 'Foreign phone should force language en');
        assert($payload['properties']['phone'] === '+441234567890', 'Phone should be normalized');

        echo "✅ Brevo reservation_created phone language override tests passed\n";
    }

    public function testFacebookPhoneHashNormalization() {
        if (!function_exists('home_url')) { function home_url() { return 'https://example.com'; } }
        if (!function_exists('wp_json_encode')) { function wp_json_encode($data) { return json_encode($data); } }

        update_option('hic_fb_pixel_id', 'FBTEST');
        update_option('hic_fb_access_token', 'FBTOKEN');
        Helpers\hic_clear_option_cache();

        global $hic_last_request;

        // Italian mobile number without explicit prefix
        $hic_last_request = null;
        $mobile = [
            'email' => 'mobile@example.com',
            'phone' => '333 123 4567',
            'country' => 'IT',
            'sid' => 'sid-mobile'
        ];
        \FpHic\hic_send_to_fb($mobile, null, null);
        $payload = json_decode($hic_last_request['args']['body'], true);
        $mobile_hash = $payload['data'][0]['user_data']['ph'][0] ?? '';
        assert($mobile_hash === hash('sha256', '+393331234567'), 'Italian mobile phone should include +39 prefix before hashing');

        // Italian landline retaining the trunk zero
        $hic_last_request = null;
        $landline = [
            'email' => 'landline@example.com',
            'phone' => '031 234567',
            'country' => 'IT',
            'sid' => 'sid-landline'
        ];
        \FpHic\hic_send_to_fb($landline, null, null);
        $payload = json_decode($hic_last_request['args']['body'], true);
        $landline_hash = $payload['data'][0]['user_data']['ph'][0] ?? '';
        assert($landline_hash === hash('sha256', '+39031234567'), 'Italian landline should retain leading zero in E.164 hash');

        // Foreign number with explicit prefix
        $hic_last_request = null;
        $uk = [
            'email' => 'uk@example.com',
            'phone' => '+44 20 7946 0958',
            'country' => 'GB',
            'sid' => 'sid-uk'
        ];
        \FpHic\hic_send_to_fb($uk, null, null);
        $payload = json_decode($hic_last_request['args']['body'], true);
        $uk_hash = $payload['data'][0]['user_data']['ph'][0] ?? '';
        assert($uk_hash === hash('sha256', '+442079460958'), 'Foreign phone should keep its country prefix when hashed');

        echo "✅ Facebook phone hash normalization tests passed\n";
    }

    public function testGa4TransactionIdFallbackIsStable() {
        if (!function_exists('home_url')) { function home_url() { return 'https://example.com'; } }
        if (!function_exists('wp_generate_uuid4')) { function wp_generate_uuid4() { return 'uuid-4'; } }
        if (!function_exists('wp_json_encode')) { function wp_json_encode($data) { return json_encode($data); } }
        if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code($res) { return $res['response']['code'] ?? 0; } }
        if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body($res) { return $res['body'] ?? ''; } }
        if (!function_exists('is_wp_error')) { function is_wp_error($thing) { return false; } }

        require_once dirname(__DIR__) . '/includes/integrations/ga4.php';

        update_option('hic_measurement_id', 'G-TEST');
        update_option('hic_api_secret', 'secret');
        Helpers\hic_clear_option_cache();

        global $hic_last_request;

        $data = [
            'email' => 'guest@example.com',
            'from_date' => '2024-05-01',
            'amount' => 150,
            'currency' => 'EUR'
        ];

        $hic_last_request = null;
        \FpHic\hic_send_to_ga4($data, null, null, null, null, null, null, '');
        $payload1 = json_decode($hic_last_request['args']['body'], true);
        $transaction_id1 = $payload1['events'][0]['params']['transaction_id'];
        $client_id1 = $payload1['client_id'];

        $hic_last_request = null;
        \FpHic\hic_send_to_ga4($data, null, null, null, null, null, null, '');
        $payload2 = json_decode($hic_last_request['args']['body'], true);
        $transaction_id2 = $payload2['events'][0]['params']['transaction_id'];
        $client_id2 = $payload2['client_id'];

        assert($transaction_id1 === $transaction_id2, 'GA4 fallback should reuse transaction_id when reservation_id is missing');
        assert($transaction_id1 !== '', 'GA4 fallback transaction_id should not be empty');
        assert($client_id1 === $transaction_id1, 'GA4 fallback client_id should reuse transaction_id');
        assert($client_id2 === $transaction_id2, 'GA4 fallback client_id should reuse transaction_id');

        echo "✅ GA4 transaction_id fallback stability tests passed\n";
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
        Helpers\hic_clear_option_cache();

        global $hic_last_request;

        // GA4 room name + SID usage
        $data = ['room' => 'Camera Deluxe', 'currency' => 'EUR', 'amount' => 100];
        $sid = 'sid123';
        \FpHic\hic_send_to_ga4($data, null, null, null, null, null, null, $sid);
        $payload = json_decode($hic_last_request['args']['body'], true);
        assert($payload['events'][0]['params']['items'][0]['item_name'] === 'Camera Deluxe', 'GA4 should use room name');
        assert($payload['client_id'] === $sid, 'GA4 should use SID as client_id');
        assert($payload['events'][0]['params']['transaction_id'] === $sid, 'GA4 should use SID as transaction_id');

        // GA4 accommodation_name fallback
        $data = ['accommodation_name' => 'Suite', 'currency' => 'EUR', 'amount' => 100];
        \FpHic\hic_send_to_ga4($data, null, null, null, null, null, null, $sid);
        $payload = json_decode($hic_last_request['args']['body'], true);
        assert($payload['events'][0]['params']['items'][0]['item_name'] === 'Suite', 'GA4 should use accommodation name');

        // GA4 default
        $data = ['currency' => 'EUR', 'amount' => 100];
        \FpHic\hic_send_to_ga4($data, null, null, null, null, null, null, $sid);
        $payload = json_decode($hic_last_request['args']['body'], true);
        assert($payload['events'][0]['params']['items'][0]['item_name'] === 'Prenotazione', 'GA4 should default to Prenotazione');

        // FB room name
        $data = ['email' => 'user@example.com', 'room' => 'Camera Deluxe', 'currency' => 'EUR', 'amount' => 100];
        \FpHic\hic_send_to_fb($data, null, null, null, null);
        $payload = json_decode($hic_last_request['args']['body'], true);
        assert($payload['data'][0]['custom_data']['content_name'] === 'Camera Deluxe', 'FB should use room name');

        // FB accommodation_name fallback
        $data = ['email' => 'user@example.com', 'accommodation_name' => 'Suite', 'currency' => 'EUR', 'amount' => 100];
        \FpHic\hic_send_to_fb($data, null, null, null, null);
        $payload = json_decode($hic_last_request['args']['body'], true);
        assert($payload['data'][0]['custom_data']['content_name'] === 'Suite', 'FB should use accommodation name');

        // FB default
        $data = ['email' => 'user@example.com', 'currency' => 'EUR', 'amount' => 100];
        \FpHic\hic_send_to_fb($data, null, null, null, null);
        $payload = json_decode($hic_last_request['args']['body'], true);
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
            $this->testPhoneLanguageDetection();
            $this->testBrevoPhoneLanguageOverride();
            $this->testBrevoContactLanguageAndTags();
            $this->testBrevoLanguageListFiltering();
            $this->testBrevoRoomNamePropagation();
            $this->testBrevoReservationCreatedPhoneLanguageOverride();
            $this->testGa4TransactionIdFallbackIsStable();
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
$script_filename = $_SERVER['SCRIPT_FILENAME'] ?? null;
$should_run = false;

if (is_string($script_filename) && $script_filename !== '') {
    $should_run = realpath(__FILE__) === realpath($script_filename);
} else {
    $should_run = basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'] ?? '');
}

if ($should_run) {
    $test = new HICFunctionsTest();
    $test->runAll();
}