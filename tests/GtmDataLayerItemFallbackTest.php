<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GtmDataLayerItemFallbackTest extends TestCase
{
    /** @var mixed */
    private $previousWpdb;

    /** @var object */
    private object $mockWpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
        $this->mockWpdb = new class {
            public string $prefix = 'wp_';
            public string $last_error = '';

            public function prepare($query, ...$args)
            {
                return $query;
            }

            public function get_var($query)
            {
                return null;
            }

            public function get_row($query)
            {
                return null;
            }
        };

        $GLOBALS['wpdb'] = $this->mockWpdb;

        update_option('hic_gtm_enabled', '1');
        \FpHic\Helpers\hic_clear_option_cache('gtm_enabled');
    }

    protected function tearDown(): void
    {
        if ($this->previousWpdb !== null) {
            $GLOBALS['wpdb'] = $this->previousWpdb;
        } else {
            unset($GLOBALS['wpdb']);
        }

        \FpHic\Helpers\hic_clear_option_cache();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function dispatchAndRetrieveEvent(array $data, string $sid): array
    {
        $queue_key = \FpHic\hic_get_gtm_queue_option_key($sid);
        if ($queue_key !== '') {
            delete_option($queue_key);
        }

        $result = \FpHic\hic_send_to_gtm_datalayer($data, '', '', '', '', '', '', $sid);
        $this->assertTrue($result, 'Expected GTM DataLayer dispatch to succeed.');

        $events = \FpHic\hic_get_and_clear_gtm_events_for_sid($sid);
        $this->assertNotEmpty($events, 'Expected GTM queue to store the dispatched event.');
        $this->assertIsArray($events[0], 'Expected the stored GTM event to be an array.');

        return $events[0];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function dispatchAndRetrieveItem(array $data, string $sid): array
    {
        $event = $this->dispatchAndRetrieveEvent($data, $sid);

        $this->assertArrayHasKey('ecommerce', $event);
        $this->assertArrayHasKey('items', $event['ecommerce']);
        $this->assertNotEmpty($event['ecommerce']['items']);

        $item = $event['ecommerce']['items'][0];
        $this->assertIsArray($item, 'Expected the ecommerce item payload to be present.');

        return $item;
    }

    public function test_room_field_populates_item_name_and_id(): void
    {
        $item = $this->dispatchAndRetrieveItem([
            'transaction_id' => 'TRANS-ROOM-1',
            'amount' => 199.5,
            'currency' => 'EUR',
            'room' => 'Camera Deluxe',
            'accommodation_id' => 'A-99',
        ], 'sid-room');

        $this->assertSame('Camera Deluxe', $item['item_name']);
        $this->assertSame('A-99', $item['item_id']);
    }

    public function test_room_name_fallback_used_when_room_missing(): void
    {
        $item = $this->dispatchAndRetrieveItem([
            'transaction_id' => 'TRANS-ROOMNAME-1',
            'amount' => 149.0,
            'currency' => 'EUR',
            'room_name' => 'Suite Mare',
            'room_id' => 'ROOM-42',
        ], 'sid-room-name');

        $this->assertSame('Suite Mare', $item['item_name']);
        $this->assertSame('ROOM-42', $item['item_id']);
    }

    public function test_accommodation_name_used_when_room_fields_empty(): void
    {
        $item = $this->dispatchAndRetrieveItem([
            'transaction_id' => 'TRANS-ACCOM-1',
            'amount' => 99.0,
            'currency' => 'EUR',
            'accommodation_name' => 'Residenza Centro',
            'accommodation_id' => 'AC-33',
        ], 'sid-accom');

        $this->assertSame('Residenza Centro', $item['item_name']);
        $this->assertSame('AC-33', $item['item_id']);
    }

    public function test_item_name_defaults_when_all_fields_missing(): void
    {
        $item = $this->dispatchAndRetrieveItem([
            'transaction_id' => 'TRANS-DEFAULT-1',
            'amount' => 59.0,
            'currency' => 'EUR',
        ], 'sid-default');

        $this->assertSame('Prenotazione', $item['item_name']);
        $this->assertSame('TRANS-DEFAULT-1', $item['item_id']);
    }

    public function test_ga4_and_gtm_share_transaction_id_when_missing_reservation(): void
    {
        $sid = 'shared-sid-123';
        $payload = [
            'value' => 218.5,
            'currency' => 'EUR',
            'email' => 'ospite@example.com',
            'from_date' => '2024-09-10',
        ];

        $expected_transaction_id = \FpHic\hic_ga4_resolve_transaction_id($payload, $sid);
        $this->assertNotSame('', $expected_transaction_id, 'Expected GA4 resolver to provide a transaction ID.');

        $event = $this->dispatchAndRetrieveEvent($payload, $sid);
        $this->assertArrayHasKey('ecommerce', $event);
        $this->assertSame($expected_transaction_id, $event['ecommerce']['transaction_id']);
        $this->assertArrayHasKey('transaction_id', $event);
        $this->assertSame($expected_transaction_id, $event['transaction_id']);
        $this->assertArrayHasKey('items', $event['ecommerce']);
        $this->assertNotEmpty($event['ecommerce']['items']);
        $this->assertSame($expected_transaction_id, $event['ecommerce']['items'][0]['item_id']);

        $queue_key = \FpHic\hic_get_gtm_queue_option_key($sid);
        if ($queue_key !== '') {
            delete_option($queue_key);
        }

        $this->assertTrue(\FpHic\hic_dispatch_gtm_reservation($payload, $sid), 'Expected GTM dispatcher fallback to succeed.');

        $dispatch_events = \FpHic\hic_get_and_clear_gtm_events_for_sid($sid);
        $this->assertNotEmpty($dispatch_events, 'Expected GTM dispatcher to queue an event.');
        $this->assertSame($expected_transaction_id, $dispatch_events[0]['ecommerce']['transaction_id']);
        $this->assertArrayHasKey('transaction_id', $dispatch_events[0]);
        $this->assertSame($expected_transaction_id, $dispatch_events[0]['transaction_id']);
    }
}
