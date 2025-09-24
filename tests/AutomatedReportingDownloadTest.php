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

if (!function_exists('nocache_headers')) {
    function nocache_headers() {
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
    /** @var string[] */
    private array $tempDirectories = [];

    protected function setUp(): void
    {
        $this->resetManagerInstance();
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $this->resetManagerInstance();
        $_GET = [];
        $this->cleanupTempDirectories();
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

    public function test_handle_download_export_blocks_disallowed_extension(): void
    {
        $_GET['file'] = 'report.txt';
        $_GET['nonce'] = 'valid';

        $manager = \FpHic\AutomatedReporting\AutomatedReportingManager::instance();
        $exportDir = $this->createExportDirectory();
        file_put_contents($exportDir . 'report.txt', 'demo');

        $managerReflection = new \ReflectionClass($manager);
        $exportDirProperty = $managerReflection->getProperty('export_dir');
        $exportDirProperty->setAccessible(true);
        $exportDirProperty->setValue($manager, $exportDir);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File not found');
        $manager->handle_download_export();
    }

    public function test_handle_download_export_blocks_symbolic_links(): void
    {
        if (!function_exists('symlink')) {
            $this->markTestSkipped('Symlink function not available.');
        }

        $exportDir = $this->createExportDirectory();
        $targetFile = $exportDir . 'valid.csv';
        file_put_contents($targetFile, 'demo');

        $linkFile = $exportDir . 'alias.csv';
        if (@symlink($targetFile, $linkFile) === false) {
            $this->markTestSkipped('Symlinks are not supported in this environment.');
        }

        $_GET['file'] = 'alias.csv';
        $_GET['nonce'] = 'valid';

        $manager = \FpHic\AutomatedReporting\AutomatedReportingManager::instance();
        $managerReflection = new \ReflectionClass($manager);
        $exportDirProperty = $managerReflection->getProperty('export_dir');
        $exportDirProperty->setAccessible(true);
        $exportDirProperty->setValue($manager, $exportDir);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File not found');
        $manager->handle_download_export();
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

    private function createExportDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/hic-export-' . uniqid('', true) . DIRECTORY_SEPARATOR;

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            $this->fail('Unable to create temporary export directory.');
        }

        $this->tempDirectories[] = $directory;

        return $directory;
    }

    private function cleanupTempDirectories(): void
    {
        foreach ($this->tempDirectories as $directory) {
            $this->removeDirectory($directory);
        }

        $this->tempDirectories = [];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}

}
