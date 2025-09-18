<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

final class BookingProcessorRefundTrackingTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        \FpHic\Helpers\hic_clear_option_cache();

        $this->logFile = sys_get_temp_dir() . '/hic-refund-tracking-disabled.log';
        update_option('hic_log_file', $this->logFile);
        \FpHic\Helpers\hic_clear_option_cache('log_file');
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        unset($GLOBALS['hic_log_manager']);

        update_option('hic_refund_tracking', '0');
        \FpHic\Helpers\hic_clear_option_cache('refund_tracking');

        update_option('hic_tracking_mode', 'ga4_only');
        \FpHic\Helpers\hic_clear_option_cache('tracking_mode');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    public function testRefundProcessingSucceedsWhenTrackingDisabled(): void
    {
        $bookingData = [
            'email' => 'cancelled@example.com',
            'reservation_id' => 'CANCELLED-123',
            'amount' => 199.99,
            'currency' => 'EUR',
            'status' => 'cancelled',
        ];

        $result = \FpHic\hic_process_booking_data($bookingData);

        $this->assertTrue($result, 'Refund processing should succeed when tracking is disabled');

        $this->assertFileExists($this->logFile);
        $logContents = file_get_contents($this->logFile);
        $this->assertIsString($logContents);
        $this->assertStringContainsString('refund detected but tracking disabled, skipping refund events', $logContents);
        $this->assertStringContainsString('Successi: 0, Errori: 0', $logContents);
    }
}
