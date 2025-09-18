<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/integrations/brevo.php';

final class BrevoSidUtmTest extends TestCase
{
    protected function setUp(): void
    {
        global $wpdb, $hic_last_request, $hic_test_options;

        $hic_last_request = null;
        $hic_test_options = [];

        update_option('hic_brevo_api_key', 'test-key');
        update_option('hic_realtime_brevo_sync', '1');

        $wpdb = new class {
            public $prefix = 'wp_';
            public $last_error = '';
            private $table;

            public function __construct()
            {
                $this->table = 'wp_hic_gclids';
            }

            public function prepare($query, ...$args)
            {
                if (count($args) === 1 && is_array($args[0])) {
                    $args = $args[0];
                }

                foreach ($args as $arg) {
                    if (strpos($query, '%d') !== false) {
                        $query = preg_replace('/%d/', (string) intval($arg), $query, 1);
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
                    return $this->table;
                }

                return null;
            }

            public function get_row($query)
            {
                if (is_array($query)) {
                    $query = $query['query'] ?? '';
                }

                if (stripos($query, 'SELECT gclid') !== false) {
                    return (object) [
                        'gclid' => 'GCLID-123',
                        'fbclid' => null,
                        'msclkid' => null,
                        'ttclid' => null,
                        'utm_source' => null,
                        'utm_medium' => null,
                        'utm_campaign' => null,
                        'utm_content' => null,
                        'utm_term' => null,
                    ];
                }

                if (stripos($query, 'SELECT utm_source') !== false) {
                    if (strpos($query, "'KNOWN-SID'") === false) {
                        return null;
                    }

                    return (object) [
                        'utm_source' => 'google',
                        'utm_medium' => 'cpc',
                        'utm_campaign' => 'retargeting',
                        'utm_content' => 'carousel',
                        'utm_term' => 'hotel+rome',
                        'gclid' => null,
                        'fbclid' => null,
                        'msclkid' => null,
                        'ttclid' => null,
                    ];
                }

                return null;
            }

            public function query($query)
            {
                return 1;
            }
        };
    }

    public function testTransformWebhookExtractsSidFromAlternateKeys(): void
    {
        $webhook = [
            'transaction_id' => 'ALT-1',
            'email' => 'alt@example.com',
            'amount' => '50',
            'session_id' => "  session-123  ",
        ];

        $transformed = \FpHic\hic_transform_webhook_data_for_brevo($webhook);

        $this->assertArrayHasKey('sid', $transformed);
        $this->assertSame('session-123', $transformed['sid']);
    }

    public function testWebhookSidPopulatesUtmParametersInEvent(): void
    {
        global $hic_last_request;

        $webhook = [
            'transaction_id' => 'RES-UTM',
            'email' => 'guest@example.com',
            'amount' => '199.90',
            'currency' => 'EUR',
            'date' => '2024-10-01',
            'sid' => "  KNOWN-SID\n",
            'guest_first_name' => 'Anna',
            'guest_last_name' => 'Bianchi',
        ];

        $transformed = \FpHic\hic_transform_webhook_data_for_brevo($webhook);

        $this->assertSame('KNOWN-SID', $transformed['sid']);

        $result = \FpHic\hic_send_brevo_reservation_created_event($transformed);
        $this->assertTrue($result['success']);
        $this->assertNotNull($hic_last_request);

        $payload = json_decode($hic_last_request['args']['body'], true);

        $this->assertSame('google', $payload['properties']['utm_source']);
        $this->assertSame('cpc', $payload['properties']['utm_medium']);
        $this->assertSame('retargeting', $payload['properties']['utm_campaign']);
        $this->assertSame('carousel', $payload['properties']['utm_content']);
        $this->assertSame('hotel+rome', $payload['properties']['utm_term']);
    }
}
