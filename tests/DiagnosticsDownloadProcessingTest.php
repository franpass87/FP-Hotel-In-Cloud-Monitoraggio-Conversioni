<?php declare(strict_types=1);

namespace {
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/bootstrap.php';
    require_once dirname(__DIR__) . '/includes/admin/diagnostics.php';

    if (!function_exists('check_ajax_referer')) {
        function check_ajax_referer($action, $arg = false, $die = true) {
            return true;
        }
    }

    if (!function_exists('__')) {
        function __($text, $domain = null) {
            return $text;
        }
    }

    if (!function_exists('wp_date')) {
        function wp_date($format, $timestamp = null, $timezone = null) {
            $time = $timestamp ?? time();
            return gmdate($format, $time);
        }
    }

    if (!function_exists('wp_send_json_error')) {
        function wp_send_json_error($data) {
            $GLOBALS['ajax_response'] = array('success' => false, 'data' => $data);
        }
    }

    if (!function_exists('wp_send_json_success')) {
        function wp_send_json_success($data) {
            $GLOBALS['ajax_response'] = array('success' => true, 'data' => $data);
        }
    }

    final class DiagnosticsDownloadProcessingTest extends TestCase
    {
        /** @var callable|null */
        private $manualBookingsFilter = null;

        protected function setUp(): void
        {
            parent::setUp();
            global $hic_test_options;
            $hic_test_options = array();
            hic_clear_option_cache();
            $GLOBALS['hic_test_download_bookings'] = array();
            $GLOBALS['ajax_response'] = null;
            update_option('hic_downloaded_booking_ids', array());
        }

        protected function tearDown(): void
        {
            parent::tearDown();
            global $hic_test_options;
            $hic_test_options = array();
            hic_clear_option_cache();
            $GLOBALS['hic_test_download_bookings'] = array();
            $GLOBALS['ajax_response'] = null;
            if ($this->manualBookingsFilter !== null) {
                remove_filter('hic_diagnostics_manual_bookings', $this->manualBookingsFilter);
                $this->manualBookingsFilter = null;
            }
            $_POST = array();
        }

        public function test_failed_bookings_are_not_marked_as_downloaded(): void
        {
            update_option('hic_connection_type', 'api');
            update_option('hic_property_id', 'prop_123');
            update_option('hic_api_url', 'https://example.com');
            update_option('hic_api_email', 'user@example.com');
            update_option('hic_api_password', 'secret');
            hic_clear_option_cache();

            $GLOBALS['hic_test_download_bookings'] = array(
                array(
                    'id' => 'booking-success',
                    'client_email' => 'success@example.com',
                    'client_first_name' => 'Alice',
                    'client_last_name' => 'Rossi',
                    'created_at' => '2024-03-01 10:00:00',
                    'from_date' => '2024-03-10',
                    'to_date' => '2024-03-12',
                ),
                array(
                    'id' => 'booking-failure',
                    'client_email' => 'not-an-email',
                    'client_first_name' => 'Bob',
                    'client_last_name' => 'Bianchi',
                    'created_at' => '2024-02-28 10:00:00',
                    'from_date' => '2024-03-15',
                    'to_date' => '2024-03-16',
                ),
            );

            $_POST = array('nonce' => 'valid');

            $this->manualBookingsFilter = static function () {
                return $GLOBALS['hic_test_download_bookings'] ?? array();
            };
            add_filter('hic_diagnostics_manual_bookings', $this->manualBookingsFilter);

            hic_ajax_download_latest_bookings();

            $response = $GLOBALS['ajax_response'];
            $this->assertIsArray($response);
            $this->assertTrue($response['success']);

            $data = $response['data'];
            $this->assertSame(array('booking-success', 'booking-failure'), $data['booking_ids']);
            $this->assertSame(array('booking-success'), $data['marked_booking_ids']);
            $this->assertSame(array('booking-failure'), $data['skipped_booking_ids']);

            $skippedEntries = $data['skipped_bookings'];
            $this->assertNotEmpty($skippedEntries);
            $this->assertSame('booking-failure', $skippedEntries[0]['booking_id']);
            $this->assertSame('failed', $skippedEntries[0]['status']);

            $this->assertSame(array('booking-success'), get_option('hic_downloaded_booking_ids'));
        }
    }
}
