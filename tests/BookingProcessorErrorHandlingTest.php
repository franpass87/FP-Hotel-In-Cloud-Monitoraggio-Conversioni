<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use FpHic\Helpers;

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) {
        return json_encode($data);
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string
    {
        return 'uuid-unit-test';
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response)
    {
        return $response['response']['code'] ?? 0;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response)
    {
        return $response['body'] ?? '';
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing)
    {
        return $thing instanceof WP_Error;
    }
}

require_once __DIR__ . '/../includes/log-manager.php';
require_once __DIR__ . '/../includes/helpers-logging.php';
require_once __DIR__ . '/../includes/integrations/ga4.php';

final class BookingProcessorErrorHandlingTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = sys_get_temp_dir() . '/hic-booking-error.log';
        update_option('hic_log_file', $this->logFile);
        Helpers\hic_clear_option_cache('log_file');
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        unset($GLOBALS['hic_log_manager']);

        if (!isset($GLOBALS['hic_test_filters'])) {
            $GLOBALS['hic_test_filters'] = [];
        }
        $GLOBALS['hic_test_filters']['hic_ga4_payload'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['hic_test_filters']['hic_ga4_payload']);
        parent::tearDown();
    }

    public function testGa4ExceptionIsCaughtAndLogged(): void
    {
        update_option('hic_measurement_id', 'G-UNITTEST');
        update_option('hic_api_secret', 'test-secret');
        update_option('hic_tracking_mode', 'ga4_only');

        Helpers\hic_clear_option_cache('measurement_id');
        Helpers\hic_clear_option_cache('api_secret');
        Helpers\hic_clear_option_cache('tracking_mode');

        add_filter('hic_ga4_payload', function ($payload) {
            throw new \RuntimeException('Simulated GA4 failure');
        });

        $bookingData = [
            'email' => 'guest@example.com',
            'reservation_id' => 'ABC123',
            'amount' => 120.0,
            'currency' => 'EUR',
        ];

        $result = \FpHic\hic_process_booking_data($bookingData);

        $this->assertFalse($result, 'Booking processing should fail gracefully when GA4 throws');

        $this->assertFileExists($this->logFile);
        $logContents = file_get_contents($this->logFile);
        $this->assertIsString($logContents);
        $this->assertStringContainsString(
            'Errore critico processando prenotazione: Simulated GA4 failure',
            $logContents
        );
    }
}
