<?php
/**
 * Test per verificare che la modalità hybrid funzioni correttamente
 * abilitando sia webhook che API polling simultaneamente.
 */

class HybridModeTest extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
        
        // Imposta modalità hybrid
        update_option('hic_connection_type', 'hybrid');
        update_option('hic_webhook_token', 'test_hybrid_token');
        update_option('hic_api_url', 'https://api.hotelincloud.com/api/partner');
        update_option('hic_api_email', 'test@example.com');
        update_option('hic_api_password', 'test_password');
        update_option('hic_property_id', '12345');
        update_option('hic_reliable_polling_enabled', '1');
        
        // Pulisci cache opzioni
        \FpHic\Helpers\hic_clear_option_cache();
    }

    /**
     * Test che la modalità hybrid sia configurata correttamente
     */
    public function test_hybrid_mode_is_active() {
        $this->assertEquals('hybrid', hic_get_connection_type());
    }

    /**
     * Test che l'endpoint webhook sia registrato in modalità hybrid
     */
    public function test_webhook_endpoint_registered_in_hybrid_mode() {
        // Simula l'inizializzazione REST API
        do_action('rest_api_init');
        
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey('/hic/v1/conversion', $routes);
    }

    /**
     * Test che l'API polling sia abilitato in modalità hybrid
     */
    public function test_api_polling_enabled_in_hybrid_mode() {
        // Testa la funzione che determina se il polling dovrebbe essere schedulato
        $this->assertTrue(hic_should_schedule_poll_event());
    }

    /**
     * Test che la modalità hybrid sia riconosciuta dal booking poller
     */
    public function test_booking_poller_accepts_hybrid_mode() {
        // Simula le condizioni del booking poller
        $reliable_polling = \FpHic\Helpers\hic_reliable_polling_enabled();
        $connection_type_ok = \FpHic\Helpers\hic_connection_uses_api();
        $has_api_url = !empty(\FpHic\Helpers\hic_get_api_url());
        $has_credentials = \FpHic\Helpers\hic_has_basic_auth_credentials();
        
        $this->assertTrue($reliable_polling);
        $this->assertTrue($connection_type_ok);
        $this->assertTrue($has_api_url);
        $this->assertTrue($has_credentials);
    }

    /**
     * Test che i diagnostics riconoscano correttamente la modalità hybrid
     */
    public function test_diagnostics_recognize_hybrid_mode() {
        // Simula controllo diagnostico
        $should_activate_scheduler = hic_reliable_polling_enabled() &&
                                    hic_connection_uses_api() &&
                                    hic_get_api_url() &&
                                    hic_has_basic_auth_credentials();
        
        $this->assertTrue($should_activate_scheduler);
    }

    /**
     * Test che entrambi i sistemi (webhook + API) possano processare prenotazioni
     */
    public function test_both_systems_can_process_bookings() {
        $booking_data = [
            'email' => 'hybrid@test.com',
            'reservation_id' => 'HYBRID_123',
            'amount' => 200.00,
            'currency' => 'EUR'
        ];

        // Test webhook processing
        $webhook_result = \FpHic\hic_process_booking_data($booking_data);
        $this->assertIsArray($webhook_result);
        $this->assertArrayHasKey('status', $webhook_result);
        $this->assertTrue(in_array($webhook_result['status'], ['success', 'partial'], true));

        // Test API processing (stesso dato, dovrebbe essere deduplicato)
        $api_result = \FpHic\hic_process_booking_data($booking_data);
        $this->assertIsArray($api_result);
        $this->assertArrayHasKey('status', $api_result);
        $this->assertTrue(in_array($api_result['status'], ['success', 'partial'], true));
        
        // Verifica che la reservation_id sia gestita correttamente
        $reservation_id = hic_extract_reservation_id($booking_data);
        $this->assertEquals('HYBRID_123', $reservation_id);
    }

    /**
     * Test che le modalità singole (webhook/api) continuino a funzionare
     */
    public function test_single_modes_still_work() {
        // Test modalità webhook
        update_option('hic_connection_type', 'webhook');
        \FpHic\Helpers\hic_clear_option_cache();
        
        // Reinizializza REST API
        rest_get_server()->reset_registrations();
        do_action('rest_api_init');
        
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey('/hic/v1/conversion', $routes);
        $this->assertFalse(hic_should_schedule_poll_event());
        
        // Test modalità API
        update_option('hic_connection_type', 'api');
        \FpHic\Helpers\hic_clear_option_cache();
        
        // Reinizializza REST API
        rest_get_server()->reset_registrations();
        do_action('rest_api_init');
        
        $routes = rest_get_server()->get_routes();
        $this->assertArrayNotHasKey('/hic/v1/conversion', $routes);
        $this->assertTrue(hic_should_schedule_poll_event());
    }

    /**
     * Test vantaggi della modalità hybrid
     */
    public function test_hybrid_mode_advantages() {
        // Reimposta modalità hybrid
        update_option('hic_connection_type', 'hybrid');
        \FpHic\Helpers\hic_clear_option_cache();
        
        // Verifica che entrambi i sistemi siano attivi
        do_action('rest_api_init');
        $routes = rest_get_server()->get_routes();
        
        // Webhook attivo
        $this->assertArrayHasKey('/hic/v1/conversion', $routes);
        
        // API polling attivo
        $this->assertTrue(hic_should_schedule_poll_event());
        
        // Questa combinazione fornisce:
        // 1. Tracciamento in tempo reale via webhook
        // 2. Backup affidabile via API polling
        // 3. Copertura completa (nuove prenotazioni + modifiche manuali)
        $this->assertTrue(true, 'Hybrid mode provides both real-time and backup tracking');
    }

    public function tearDown(): void {
        // Pulisci impostazioni di test
        delete_option('hic_connection_type');
        delete_option('hic_webhook_token');
        delete_option('hic_api_url');
        delete_option('hic_api_email');
        delete_option('hic_api_password');
        delete_option('hic_property_id');
        delete_option('hic_reliable_polling_enabled');
        \FpHic\Helpers\hic_clear_option_cache();
        
        parent::tearDown();
    }
}