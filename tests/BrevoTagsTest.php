<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/integrations/brevo.php';

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}

final class BrevoTagsTest extends TestCase {
    protected function setUp(): void {
        update_option('hic_brevo_api_key', 'test-key');
    }

    public function testDispatchReservationSendsTags() {
        global $hic_last_request;
        $hic_last_request = null;

        $data = [
            'email' => 'tag@example.com',
            'transaction_id' => 'T1',
            'tags' => ['vip', 'promo']
        ];

        \FpHic\hic_dispatch_brevo_reservation($data);

        $payload = json_decode($hic_last_request['args']['body'], true);
        $this->assertSame(['vip', 'promo'], $payload['tags']);
        $this->assertSame(['vip', 'promo'], json_decode($payload['attributes']['TAGS'], true));
    }

    public function testEventSendsTags() {
        global $hic_last_request;
        $hic_last_request = null;

        $reservation = [
            'email' => 'tag@example.com',
            'reservation_id' => 'R1',
            'amount' => 100,
            'currency' => 'EUR',
            'tags' => ['vip', 'promo']
        ];

        \FpHic\hic_send_brevo_event($reservation, null, null);

        $payload = json_decode($hic_last_request['args']['body'], true);
        $this->assertSame(['vip', 'promo'], $payload['tags']);
    }
}
