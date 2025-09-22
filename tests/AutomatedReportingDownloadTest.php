<?php declare(strict_types=1);

namespace {

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir($path = null) {
        return ['basedir' => sys_get_temp_dir(), 'baseurl' => ''];
    }
}

if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($filename) {
        $sanitized = preg_replace('/[^A-Za-z0-9.\-_]/', '', (string) $filename);
        return is_string($sanitized) ? $sanitized : '';
    }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action, $arg = false, $die = true) {
        return true;
    }
}

if (!function_exists('wp_die')) {
    function wp_die($message) {
        throw new \Exception($message);
    }
}

require_once __DIR__ . '/../includes/automated-reporting.php';

final class AutomatedReportingDownloadTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetManagerInstance();
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $this->resetManagerInstance();
        $_GET = [];
    }

    public function test_handle_download_export_with_missing_directory_returns_error(): void
    {
        $_GET['file'] = 'report.csv';
        $_GET['nonce'] = 'valid';

        $manager = \FpHic\AutomatedReporting\AutomatedReportingManager::instance();

        $missingExportDir = sys_get_temp_dir() . '/hic-missing-' . uniqid('', true) . DIRECTORY_SEPARATOR;
        $managerReflection = new \ReflectionClass($manager);
        $exportDirProperty = $managerReflection->getProperty('export_dir');
        $exportDirProperty->setAccessible(true);
        $exportDirProperty->setValue($manager, $missingExportDir);

        try {
            $manager->handle_download_export();
            $this->fail('Expected wp_die to be triggered for missing export directory.');
        } catch (\Throwable $exception) {
            $this->assertSame('File not found', $exception->getMessage());
        }
    }

    private function resetManagerInstance(): void
    {
        $managerReflection = new \ReflectionClass(\FpHic\AutomatedReporting\AutomatedReportingManager::class);
        if ($managerReflection->hasProperty('instance')) {
            $property = $managerReflection->getProperty('instance');
            $property->setAccessible(true);
            $property->setValue(null, null);
        }
    }
}

}
