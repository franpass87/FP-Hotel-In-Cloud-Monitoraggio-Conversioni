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
            'room_name' => 'Room 1',
            'offer' => 'OFF1',
            'valid' => 1,
            'relocations' => [['from' => '2024-01-01', 'to' => '2024-01-05']]
        ];

        \FpHic\hic_dispatch_brevo_reservation($data);

        $payload = json_decode($hic_last_request['args']['body'], true);
        $this->assertSame(['vip', 'promo'], $payload['tags']);
        $this->assertSame('vip,promo', $payload['attributes']['TAGS']);
        $this->assertSame(1, $payload['attributes']['HIC_PRESENCE']);
        $this->assertSame(50.5, $payload['attributes']['HIC_BALANCE']);
        $this->assertSame('A1', $payload['attributes']['HIC_ACCOM_ID']);
        $this->assertSame('R1', $payload['attributes']['HIC_ROOM_ID']);
        $this->assertSame('Room 1', $payload['attributes']['HIC_ROOM_NAME']);
        $this->assertSame('OFF1', $payload['attributes']['HIC_OFFER']);
        $this->assertSame(1, $payload['attributes']['HIC_VALID']);
        $this->assertSame(json_encode($data['relocations']), $payload['attributes']['HIC_RELOCATIONS']);
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
            'accommodation_name' => 'Hotel Test',
            'accommodation_id' => 'A1',
            'room_id' => 'R1',
            'room_name' => 'Room 1',
            'offer' => 'OFF1',
            'guest_first_name' => 'Mario',
            'guest_last_name' => 'Rossi',
            'valid' => 1,
            'relocations' => [['from' => '2024-01-01', 'to' => '2024-01-05']]
        ];

        \FpHic\hic_send_brevo_reservation_created_event($reservation);

        $payload = json_decode($hic_last_request['args']['body'], true);
        $this->assertSame(['vip', 'promo'], $payload['tags']);
        $this->assertSame('vip,promo', $payload['properties']['tags']);
        $this->assertSame(1, $payload['properties']['presence']);
        $this->assertSame(50.5, $payload['properties']['unpaid_balance']);
        $this->assertSame('Hotel Test', $payload['properties']['accommodation_name']);
        $this->assertSame('A1', $payload['properties']['accommodation_id']);
        $this->assertSame('R1', $payload['properties']['room_id']);
        $this->assertSame('Room 1', $payload['properties']['room_name']);
        $this->assertSame('OFF1', $payload['properties']['offer']);
        $this->assertSame('Mario', $payload['properties']['guest_first_name']);
        $this->assertSame('Rossi', $payload['properties']['guest_last_name']);
        $this->assertSame(1, $payload['properties']['valid']);
        $this->assertSame(json_encode($reservation['relocations']), $payload['properties']['relocations']);
    }

    public function testPhoneAndWhatsappSeparated() {
        global $hic_last_request;
        $hic_last_request = null;

        $data = [
            'email' => 'both@example.com',
            'transaction_id' => 'TB',
            'phone' => '+39 333 1234567',
            'whatsapp' => '+44 1234567890',
            'language' => 'en'
        ];

        \FpHic\hic_dispatch_brevo_reservation($data);

        $payload = json_decode($hic_last_request['args']['body'], true);
        $this->assertSame('+393331234567', $payload['attributes']['PHONE']);
        $this->assertSame('+44 1234567890', $payload['attributes']['WHATSAPP']);
        $this->assertSame('it', $payload['attributes']['LANGUAGE']);
    }

    public function testWhatsappOnlyFallback() {
        global $hic_last_request;
        $hic_last_request = null;

        $data = [
            'email' => 'wa@example.com',
            'transaction_id' => 'TW',
            'whatsapp' => '+44 1234567890',
            'language' => 'it'
        ];

        \FpHic\hic_dispatch_brevo_reservation($data);

        $payload = json_decode($hic_last_request['args']['body'], true);
        $this->assertSame('+441234567890', $payload['attributes']['PHONE']);
        $this->assertSame('+441234567890', $payload['attributes']['WHATSAPP']);
        $this->assertSame('en', $payload['attributes']['LANGUAGE']);
    }

    public function testReservationCreatedEventHandlesEmptyTags() {
        global $hic_last_request;
        $hic_last_request = null;

        $reservation = [
            'email' => 'empty@example.com',
            'transaction_id' => 'E1',
            'original_price' => 10,
            'currency' => 'EUR',
            'tags' => []
        ];

        \FpHic\hic_send_brevo_reservation_created_event($reservation);

        $payload = json_decode($hic_last_request['args']['body'], true);
        $this->assertArrayHasKey('tags', $payload);
        $this->assertSame([], $payload['tags']);
        $this->assertArrayHasKey('tags', $payload['properties']);
        $this->assertSame('', $payload['properties']['tags']);
    }
}
