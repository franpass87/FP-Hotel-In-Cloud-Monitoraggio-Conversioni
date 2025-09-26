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

    public function test_default_filter_masks_sensitive_data_in_structures(): void {
        $manager = new \HIC_Log_Manager();
        $data = [
            'email' => 'jane.doe@example.com',
            'token' => 'secret123',
            'number' => 12345,
            'nested' => (object) [
                'phone' => '1234567890',
                'inner' => ['api_key' => 'abcdef']
            ]
        ];
        $manager->info($data);

        $log_file = Helpers\hic_get_log_file();
        $this->assertFileExists($log_file);
        $contents = file_get_contents($log_file);
        $this->assertStringNotContainsString('jane.doe@example.com', $contents);
        $this->assertStringNotContainsString('secret123', $contents);
        $this->assertStringNotContainsString('1234567890', $contents);
        $this->assertStringNotContainsString('12345', $contents);
        $this->assertStringContainsString('[masked-email]', $contents);
        $this->assertStringContainsString('[masked]', $contents);
        $this->assertStringContainsString('[masked-phone]', $contents);
        $this->assertStringContainsString('[masked-number]', $contents);
    }

    public function test_default_filter_masks_additional_email_and_phone_keys(): void
    {
        $manager = new \HIC_Log_Manager();
        $data = [
            'guestEmail' => 'guest@example.com',
            'contact_phone' => '+39 123 456 7890',
            'details' => [
                'customerEmail' => 'customer@example.com',
                'mobileNumber' => '555-1234',
            ],
        ];

        $manager->info($data);

        $log_file = Helpers\hic_get_log_file();
        $this->assertFileExists($log_file);
        $contents = file_get_contents($log_file);
        $this->assertStringNotContainsString('guest@example.com', $contents);
        $this->assertStringNotContainsString('customer@example.com', $contents);
        $this->assertStringNotContainsString('+39 123 456 7890', $contents);
        $this->assertStringNotContainsString('555-1234', $contents);
        $this->assertSame(2, substr_count($contents, '[masked-email]'));
        $this->assertSame(2, substr_count($contents, '[masked-phone]'));
    }
}
}
