<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/log-manager.php';

class RetryFailedRequestsTest extends TestCase {
    protected function setUp(): void {
        global $wpdb, $hic_last_request, $retry_log_messages;
        $hic_last_request = null;
        $retry_log_messages = [];
        add_filter('hic_log_message', function($msg, $level) {
            global $retry_log_messages;
            $retry_log_messages[] = $msg;
            return $msg;
        }, 10, 2);
        $wpdb = new class {
            public $prefix = 'wp_';
            public $rows = [];
            public $deleted = [];
            public $updated = [];
            public function get_results($query) { return $this->rows; }
            public function delete($table, $where) { $this->deleted[] = $where; $this->rows = []; }
            public function update($table, $data, $where, $formats = null, $where_formats = null) { $this->updated[] = compact('data', 'where'); }
        };
    }

    public function test_invalid_json_logs_and_removes_row() {
        global $wpdb, $retry_log_messages;
        $wpdb->rows = [(object)[
            'id' => 1,
            'endpoint' => 'https://example.com',
            'payload' => '{"incomplete":',
            'attempts' => 0,
            'last_try' => '2024-01-01 00:00:00',
        ]];
        \FpHic\Helpers\hic_retry_failed_requests();
        $this->assertCount(1, $wpdb->deleted);
        $this->assertStringContainsString('JSON decode error', $retry_log_messages[0] ?? '');
    }

    public function test_valid_request_is_retried_and_deleted() {
        global $wpdb, $hic_last_request;
        $wpdb->rows = [(object)[
            'id' => 2,
            'endpoint' => 'https://example.com',
            'payload' => json_encode(['method' => 'GET']),
            'attempts' => 0,
            'last_try' => '2024-01-01 00:00:00',
        ]];
        \FpHic\Helpers\hic_retry_failed_requests();
        $this->assertNotNull($hic_last_request);
        $this->assertCount(1, $wpdb->deleted);
    }
}
