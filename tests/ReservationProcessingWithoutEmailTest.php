<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use FpHic\Helpers;

final class ReservationProcessingWithoutEmailTest extends TestCase
{
    private string $logFile;

    /** @var array<int, array{message:string, level:string}> */
    private static array $capturedLogs = [];

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../includes/constants.php';
        require_once __DIR__ . '/../includes/functions.php';
        require_once __DIR__ . '/../includes/log-manager.php';
        require_once __DIR__ . '/../includes/helpers-logging.php';
        require_once __DIR__ . '/../includes/api/polling.php';
        require_once __DIR__ . '/../includes/integrations/ga4.php';
        require_once __DIR__ . '/../includes/integrations/gtm.php';
        require_once __DIR__ . '/../includes/integrations/facebook.php';
        require_once __DIR__ . '/../includes/integrations/brevo.php';

        $this->logFile = sys_get_temp_dir() . '/hic-no-email.log';
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        update_option('hic_log_file', $this->logFile);
        Helpers\hic_clear_option_cache('log_file');

        unset($GLOBALS['hic_log_manager']);

        $GLOBALS['hic_test_transients'] = [];
        $GLOBALS['hic_test_transient_expirations'] = [];

        self::$capturedLogs = [];
        add_filter('hic_log_message', [self::class, 'captureLogMessage'], 20, 2);

        update_option('hic_measurement_id', 'G-TEST');
        update_option('hic_api_secret', 'test-secret');
        update_option('hic_tracking_mode', 'hybrid');
        update_option('hic_gtm_enabled', '1');
        update_option('hic_fb_pixel_id', '');
        update_option('hic_fb_access_token', '');
        update_option('hic_brevo_enabled', '1');
        update_option('hic_brevo_api_key', 'test-key');
        update_option('hic_realtime_brevo_sync', '1');
        update_option('hic_brevo_event_endpoint', 'https://brevo.test/track');
        update_option('hic_connection_type', 'api');
        update_option('hic_admin_email', '');
        update_option('hic_synced_res_ids', []);

        Helpers\hic_clear_option_cache('measurement_id');
        Helpers\hic_clear_option_cache('api_secret');
        Helpers\hic_clear_option_cache('tracking_mode');
        Helpers\hic_clear_option_cache('gtm_enabled');
        Helpers\hic_clear_option_cache('fb_pixel_id');
        Helpers\hic_clear_option_cache('fb_access_token');
        Helpers\hic_clear_option_cache('brevo_enabled');
        Helpers\hic_clear_option_cache('brevo_api_key');
        Helpers\hic_clear_option_cache('realtime_brevo_sync');
        Helpers\hic_clear_option_cache('brevo_event_endpoint');
        Helpers\hic_clear_option_cache('connection_type');
        Helpers\hic_clear_option_cache('admin_email');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['hic_test_filters']['hic_log_message']);
        unset($GLOBALS['hic_log_manager']);

        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    public function testReservationWithoutEmailProcessesAndSkipsBrevo(): void
    {
        $reservation = [
            'id' => 'NOEMAIL-1',
            'from_date' => '2024-02-01',
            'to_date' => '2024-02-05',
            'price' => 199.0,
            'currency' => 'EUR',
            'guest_first_name' => 'Mario',
            'guest_last_name' => 'Rossi',
            'accommodation_id' => 'ROOM-1',
            'accommodation_name' => 'Deluxe Room',
            'sid' => 'SID-123',
        ];

        $result = \FpHic\hic_process_reservations_batch([$reservation]);

        $this->assertSame(1, $result['new'], 'Reservation without email should still be processed.');
        $this->assertSame(0, $result['errors'], 'Processing should not report errors for missing email.');
        $this->assertSame(0, $result['skipped'], 'Reservation should not be counted as skipped.');

        $processedLog = self::findLogContaining('Reservation NOEMAIL-1: processed');
        $this->assertNotNull($processedLog, 'hic_process_single_reservation should be invoked for missing email reservations.');

        $summaryLog = self::findLogContaining('Reservation NOEMAIL-1 dispatched successfully');
        $this->assertNotNull($summaryLog, 'Dispatch summary log should be emitted.');
        $this->assertStringContainsString('GA4=success', $summaryLog);
        $this->assertStringContainsString('GTM=success', $summaryLog);
        $this->assertStringContainsString('Meta Pixel=skipped', $summaryLog);
        $this->assertStringContainsString('Brevo contact=skipped', $summaryLog);
        $this->assertStringContainsString('Brevo event=skipped', $summaryLog);
        $this->assertStringContainsString('Brevo contact (missing email)', $summaryLog);

        $brevoContactLog = self::findLogContaining('Brevo contact skipped for reservation NOEMAIL-1');
        $this->assertNotNull($brevoContactLog, 'Brevo contact should log the missing email skip.');

        $brevoEventLog = self::findLogContaining('Brevo reservation_created event SKIPPED');
        $this->assertNotNull($brevoEventLog, 'Brevo reservation_created event should be marked as skipped.');

        $ga4Log = self::findLogContaining('GA4 HIC dispatch');
        $this->assertNotNull($ga4Log, 'GA4 dispatch log should be present.');

        $gtmLog = self::findLogContaining('GTM dispatch');
        $this->assertNotNull($gtmLog, 'GTM dispatch log should be present.');
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
}
