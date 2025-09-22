<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use FpHic\Helpers;

final class BookingProcessorSkipFilterTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = sys_get_temp_dir() . '/hic-booking-skip.log';
        update_option('hic_log_file', $this->logFile);
        Helpers\hic_clear_option_cache('log_file');

        unset($GLOBALS['hic_log_manager']);

        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        if (!isset($GLOBALS['hic_test_filters'])) {
            $GLOBALS['hic_test_filters'] = [];
        }

        $GLOBALS['hic_test_filters']['hic_should_track_reservation'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['hic_test_filters']['hic_should_track_reservation']);
        unset($GLOBALS['hic_log_manager']);

        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    public function test_booking_skip_returns_true_and_logs(): void
    {
        add_filter('hic_should_track_reservation', function ($should_track, $reservation) {
            return false;
        }, 10, 2);

        $bookingData = [
            'email' => 'skip@example.com',
            'reservation_id' => 'SKIP-123',
            'amount' => 150,
            'currency' => 'EUR',
        ];

        $result = \FpHic\hic_process_booking_data($bookingData);

        $this->assertIsArray($result, 'Expected structured result for skipped bookings');
        $this->assertSame('success', $result['status'], 'Skipping tracking should not produce a failure status');
        $this->assertTrue(!empty($result['should_mark_processed']), 'Skipped bookings should still be marked as processed');
        $this->assertContains('tracking_skipped', $result['messages'], 'Tracking skip should be noted in result messages');

        $this->assertFileExists($this->logFile);
        $logContents = file_get_contents($this->logFile);
        $this->assertIsString($logContents);
        $this->assertStringContainsString('tracciamento ignorato da hic_should_track_reservation', $logContents);
        $this->assertStringContainsString('Stato: success', $logContents);
        $this->assertStringContainsString('Messages: tracking_skipped', $logContents);
    }
}
