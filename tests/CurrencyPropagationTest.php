<?php
namespace FpHic\Helpers {
    if (!function_exists(__NAMESPACE__ . '\\hic_get_tracking_ids_by_sid')) {
        function hic_get_tracking_ids_by_sid($sid) {
            return ['gclid' => null, 'fbclid' => null, 'msclkid' => null, 'ttclid' => null, 'gbraid' => null, 'wbraid' => null];
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\fp_normalize_bucket')) {
        function fp_normalize_bucket($gclid, $fbclid) {
            if (!empty($gclid) && trim((string) $gclid) !== '') {
                return 'gads';
            }
            if (!empty($fbclid) && trim((string) $fbclid) !== '') {
                return 'fbads';
            }
            return 'organic';
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\hic_get_utm_params_by_sid')) {
        function hic_get_utm_params_by_sid($sid) {
            return [
                'utm_source' => null,
                'utm_medium' => null,
                'utm_campaign' => null,
                'utm_content' => null,
                'utm_term' => null,
            ];
        }
    }
}

namespace {

use PHPUnit\Framework\TestCase;

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) {
        return json_encode($data);
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string
    {
        return '00000000-0000-4000-8000-000000000000';
    }
}

if (!function_exists('home_url')) {
    function home_url($path = '') {
        return 'https://example.com' . $path;
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return $value;
    }
}

if (!function_exists('__')) {
    function __($text, $domain = null) {
        return $text;
    }
}

final class CurrencyPropagationTest extends TestCase
{
    protected function setUp(): void
    {
        global $hic_test_options, $hic_last_request;
        $hic_test_options = [];
        $hic_last_request = null;

        $this->ensurePluginFunctionsLoaded();
        \FpHic\Helpers\hic_clear_option_cache();
        update_option('hic_currency', 'EUR');
        update_option('hic_gtm_queued_events', []);
    }

    private function ensurePluginFunctionsLoaded(): void
    {
        if (!function_exists('FpHic\\hic_transform_reservation')) {
            require_once __DIR__ . '/../includes/api/polling.php';
        }
        if (!function_exists('FpHic\\hic_dispatch_ga4_reservation')) {
            require_once __DIR__ . '/../includes/integrations/ga4.php';
        }
        if (!function_exists('FpHic\\hic_dispatch_gtm_reservation')) {
            require_once __DIR__ . '/../includes/integrations/gtm.php';
        }
        if (!function_exists('FpHic\\hic_dispatch_pixel_reservation')) {
            require_once __DIR__ . '/../includes/integrations/facebook.php';
        }
        if (!function_exists('FpHic\\hic_dispatch_brevo_reservation')) {
            require_once __DIR__ . '/../includes/integrations/brevo.php';
        }
    }

    public function testTransformReservationUsesCurrencyField(): void
    {
        $reservation = [
            'id' => 'R-100',
            'price' => '100.00',
            'currency' => 'usd',
        ];

        $transformed = \FpHic\hic_transform_reservation($reservation);

        $this->assertSame('USD', $transformed['currency']);
    }

    public function testTransformReservationUsesBookingCurrency(): void
    {
        $reservation = [
            'id' => 'R-200',
            'price' => '150.00',
            'booking_currency' => 'gbp',
        ];

        $transformed = \FpHic\hic_transform_reservation($reservation);

        $this->assertSame('GBP', $transformed['currency']);
    }

    public function testDispatchersPropagateDetectedCurrency(): void
    {
        global $hic_last_request;

        update_option('hic_measurement_id', 'G-TEST123');
        update_option('hic_api_secret', 'test-secret');
        update_option('hic_gtm_enabled', '1');
        update_option('hic_tracking_mode', 'hybrid');
        update_option('hic_fb_pixel_id', '123456789012345');
        update_option('hic_fb_access_token', 'test-token');
        update_option('hic_brevo_api_key', 'brevo-test-key');
        update_option('hic_realtime_brevo_sync', '1');

        $reservation = [
            'id' => 'RES-300',
            'price' => '249.90',
            'unpaid_balance' => '0',
            'guests' => 2,
            'booking_currency' => 'chf',
            'guest_first_name' => 'Alice',
            'guest_last_name' => 'Wonder',
            'guest_email' => 'alice@example.com',
            'from_date' => '2024-11-01',
            'to_date' => '2024-11-05',
            'reservation_code' => 'CODE-123',
            'accommodation_id' => 'ROOM-1',
            'accommodation_name' => 'Lake View',
            'room_name' => 'Lake View Suite',
            'phone' => '+41441234567',
        ];

        $transformed = \FpHic\hic_transform_reservation($reservation);
        $this->assertSame('CHF', $transformed['currency']);

        // GA4 dispatch
        $hic_last_request = null;
        $this->assertTrue(\FpHic\hic_dispatch_ga4_reservation($transformed));
        $this->assertNotNull($hic_last_request);
        $ga_payload = json_decode($hic_last_request['args']['body'], true);
        $this->assertSame('CHF', $ga_payload['events'][0]['params']['currency']);

        // GTM dispatch
        update_option('hic_gtm_queued_events', []);
        $this->assertTrue(\FpHic\hic_dispatch_gtm_reservation($transformed));
        $gtm_events = get_option('hic_gtm_queued_events', []);
        $this->assertNotEmpty($gtm_events);
        $this->assertSame('CHF', $gtm_events[0]['ecommerce']['currency']);

        // Meta Pixel dispatch
        $hic_last_request = null;
        $this->assertTrue(\FpHic\hic_dispatch_pixel_reservation($transformed));
        $this->assertNotNull($hic_last_request);
        $fb_payload = json_decode($hic_last_request['args']['body'], true);
        $this->assertSame('CHF', $fb_payload['data'][0]['custom_data']['currency']);

        // Brevo contact dispatch
        $hic_last_request = null;
        $this->assertTrue(\FpHic\hic_dispatch_brevo_reservation($transformed));
        $this->assertNotNull($hic_last_request);
        $brevo_contact_payload = json_decode($hic_last_request['args']['body'], true);
        $this->assertSame('CHF', $brevo_contact_payload['attributes']['CURRENCY']);

        // Brevo reservation_created event
        $hic_last_request = null;
        $result = \FpHic\hic_send_brevo_reservation_created_event($transformed);
        $this->assertTrue($result['success']);
        $this->assertNotNull($hic_last_request);
        $brevo_event_payload = json_decode($hic_last_request['args']['body'], true);
        $this->assertSame('CHF', $brevo_event_payload['properties']['currency']);
    }
}

}
