<?php
namespace {
use PHPUnit\Framework\TestCase;
use FpHic\Helpers;

require_once __DIR__ . '/../includes/log-manager.php';

final class LogMessageFilterTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $log_file = sys_get_temp_dir() . '/hic-log-filter.log';
        update_option('hic_log_file', $log_file);
        if (file_exists($log_file)) {
            unlink($log_file);
        }
    }

    public function test_default_filter_masks_sensitive_data(): void {
        $manager = new \HIC_Log_Manager();
        $manager->info('contact john.doe@example.com phone 1234567890 token=abcdef');

        $log_file = Helpers\hic_get_log_file();
        $this->assertFileExists($log_file);
        $contents = file_get_contents($log_file);
        $this->assertStringNotContainsString('john.doe@example.com', $contents);
        $this->assertStringNotContainsString('1234567890', $contents);
        $this->assertStringNotContainsString('abcdef', $contents);
        $this->assertStringContainsString('[masked-email]', $contents);
    }
}
}
