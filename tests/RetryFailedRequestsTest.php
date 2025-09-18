<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/log-manager.php';

class RetryFailedRequestsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        global $wpdb, $hic_last_request, $retry_log_messages, $hic_test_http_error, $hic_test_http_error_urls, $hic_test_http_mock;
        $hic_last_request = null;
        $retry_log_messages = [];
        $hic_test_http_error = null;
        $hic_test_http_error_urls = null;
        $hic_test_http_mock = null;

        unset($GLOBALS['hic_test_filters']['hic_log_message']);
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
            public $inserted = [];

            public function get_results($query) {
                return $this->rows;
            }

            public function delete($table, $where) {
                $this->deleted[] = $where;
                $this->rows = array_values(array_filter($this->rows, function($row) use ($where) {
                    foreach ($where as $key => $value) {
                        if (!property_exists($row, $key) || (string) $row->$key !== (string) $value) {
                            return true;
                        }
                    }
                    return false;
                }));
                return true;
            }

            public function update($table, $data, $where, $formats = null, $where_formats = null) {
                $this->updated[] = ['data' => $data, 'where' => $where];
                foreach ($this->rows as $index => $row) {
                    $matches = true;
                    foreach ($where as $key => $value) {
                        if (!property_exists($row, $key) || (string) $row->$key !== (string) $value) {
                            $matches = false;
                            break;
                        }
                    }
                    if ($matches) {
                        $new_row = clone $row;
                        foreach ($data as $key => $value) {
                            $new_row->$key = $value;
                        }
                        $this->rows[$index] = $new_row;
                    }
                }
                return true;
            }

            public function insert($table, $data, $formats = null) {
                $this->inserted[] = ['data' => $data, 'table' => $table, 'formats' => $formats];
                return true;
            }
        };
    }

    protected function tearDown(): void {
        global $hic_test_http_error, $hic_test_http_error_urls, $hic_test_http_mock;
        $hic_test_http_error = null;
        $hic_test_http_error_urls = null;
        $hic_test_http_mock = null;
        parent::tearDown();
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

    public function test_persistent_http_500_retries_until_cleanup_without_duplicates() {
        global $wpdb, $hic_test_http_mock;

        $wpdb->rows = [(object)[
            'id' => 3,
            'endpoint' => 'https://example.com/fail',
            'payload' => json_encode(['method' => 'GET']),
            'attempts' => 1,
            'last_try' => '2000-01-01 00:00:00',
        ]];

        $hic_test_http_mock = static function() {
            return [
                'body' => '{}',
                'response' => ['code' => 500],
            ];
        };

        for ($i = 0; $i < 5; $i++) {
            \FpHic\Helpers\hic_retry_failed_requests();
            if (!empty($wpdb->rows)) {
                $wpdb->rows[0]->last_try = '2000-01-01 00:00:00';
            }
        }

        $this->assertCount(4, $wpdb->updated, 'Each failure should update the existing queue entry.');
        $attempts = array_map(static function ($update) {
            return $update['data']['attempts'] ?? null;
        }, $wpdb->updated);
        $this->assertSame([2, 3, 4, 5], $attempts, 'Attempts should increment up to five.');

        $lastUpdate = end($wpdb->updated);
        $this->assertIsArray($lastUpdate);
        $this->assertSame('HTTP 500', $lastUpdate['data']['last_error'] ?? null);

        $this->assertEmpty($wpdb->rows, 'The entry should be removed after the fifth failure.');
        $this->assertCount(1, $wpdb->deleted, 'The queue entry should be deleted once the maximum is reached.');
        $this->assertEmpty($wpdb->inserted, 'Processing the queue should not create duplicate rows.');
    }
}
