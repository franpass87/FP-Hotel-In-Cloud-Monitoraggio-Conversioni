<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/log-manager.php';

class CleanupFailedRequestsTest extends TestCase {
    protected function setUp(): void {
        global $wpdb, $cleanup_log_messages;
        $cleanup_log_messages = [];
        add_filter('hic_log_message', function($msg, $level) {
            global $cleanup_log_messages;
            $cleanup_log_messages[] = $msg;
            return $msg;
        }, 10, 2);
        $wpdb = new class {
            public $prefix = 'wp_';
            public $query_sql = '';
            public $last_error = '';
            public function prepare($query, $value) { return str_replace('%s', $value, $query); }
            public function query($sql) { $this->query_sql = $sql; return 2; }
        };
    }

    public function test_cleanup_deletes_old_records_and_logs_message() {
        global $wpdb, $cleanup_log_messages;
        $deleted = \FpHic\Helpers\hic_cleanup_failed_requests(30);
        $this->assertSame(2, $deleted);
        $this->assertStringContainsString('DELETE FROM wp_hic_failed_requests', $wpdb->query_sql);
        $this->assertStringContainsString('Removed 2 records', $cleanup_log_messages[0] ?? '');
    }
}
