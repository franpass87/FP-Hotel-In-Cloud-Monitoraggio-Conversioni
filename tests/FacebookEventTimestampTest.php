<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FacebookEventTimestampTest extends TestCase
{
    /** @var callable|null */
    private $payloadFilter;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure Facebook credentials are set for the integration to run.
        update_option('hic_fb_pixel_id', '1234567890');
        update_option('hic_fb_access_token', 'test-access-token');
        \FpHic\Helpers\hic_clear_option_cache();

        // Simulate a positive timezone installation.
        update_option('timezone_string', 'Europe/Rome');
        update_option('gmt_offset', 2);

        // Freeze time with distinct UTC and local values.
        $GLOBALS['hic_test_current_time'] = [
            'timestamp_gmt'   => 1700000000,
            'timestamp_local' => 1700000000 + 7200,
            'value'           => 1700000000,
        ];

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->payloadFilter !== null) {
            remove_filter('hic_fb_payload', $this->payloadFilter);
            $this->payloadFilter = null;
        }

        unset($GLOBALS['hic_test_current_time']);
    }

    public function test_event_time_and_fbc_use_utc_timestamp_with_positive_timezone(): void
    {
        $capturedPayload = null;
        $this->payloadFilter = function ($payload) use (&$capturedPayload) {
            $capturedPayload = $payload;
            return $payload;
        };
        add_filter('hic_fb_payload', $this->payloadFilter);

        $data = [
            'email'    => 'user@example.com',
            'value'    => '199.99',
            'currency' => 'EUR',
            'room'     => 'Suite',
        ];

        $result = \FpHic\hic_send_to_fb($data, '', 'FBCLID123');

        $this->assertTrue($result, 'The Meta event dispatch should succeed in tests.');
        $this->assertNotNull($capturedPayload, 'The Facebook payload should be captured for assertions.');

        $event = $capturedPayload['data'][0];

        $this->assertSame(1700000000, $event['event_time'], 'Event time must use the UTC timestamp override.');
        $this->assertSame(
            'fb.1.1700000000.FBCLID123',
            $event['user_data']['fbc'][0],
            'The fbc parameter must include the UTC timestamp.'
        );
    }
}
