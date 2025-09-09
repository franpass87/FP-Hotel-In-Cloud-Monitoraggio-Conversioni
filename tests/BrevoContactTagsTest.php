<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/integrations/brevo.php';

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}

final class BrevoContactTagsTest extends TestCase {
    protected function setUp(): void {
        update_option('hic_brevo_api_key', 'test-key');
    }

    public function testContactIncludesTags() {
        global $hic_last_request;
        $hic_last_request = null;

        $data = [
            'email' => 'tagtest@example.com',
            'tags'  => ['vip', 'promo'],
        ];

        \FpHic\hic_send_brevo_contact($data, '', '');

        $payload = json_decode($hic_last_request['args']['body'], true);
        $this->assertSame(['vip', 'promo'], $payload['tags']);
        $this->assertSame('vip,promo', $payload['attributes']['TAGS']);
    }
}
