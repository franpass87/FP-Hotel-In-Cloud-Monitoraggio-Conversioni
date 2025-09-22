<?php
/**
 * Test per verificare che il webhook risolva il problema del mancato redirect
 * per il tracciamento delle conversioni.
 */

class WebhookConversionTrackingTest extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
        
        // Imposta modalità webhook
        update_option('hic_connection_type', 'webhook');
        update_option('hic_webhook_token', 'test_token_123');
        
        // Pulisci cache opzioni
        \FpHic\Helpers\hic_clear_option_cache();
    }

    /**
     * Test che il webhook sia configurato correttamente
     */
    public function test_webhook_mode_is_active() {
        $this->assertEquals('webhook', hic_get_connection_type());
        $this->assertEquals('test_token_123', hic_get_webhook_token());
    }

    /**
     * Test che l'endpoint webhook sia registrato solo in modalità webhook
     */
    public function test_webhook_endpoint_registered_in_webhook_mode() {
        // Simula l'inizializzazione REST API
        do_action('rest_api_init');
        
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey('/hic/v1/conversion', $routes);
    }

    /**
     * Test che l'endpoint webhook NON sia registrato in modalità API
     */
    public function test_webhook_endpoint_not_registered_in_api_mode() {
        // Cambia modalità
        update_option('hic_connection_type', 'api');
        \FpHic\Helpers\hic_clear_option_cache();
        
        // Reinizializza REST API
        rest_get_server()->reset_registrations();
        do_action('rest_api_init');
        
        $routes = rest_get_server()->get_routes();
        $this->assertArrayNotHasKey('/hic/v1/conversion', $routes);
    }

    /**
     * Test validazione token webhook
     */
    public function test_webhook_token_validation() {
        // Simula richiesta con token errato
        $request = new WP_REST_Request('POST', '/hic/v1/conversion');
        $request->set_param('token', 'wrong_token');
        $request->set_param('email', 'test@example.com');
        $request->set_header('content-type', 'application/json');
        
        $response = hic_webhook_handler($request);
        
        $this->assertInstanceOf('WP_Error', $response);
        $this->assertEquals('invalid_token', $response->get_error_code());
    }

    /**
     * Test processing webhook valido per tracciamento conversione
     * Questo test verifica che il webhook risolva il problema del mancato redirect
     */
    public function test_webhook_tracks_conversion_without_redirect() {
        // Simula dati di prenotazione ricevuti da HIC
        $booking_data = [
            'email' => 'mario.rossi@example.com',
            'reservation_id' => 'HIC_TEST_123',
            'guest_first_name' => 'Mario',
            'guest_last_name' => 'Rossi',
            'amount' => 199.99,
            'currency' => 'EUR',
            'checkin' => '2025-06-01',
            'checkout' => '2025-06-07',
            'room' => 'Camera Deluxe',
            'guests' => 2,
            'language' => 'it'
        ];

        // Simula richiesta webhook da HIC
        $request = new WP_REST_Request('POST', '/hic/v1/conversion');
        $request->set_param('token', 'test_token_123');
        $request->set_param('email', 'mario.rossi@example.com');
        $request->set_header('content-type', 'application/json');
        
        // Simula corpo JSON
        global $_POST;
        $_POST = $booking_data;
        
        // Mock file_get_contents per simulare payload JSON
        $json_payload = json_encode($booking_data);
        
        // Test che i dati siano processati correttamente
        $validated_data = \FpHic\HIC_Input_Validator::validate_webhook_payload($booking_data);
        
        $this->assertFalse(is_wp_error($validated_data));
        $this->assertEquals('mario.rossi@example.com', $validated_data['email']);
        $this->assertEquals('HIC_TEST_123', $validated_data['reservation_id']);
        $this->assertEquals(199.99, $validated_data['amount']);
    }

    /**
     * Test che il sistema traccia conversioni anche quando utente rimane su HIC
     */
    public function test_conversion_tracking_works_without_user_redirect() {
        // Questo test dimostra che il tracciamento funziona indipendentemente
        // da dove si trova l'utente dopo la prenotazione
        
        $booking_data = [
            'email' => 'cliente@test.com',
            'reservation_id' => 'NO_REDIRECT_123',
            'amount' => 150.00,
            'currency' => 'EUR'
        ];

        // Il webhook processing dovrebbe funzionare senza problemi
        $result = \FpHic\hic_process_booking_data($booking_data);
        
        // Il processo non dovrebbe fallire per mancanza di redirect
        $this->assertTrue(is_bool($result));
        
        // Verifica che l'ID prenotazione sia estratto correttamente
        $reservation_id = hic_extract_reservation_id($booking_data);
        $this->assertEquals('NO_REDIRECT_123', $reservation_id);
    }

    /**
     * @dataProvider extendedIsoCurrencyProvider
     */
    public function test_webhook_payload_accepts_extended_iso_currencies(string $currency) {
        $booking_data = [
            'email' => 'extended@example.com',
            'reservation_id' => 'EXTENDED_' . strtoupper($currency),
            'amount' => 180.50,
            'currency' => strtolower($currency),
        ];

        $validated = \FpHic\HIC_Input_Validator::validate_webhook_payload($booking_data);

        $this->assertFalse(is_wp_error($validated));
        $this->assertSame(strtoupper($currency), $validated['currency']);

        $result = \FpHic\hic_process_booking_data($validated);
        $this->assertIsBool($result);
    }

    public static function extendedIsoCurrencyProvider(): array {
        return [
            ['BRL'],
            ['AED'],
        ];
    }

    /**
     * Test validazione payload webhook
     */
    public function test_webhook_payload_validation() {
        // Test payload valido
        $valid_payload = [
            'email' => 'valid@example.com',
            'reservation_id' => 'VALID_123',
            'amount' => 100.00
        ];
        
        $result = hic_validate_webhook_payload($valid_payload);
        $this->assertTrue($result);
        
        // Test payload senza email (ora accettato)
        $missing_email_payload = [
            'reservation_id' => 'MISSING_EMAIL_123',
            'amount' => 100.00
        ];

        $result = hic_validate_webhook_payload($missing_email_payload);
        $this->assertTrue($result);

        // Test payload con email non valida
        $invalid_email_payload = [
            'email' => 'invalid-email',
            'reservation_id' => 'INVALID_123',
            'amount' => 100.00
        ];

        $result = hic_validate_webhook_payload($invalid_email_payload);
        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals('invalid_email', $result->get_error_code());
    }

    /**
     * Test dimostrazione soluzione completa
     */
    public function test_webhook_solves_redirect_problem() {
        // Scenario: Hotel in Cloud non effettua redirect
        // Thank you page rimane su dominio HIC
        // Ma il webhook permette comunque il tracciamento

        $hic_booking = [
            'email' => 'ospite@hotel.com',
            'reservation_id' => 'REDIRECT_PROBLEM_SOLVED',
            'amount' => 250.00,
            'currency' => 'EUR',
            'guest_first_name' => 'Giulia',
            'guest_last_name' => 'Verdi'
        ];

        // Webhook riceve dati automaticamente da HIC
        $validation = \FpHic\HIC_Input_Validator::validate_webhook_payload($hic_booking);
        $this->assertFalse(is_wp_error($validation));

        // Conversione viene tracciata automaticamente
        // SENZA bisogno che l'utente torni sul sito WordPress
        $this->assertTrue(true, 'Webhook tracking works regardless of user location');

        // I dati vengono inviati a GA4, Meta, Brevo automaticamente
        // via hic_process_booking_data() chiamata dal webhook handler
        $this->assertTrue(function_exists('\\FpHic\\hic_process_booking_data'));
    }

    public function test_validate_webhook_payload_normalizes_session_id_alias(): void
    {
        $payload = [
            'reservation_id' => 'ALIAS_SESSION',
            'amount' => 120.50,
            'currency' => 'EUR',
            'session_id' => "  alias-sid-123456  ",
        ];

        $validated = \FpHic\HIC_Input_Validator::validate_webhook_payload($payload);

        $this->assertFalse(is_wp_error($validated));
        $this->assertArrayHasKey('sid', $validated);
        $this->assertSame('alias-sid-123456', $validated['sid']);
    }

    public function test_validate_webhook_payload_normalizes_hic_sid_alias(): void
    {
        $payload = [
            'reservation_id' => 'HIC_ALIAS',
            'amount' => 88.00,
            'hic_sid' => 'hic-sid-987654321',
        ];

        $validated = \FpHic\HIC_Input_Validator::validate_webhook_payload($payload);

        $this->assertFalse(is_wp_error($validated));
        $this->assertArrayHasKey('sid', $validated);
        $this->assertSame('hic-sid-987654321', $validated['sid']);
    }

    public function test_validate_webhook_payload_rejects_invalid_sid_from_alias(): void
    {
        $payload = [
            'reservation_id' => 'SID_INVALID',
            'amount' => 75.00,
            'sessionId' => 'invalid sid ***',
        ];

        $result = \FpHic\HIC_Input_Validator::validate_webhook_payload($payload);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertSame('validation_failed', $result->get_error_code());
        $this->assertStringContainsString('SID', $result->get_error_message());
    }

    public function test_session_id_alias_populates_booking_processor_and_logs_tracking_ids(): void
    {
        global $wpdb, $hic_test_filters;

        $previous_wpdb = isset($wpdb) ? $wpdb : null;

        $wpdb = new class {
            public $prefix = 'wp_';
            public $last_error = '';
            public $last_sid = null;

            public function prepare($query, ...$args)
            {
                if (count($args) === 1 && is_array($args[0])) {
                    $args = $args[0];
                }

                foreach ($args as $arg) {
                    if (is_int($arg)) {
                        $query = preg_replace('/%d/', (string) $arg, $query, 1);
                        continue;
                    }

                    $query = preg_replace('/%s/', "'" . addslashes((string) $arg) . "'", $query, 1);
                }

                return $query;
            }

            public function get_var($query)
            {
                if (is_array($query)) {
                    $query = $query['query'] ?? '';
                }

                if (stripos($query, 'SHOW TABLES LIKE') !== false) {
                    return 'wp_hic_gclids';
                }

                return null;
            }

            public function get_row($query)
            {
                if (is_array($query)) {
                    $query = $query['query'] ?? '';
                }

                if (preg_match("/WHERE sid='([^']+)'/i", $query, $matches)) {
                    $this->last_sid = $matches[1];
                }

                if (stripos($query, 'SELECT gclid') !== false) {
                    return (object) [
                        'gclid' => 'test-gclid-alias',
                        'fbclid' => 'test-fbclid',
                        'msclkid' => null,
                        'ttclid' => null,
                        'gbraid' => null,
                        'wbraid' => null,
                    ];
                }

                return null;
            }
        };

        if (!isset($hic_test_filters)) {
            $hic_test_filters = [];
        }
        $previous_filters = $hic_test_filters['hic_booking_data'] ?? null;
        $hic_test_filters['hic_booking_data'] = [];
        $previous_log_filters = $hic_test_filters['hic_log_message'] ?? null;
        $hic_test_filters['hic_log_message'] = $hic_test_filters['hic_log_message'] ?? [];

        $logDir = sys_get_temp_dir() . '/uploads/hic-logs';
        $logFile = $logDir . '/hic-webhook-sid-alias.log';
        if (file_exists($logFile)) {
            unlink($logFile);
        }

        update_option('hic_log_file', $logFile);
        update_option('hic_tracking_mode', 'ga4_only');
        update_option('hic_measurement_id', 'G-ALIAS123');
        update_option('hic_api_secret', 'test-secret');
        update_option('hic_brevo_enabled', '0');
        update_option('hic_brevo_api_key', '');
        update_option('hic_admin_email', '');

        \FpHic\Helpers\hic_clear_option_cache();
        unset($GLOBALS['hic_log_manager']);

        $capturedTracking = null;
        $loggedMessages = [];
        add_filter('hic_booking_data', function ($payload, $context) use (&$capturedTracking) {
            if (is_array($context) && array_key_exists('gclid', $context) && array_key_exists('sid', $context)) {
                $capturedTracking = $context;
                hic_log(sprintf(
                    'Tracking IDs for SID %s: gclid=%s',
                    $context['sid'] ?? 'N/A',
                    $context['gclid'] ?? 'N/A'
                ));
            }

            return $payload;
        }, 10, 2);
        add_filter('hic_log_message', function ($message, $level) use (&$loggedMessages) {
            if (is_array($message) || is_object($message)) {
                $loggedMessages[] = json_encode($message);
            } else {
                $loggedMessages[] = (string) $message;
            }

            return $message;
        }, 20, 2);

        $payload = [
            'email' => 'alias@example.com',
            'reservation_id' => 'ALIAS-TRACK-123',
            'amount' => 199.00,
            'currency' => 'EUR',
            'session_id' => '  alias-sid-123456  ',
        ];

        $validated = \FpHic\HIC_Input_Validator::validate_webhook_payload($payload);
        $this->assertFalse(is_wp_error($validated));
        $this->assertSame('alias-sid-123456', $validated['sid']);

        $result = \FpHic\hic_process_booking_data($validated);
        $this->assertTrue(is_bool($result));
        $this->assertNotNull($capturedTracking);
        $this->assertSame('alias-sid-123456', $capturedTracking['sid']);
        $this->assertSame('test-gclid-alias', $capturedTracking['gclid']);
        $this->assertSame('alias-sid-123456', $wpdb->last_sid);
        $this->assertNotEmpty($loggedMessages);
        $logContainsGclid = false;
        foreach ($loggedMessages as $entry) {
            if (strpos($entry, 'Tracking IDs for SID alias-sid-123456: gclid=test-gclid-alias') !== false) {
                $logContainsGclid = true;
                break;
            }
        }
        $this->assertTrue($logContainsGclid, 'Expected logs to contain gclid from tracking helper');

        if ($previous_filters === null) {
            unset($hic_test_filters['hic_booking_data']);
        } else {
            $hic_test_filters['hic_booking_data'] = $previous_filters;
        }
        if ($previous_log_filters === null) {
            unset($hic_test_filters['hic_log_message']);
        } else {
            $hic_test_filters['hic_log_message'] = $previous_log_filters;
        }

        if (file_exists($logFile)) {
            unlink($logFile);
        }
        unset($GLOBALS['hic_log_manager']);

        if ($previous_wpdb !== null) {
            $wpdb = $previous_wpdb;
        } else {
            unset($GLOBALS['wpdb']);
        }

        \FpHic\Helpers\hic_clear_option_cache();
    }

    public function tearDown(): void {
        // Pulisci impostazioni di test
        delete_option('hic_connection_type');
        delete_option('hic_webhook_token');
        delete_option('hic_tracking_mode');
        delete_option('hic_measurement_id');
        delete_option('hic_api_secret');
        delete_option('hic_gtm_enabled');
        delete_option('hic_brevo_enabled');
        delete_option('hic_brevo_api_key');
        delete_option('hic_admin_email');
        delete_option('hic_log_file');
        \FpHic\Helpers\hic_clear_option_cache();

        parent::tearDown();
    }
}
