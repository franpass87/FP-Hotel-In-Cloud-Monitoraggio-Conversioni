<?php declare(strict_types=1);

/**
 * Test che il sistema funziona senza Google Ads Enhanced Conversions
 */

class SystemWithoutEnhancedConversionsTest extends \PHPUnit\Framework\TestCase
{
    public function setUp(): void
    {
        // Reset WordPress environment
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        // Ensure we start with a clean state
        delete_option('hic_google_ads_enhanced_enabled');
        delete_option('hic_google_ads_enhanced_settings');
    }

    /**
     * Test che il core booking processor funziona senza Google Ads Enhanced
     */
    public function testBookingProcessorWorksWithoutEnhanced()
    {
        // Mock booking data
        $booking_data = [
            'email' => 'test@example.com',
            'reservation_id' => 'TEST123',
            'amount' => 150.00,
            'currency' => 'EUR',
            'sid' => 'test_sid_' . time()
        ];

        // Verify function exists
        $this->assertTrue(
            function_exists('\\FpHic\\hic_process_booking_data'),
            'La funzione hic_process_booking_data deve esistere'
        );

        // Process booking without enhanced conversions
        ob_start();
        $result = \FpHic\hic_process_booking_data($booking_data);
        $output = ob_get_clean();

        // Test should not fail due to missing enhanced conversions
        $this->assertIsBool($result, 'hic_process_booking_data deve restituire un boolean');
        
        // Check no fatal errors occurred
        $this->assertStringNotContainsString(
            'Fatal error',
            $output,
            'Non devono verificarsi errori fatali senza Google Ads Enhanced'
        );
    }

    /**
     * Test che le integrazioni core funzionano indipendentemente
     */
    public function testCoreIntegrationsWorkIndependently()
    {
        $test_cases = [
            'ga4_only' => 'Solo GA4',
            'gtm_only' => 'Solo GTM',
            'hybrid' => 'Modalità ibrida'
        ];

        foreach ($test_cases as $mode => $description) {
            // Set tracking mode
            update_option('hic_tracking_mode', $mode);
            
            $booking_data = [
                'email' => "test-{$mode}@example.com",
                'reservation_id' => "TEST-{$mode}-" . time(),
                'amount' => 100.00,
                'currency' => 'EUR'
            ];

            ob_start();
            $result = \FpHic\hic_process_booking_data($booking_data);
            $output = ob_get_clean();

            $this->assertIsBool(
                $result,
                "Il sistema deve funzionare in modalità {$description} senza Google Ads Enhanced"
            );
            
            // No fatal errors should occur
            $this->assertStringNotContainsString(
                'Fatal error',
                $output,
                "Nessun errore fatale per modalità {$description}"
            );
        }
    }

    /**
     * Test che Google Ads Enhanced non interferisce quando disabilitato
     */
    public function testEnhancedConversionsDoesNotInterefereWhenDisabled()
    {
        // Explicitly disable enhanced conversions
        update_option('hic_google_ads_enhanced_enabled', false);
        
        // Mock GCLID present (would normally trigger enhanced conversions)
        $booking_data = [
            'email' => 'test-with-gclid@example.com',
            'reservation_id' => 'TEST-GCLID-' . time(),
            'amount' => 200.00,
            'currency' => 'EUR',
            'sid' => 'test_sid_with_gclid_' . time(),
            'gclid' => 'test_gclid_123'
        ];

        // Mock tracking data that would include GCLID
        global $wpdb;
        if ($wpdb) {
            $table_name = $wpdb->prefix . 'hic_gclids';
            $wpdb->insert(
                $table_name,
                [
                    'sid' => $booking_data['sid'],
                    'gclid' => $booking_data['gclid'],
                    'created_at' => current_time('mysql'),
                    'url' => 'https://test.com'
                ]
            );
        }

        ob_start();
        $result = \FpHic\hic_process_booking_data($booking_data);
        $output = ob_get_clean();

        $this->assertIsBool(
            $result,
            'Il sistema deve funzionare anche con GCLID presente ma Enhanced Conversions disabilitato'
        );
        
        // Cleanup
        if ($wpdb && $booking_data['sid']) {
            $wpdb->delete(
                $wpdb->prefix . 'hic_gclids',
                ['sid' => $booking_data['sid']]
            );
        }
    }

    /**
     * Test delle funzioni helper senza dipendenze da Enhanced Conversions
     */
    public function testHelperFunctionsWorkWithoutEnhanced()
    {
        // Test key helper functions exist and work
        $this->assertTrue(
            function_exists('\\FpHic\\Helpers\\hic_get_tracking_mode'),
            'Helper hic_get_tracking_mode deve esistere'
        );

        $this->assertTrue(
            function_exists('\\FpHic\\Helpers\\hic_normalize_price'),
            'Helper hic_normalize_price deve esistere'
        );

        $this->assertTrue(
            function_exists('\\FpHic\\Helpers\\hic_is_valid_email'),
            'Helper hic_is_valid_email deve esistere'
        );

        // Test helper functions work
        $tracking_mode = \FpHic\Helpers\hic_get_tracking_mode();
        $this->assertIsString($tracking_mode, 'tracking_mode deve essere una stringa');

        $normalized_price = \FpHic\Helpers\hic_normalize_price('123.45');
        $this->assertIsFloat($normalized_price, 'normalize_price deve restituire un float');

        $is_valid_email = \FpHic\Helpers\hic_is_valid_email('test@example.com');
        $this->assertIsBool($is_valid_email, 'is_valid_email deve restituire un boolean');
    }

    /**
     * Test che le integrazioni Brevo/GA4/Meta funzionano senza Enhanced
     */
    public function testIntegrationsWorkWithoutEnhanced()
    {
        // Test that integration functions exist
        $integration_functions = [
            '\\FpHic\\hic_send_to_ga4',
            '\\FpHic\\hic_send_to_fb', 
            '\\FpHic\\hic_send_unified_brevo_events'
        ];

        foreach ($integration_functions as $function) {
            $this->assertTrue(
                function_exists($function),
                "La funzione di integrazione {$function} deve esistere"
            );
        }
    }

    /**
     * Test che il sistema è configurabile senza Enhanced Conversions
     */
    public function testSystemConfigurableWithoutEnhanced()
    {
        // Test that we can configure basic settings without enhanced conversions
        $basic_settings = [
            'hic_measurement_id' => 'G-TESTID123',
            'hic_api_secret' => 'test_api_secret',
            'hic_tracking_mode' => 'ga4_only',
            'hic_admin_email' => 'admin@test.com'
        ];

        foreach ($basic_settings as $option => $value) {
            update_option($option, $value);
            $retrieved = get_option($option);
            
            $this->assertEquals(
                $value,
                $retrieved,
                "L'impostazione {$option} deve essere configurabile senza Enhanced Conversions"
            );
        }
    }

    public function tearDown(): void
    {
        // Clean up test data
        delete_option('hic_google_ads_enhanced_enabled');
        delete_option('hic_google_ads_enhanced_settings');
        delete_option('hic_tracking_mode');
        delete_option('hic_measurement_id');
        delete_option('hic_api_secret');
        delete_option('hic_admin_email');
    }
}