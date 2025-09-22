<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use FpHic\Helpers;

final class BrevoSuccessResponseTest extends TestCase
{
    private string $logFile;

    /**
     * @var array<int, array{message:string, level:string}>
     */
    private static array $capturedLogs = [];

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../includes/constants.php';
        require_once __DIR__ . '/../includes/functions.php';
        require_once __DIR__ . '/../includes/log-manager.php';
        require_once __DIR__ . '/../includes/helpers-logging.php';
        require_once __DIR__ . '/../includes/integrations/brevo.php';
        require_once __DIR__ . '/../includes/api/polling.php';

        global $hic_test_options, $hic_test_http_mock;
        $hic_test_options = [];
        $hic_test_http_mock = null;

        $this->logFile = sys_get_temp_dir() . '/hic-brevo-success.log';
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        update_option('hic_log_file', $this->logFile);
        Helpers\hic_clear_option_cache('log_file');

        unset($GLOBALS['hic_log_manager']);

        update_option('hic_brevo_api_key', 'test-key');
        update_option('hic_brevo_enabled', '1');
        update_option('hic_realtime_brevo_sync', '1');
        update_option('hic_tracking_mode', 'none');
        update_option('hic_connection_type', 'api');
        update_option('hic_admin_email', '');
        update_option('hic_synced_res_ids', []);
        update_option('hic_brevo_event_endpoint', 'https://brevo.test/track');
        update_option('hic_fb_pixel_id', '');
        update_option('hic_fb_access_token', '');
        update_option('hic_measurement_id', '');
        update_option('hic_api_secret', '');
        update_option('hic_gtm_enabled', '0');

        Helpers\hic_clear_option_cache();

        self::$capturedLogs = [];
        add_filter('hic_log_message', [self::class, 'captureLogMessage'], 20, 2);
    }

    protected function tearDown(): void
    {
        global $hic_test_http_mock;
        $hic_test_http_mock = null;

        unset($GLOBALS['hic_test_filters']['hic_log_message']);
        unset($GLOBALS['hic_log_manager']);

        if (isset($this->logFile) && file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    public static function captureLogMessage($message, $level): mixed
    {
        if (is_array($message) || is_object($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE);
        } elseif (!is_string($message)) {
            $message = (string) $message;
        }

        self::$capturedLogs[] = [
            'message' => $message,
            'level' => (string) $level,
        ];

        return $message;
    }

    private static function findLogContaining(string $needle): ?string
    {
        foreach (self::$capturedLogs as $entry) {
            if (strpos($entry['message'], $needle) !== false) {
                return $entry['message'];
            }
        }

        return null;
    }

    public function testSuccessfulBrevoDispatchWithEmptyBodies(): void
    {
        global $hic_test_http_mock;

        $contactEndpoint = 'https://api.brevo.com/v3/contacts';
        $eventEndpoint = Helpers\hic_get_brevo_event_endpoint();

        $hic_test_http_mock = static function ($url, $args) use ($contactEndpoint, $eventEndpoint) {
            if (strpos($url, $contactEndpoint) !== false) {
                return [
                    'body' => '',
                    'response' => ['code' => 201],
                ];
            }

            if (strpos($url, $eventEndpoint) !== false) {
                return [
                    'body' => '',
                    'response' => ['code' => 204],
                ];
            }

            return [
                'body' => '{}',
                'response' => ['code' => 200],
            ];
        };

        $reservation = [
            'transaction_id' => 'RES-204',
            'original_price' => 150.0,
            'currency' => 'EUR',
            'email' => 'guest@example.com',
            'from_date' => '2024-01-05',
            'to_date' => '2024-01-07',
        ];

        $contactResult = \FpHic\hic_dispatch_brevo_reservation($reservation);
        $this->assertTrue($contactResult, 'Contact dispatch should succeed with empty response body.');

        $eventResult = \FpHic\hic_send_brevo_reservation_created_event($reservation);
        $this->assertIsArray($eventResult);
        $this->assertArrayHasKey('success', $eventResult);
        $this->assertTrue($eventResult['success'], 'Event dispatch should succeed with empty response body.');
        $this->assertArrayHasKey('skipped', $eventResult);
        $this->assertFalse($eventResult['skipped'], 'Event dispatch should not be skipped.');

        $dispatchResult = \FpHic\hic_dispatch_reservation($reservation, ['id' => 'RES-204']);
        $this->assertTrue($dispatchResult, 'Reservation dispatch should complete successfully.');

        $summaryLog = self::findLogContaining('Reservation RES-204 dispatched successfully');
        $this->assertNotNull($summaryLog, 'Dispatch summary log should indicate success.');
        $this->assertStringContainsString('Brevo contact=success', $summaryLog);
        $this->assertStringContainsString('Brevo event=success', $summaryLog);

        $this->assertNull(
            self::findLogContaining('Brevo contact dispatch FAILED'),
            'Brevo contact failure log should not be present.'
        );
        $this->assertNull(
            self::findLogContaining('Failed to send Brevo reservation_created event in dispatch'),
            'Brevo event retry log should not be present.'
        );
    }
}
