<?php
namespace Helpers {
    function hic_get_log_file() { return \FpHic\Helpers\hic_get_log_file(); }
    function hic_is_debug_verbose() { return \FpHic\Helpers\hic_is_debug_verbose(); }
}

namespace {
use PHPUnit\Framework\TestCase;
use FpHic\Helpers;

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}

require_once __DIR__ . '/../includes/log-manager.php';

final class LogManagerTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        date_default_timezone_set('UTC');
        $log_file = sys_get_temp_dir() . '/hic-log-timezone.log';
        update_option('hic_log_file', $log_file);
        \FpHic\Helpers\hic_clear_option_cache('log_file');
        if (file_exists($log_file)) {
            unlink($log_file);
        }
    }

    public function test_log_uses_wordpress_timezone(): void {
        update_option('timezone_string', 'Europe/Rome');

        $manager = new \HIC_Log_Manager();
        $manager->info('timezone check');

        $log_file = Helpers\hic_get_log_file();
        $this->assertFileExists($log_file);
        $line = trim(file_get_contents($log_file));
        $timestamp = substr($line, 1, 19);

        $wp_now = new \DateTime('now', new \DateTimeZone('Europe/Rome'));
        $logged_wp = new \DateTime($timestamp, new \DateTimeZone('Europe/Rome'));
        $this->assertLessThan(5, abs($wp_now->getTimestamp() - $logged_wp->getTimestamp()));

        $server_now = new \DateTime('now', new \DateTimeZone('UTC'));
        $logged_server = new \DateTime($timestamp, new \DateTimeZone('UTC'));
        $this->assertGreaterThan(3000, abs($server_now->getTimestamp() - $logged_server->getTimestamp()));
    }

    public function test_rotates_logs_older_than_default(): void {
        $log_file = sys_get_temp_dir() . '/hic-log-age.log';
        update_option('hic_log_file', $log_file);
        \FpHic\Helpers\hic_clear_option_cache('log_file');
        foreach (glob($log_file . '*') as $file) {
            @unlink($file);
        }

        file_put_contents($log_file, "old line\n");
        $old_time = time() - (8 * 86400);
        update_option('hic_log_created', $old_time);

        $manager = new \HIC_Log_Manager();
        $manager->info('new line');

        $this->assertFileExists($log_file);
        $contents = file_get_contents($log_file);
        $this->assertStringContainsString('new line', $contents);
        $this->assertStringNotContainsString('old line', $contents);

        $rotated = glob($log_file . '.*');
        $this->assertNotEmpty($rotated);

        $this->assertGreaterThan($old_time, get_option('hic_log_created'));
    }
}
}
