<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/integrations/brevo.php';

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}

final class BrevoGuestNamesTest extends TestCase {
    protected function setUp(): void {
        update_option('hic_brevo_api_key', 'test-key');
    }

    public function testEventSendsGuestNames() {
        global $hic_last_request;
        $hic_last_request = null;

        $reservation = [
            'email' => 'test@example.com',
            'reservation_id' => 'R1',
            'amount' => 100,
            'currency' => 'EUR',
            'guest_first_name' => 'Mario',
            'guest_last_name' => 'Rossi',
        ];

        \FpHic\hic_send_brevo_event($reservation, null, null);

        $payload = json_decode($hic_last_request['args']['body'], true);
        $this->assertSame('Mario', $payload['properties']['firstname']);
        $this->assertSame('Rossi', $payload['properties']['lastname']);
    }

    public function testRefundEventSendsGuestNames() {
        global $hic_last_request;
        $hic_last_request = null;

        $reservation = [
            'email' => 'test@example.com',
            'reservation_id' => 'R1',
            'amount' => 100,
            'currency' => 'EUR',
            'guest_first_name' => 'Mario',
            'guest_last_name' => 'Rossi',
        ];

        \FpHic\hic_send_brevo_refund_event($reservation, null, null);

        $payload = json_decode($hic_last_request['args']['body'], true);
        $this->assertSame('Mario', $payload['properties']['firstname']);
        $this->assertSame('Rossi', $payload['properties']['lastname']);
    }
}
