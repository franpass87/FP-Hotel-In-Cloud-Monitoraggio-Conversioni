<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/integrations/brevo.php';

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}

final class BrevoReservationFieldsTest extends TestCase {
    protected function setUp(): void {
        update_option('hic_brevo_api_key', 'test-key');
    }

    public function testDispatchReservationSendsFields() {
        global $hic_last_request;
        $hic_last_request = null;

        $data = [
            'email' => 'tag@example.com',
            'transaction_id' => 'T1',
            'presence' => 1,
            'unpaid_balance' => 50.5,
            'tags' => ['vip', 'promo'],
            'accommodation_id' => 'A1',
            'room_id' => 'R1',
            'offer' => 'OFF1'
        ];

        \FpHic\hic_dispatch_brevo_reservation($data);

        $payload = json_decode($hic_last_request['args']['body'], true);
        $this->assertSame(['vip', 'promo'], $payload['tags']);
        $this->assertSame('vip,promo', $payload['attributes']['TAGS']);
        $this->assertSame(1, $payload['attributes']['HIC_PRESENCE']);
        $this->assertSame(50.5, $payload['attributes']['HIC_BALANCE']);
        $this->assertSame('A1', $payload['attributes']['HIC_ACCOM_ID']);
        $this->assertSame('R1', $payload['attributes']['HIC_ROOM_ID']);
        $this->assertSame('OFF1', $payload['attributes']['HIC_OFFER']);
    }

    public function testReservationCreatedEventSendsFields() {
        global $hic_last_request;
        $hic_last_request = null;

        $reservation = [
            'email' => 'tag@example.com',
            'transaction_id' => 'R1',
            'original_price' => 100,
            'currency' => 'EUR',
            'presence' => 1,
            'unpaid_balance' => 50.5,
            'tags' => ['vip', 'promo'],
            'accommodation_id' => 'A1',
            'room_id' => 'R1',
            'offer' => 'OFF1',
            'guest_first_name' => 'Mario',
            'guest_last_name' => 'Rossi'
        ];

        \FpHic\hic_send_brevo_reservation_created_event($reservation);

        $payload = json_decode($hic_last_request['args']['body'], true);
        $this->assertSame(['vip', 'promo'], $payload['tags']);
        $this->assertSame('vip,promo', $payload['properties']['tags']);
        $this->assertSame(1, $payload['properties']['presence']);
        $this->assertSame(50.5, $payload['properties']['unpaid_balance']);
        $this->assertSame('A1', $payload['properties']['accommodation_id']);
        $this->assertSame('R1', $payload['properties']['room_id']);
        $this->assertSame('OFF1', $payload['properties']['offer']);
        $this->assertSame('Mario', $payload['properties']['guest_first_name']);
        $this->assertSame('Rossi', $payload['properties']['guest_last_name']);
    }
}
