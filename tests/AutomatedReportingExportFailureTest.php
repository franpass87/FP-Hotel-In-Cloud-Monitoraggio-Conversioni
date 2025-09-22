<?php declare(strict_types=1);

namespace FpHic\AutomatedReporting {
    function fopen($filename, $mode)
    {
        if (!empty($GLOBALS['hic_test_fail_fopen_directory']) && is_string($GLOBALS['hic_test_fail_fopen_directory'])) {
            $directory = rtrim($GLOBALS['hic_test_fail_fopen_directory'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

            if (strpos($filename, $directory) === 0) {
                return false;
            }
        }

        return \fopen($filename, $mode);
    }
}

namespace {

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action, $arg = false, $die = true) {
        return true;
    }
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data) {
        $GLOBALS['ajax_response'] = ['success' => false, 'data' => $data];
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data) {
        $GLOBALS['ajax_response'] = ['success' => true, 'data' => $data];
    }
}

require_once __DIR__ . '/../includes/automated-reporting.php';

final class AutomatedReportingExportFailureTest extends TestCase
{
    /** @var mixed */
    private $originalWpdb;

    private string $tempExportDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetManagerInstance();
        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['ajax_response'] = null;
        $GLOBALS['hic_test_fail_fopen_directory'] = null;
        $_POST = [];
        $_GET = [];

        $this->tempExportDir = sys_get_temp_dir() . '/hic-unwritable-' . uniqid('', true) . DIRECTORY_SEPARATOR;
        if (!is_dir($this->tempExportDir) && !mkdir($concurrentDirectory = $this->tempExportDir, 0777, true) && !is_dir($concurrentDirectory)) {
            $this->fail('Unable to create temporary export directory for tests.');
        }

        global $wpdb;
        $wpdb = new class {
            public $prefix = 'wp_';

            /** @var array<int, array<string, mixed>> */
            private array $data;

            public function __construct()
            {
                $this->data = [
                    [
                        'id' => 1,
                        'gclid' => 'gclid-123',
                        'fbclid' => 'fbclid-123',
                        'msclkid' => 'msclkid-123',
                        'ttclid' => 'ttclid-123',
                        'gbraid' => 'gbraid-123',
                        'wbraid' => 'wbraid-123',
                        'sid' => 'sid-123',
                        'utm_source' => 'google',
                        'utm_medium' => 'cpc',
                        'utm_campaign' => 'campaign',
                        'utm_content' => 'content',
                        'utm_term' => 'term',
                        'created_at' => '2024-01-01 00:00:00',
                    ],
                ];
            }

            public function prepare($query, ...$args)
            {
                return $query;
            }

            public function get_results($query, $output = ARRAY_A)
            {
                return $this->data;
            }
        };
    }

    protected function tearDown(): void
    {
        global $wpdb;
        if ($this->originalWpdb !== null) {
            $wpdb = $this->originalWpdb;
        } elseif (isset($GLOBALS['wpdb'])) {
            unset($GLOBALS['wpdb']);
        }

        if (is_dir($this->tempExportDir)) {
            $this->removeDirectory($this->tempExportDir);
        }

        $this->resetManagerInstance();
        $_POST = [];
        $_GET = [];
        $GLOBALS['ajax_response'] = null;
        $GLOBALS['hic_test_fail_fopen_directory'] = null;
        parent::tearDown();
    }

    public function test_generate_raw_csv_export_with_unwritable_directory_throws_exception(): void
    {
        $manager = \FpHic\AutomatedReporting\AutomatedReportingManager::instance();
        $this->setExportDirectory($manager, $this->tempExportDir);

        $GLOBALS['hic_test_fail_fopen_directory'] = $this->tempExportDir;

        $method = new \ReflectionMethod($manager, 'generate_raw_csv_export');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to open export file for writing. Please verify the export directory is writable.');

        try {
            $method->invoke($manager, [['id' => 1]], 'last_7_days');
        } finally {
            $GLOBALS['hic_test_fail_fopen_directory'] = null;
        }
    }

    public function test_ajax_export_data_csv_with_unwritable_directory_returns_error_response(): void
    {
        $manager = \FpHic\AutomatedReporting\AutomatedReportingManager::instance();
        $this->setExportDirectory($manager, $this->tempExportDir);

        $GLOBALS['hic_test_fail_fopen_directory'] = $this->tempExportDir;

        $_POST = [
            'nonce' => 'valid',
            'period' => 'last_7_days',
        ];

        try {
            $manager->ajax_export_data_csv();
        } finally {
            $GLOBALS['hic_test_fail_fopen_directory'] = null;
        }

        $this->assertIsArray($GLOBALS['ajax_response']);
        $this->assertFalse($GLOBALS['ajax_response']['success']);
        $this->assertSame(
            'Export failed: Unable to open export file for writing. Please verify the export directory is writable.',
            $GLOBALS['ajax_response']['data']
        );
    }

    private function setExportDirectory($manager, string $directory): void
    {
        $reflection = new \ReflectionClass($manager);
        $property = $reflection->getProperty('export_dir');
        $property->setAccessible(true);
        $property->setValue($manager, rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
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
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}

}
