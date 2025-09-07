<?php
use PHPUnit\Framework\TestCase;
use FpHic\Helpers;

final class LogPathValidationTest extends TestCase {
    public function test_outside_path_returns_default() {
        $result = Helpers\hic_validate_log_path('/etc/passwd');
        $this->assertEquals(WP_CONTENT_DIR . '/uploads/hic-logs/hic-log.txt', $result);
    }

    public function test_inside_path_is_accepted() {
        $path = WP_CONTENT_DIR . '/uploads/hic-logs/custom.log';
        $result = Helpers\hic_validate_log_path($path);
        $this->assertEquals(str_replace('\\', '/', $path), $result);
    }
}
