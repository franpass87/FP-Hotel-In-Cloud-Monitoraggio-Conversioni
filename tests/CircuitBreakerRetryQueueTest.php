<?php

namespace FpHic\Helpers {
    if (!function_exists(__NAMESPACE__ . '\\hic_log')) {
        function hic_log($message) {
            $GLOBALS['circuit_breaker_test_logs'][] = $message;
            return $message;
        }
    }
}

namespace {
    use FpHic\CircuitBreaker\CircuitBreakerManager;
    use PHPUnit\Framework\TestCase;

    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    if (!function_exists('wp_remote_request')) {
        function wp_remote_request($url, $args = array()) {
            $GLOBALS['circuit_breaker_http_calls'][] = ['url' => $url, 'args' => $args];

            return [
                'body' => '{}',
                'response' => ['code' => 200],
            ];
        }
    }

    require_once __DIR__ . '/../includes/circuit-breaker.php';

    final class CircuitBreakerRetryQueueTest extends TestCase {
        private CircuitBreakerManager $manager;

        protected function setUp(): void {
            parent::setUp();

            global $wpdb, $circuit_breaker_http_calls, $circuit_breaker_test_logs, $hic_test_current_time;

            $hic_test_current_time = '2099-01-01 00:00:00';
            $circuit_breaker_http_calls = [];
            $circuit_breaker_test_logs = [];

            $wpdb = new class {
                public $prefix = 'wp_';
                public $retry_rows = [];
                public $circuit_states = [];

                public function prepare($query, ...$args) {
                    return ['query' => $query, 'args' => $args];
                }

                public function get_row($prepared, $output = ARRAY_A) {
                    if (is_array($prepared)
                        && isset($prepared['query'])
                        && strpos($prepared['query'], 'hic_circuit_breakers') !== false
                    ) {
                        $service = $prepared['args'][0] ?? null;

                        if ($service && isset($this->circuit_states[$service])) {
                            $row = [
                                'service_name' => $service,
                                'state' => $this->circuit_states[$service],
                                'failure_count' => 0,
                                'success_count' => 0,
                                'failure_threshold' => 5,
                                'recovery_timeout' => 300,
                                'success_threshold' => 3,
                            ];

                            return $output === ARRAY_A ? $row : (object) $row;
                        }
                    }

                    return null;
                }

                public function insert($table, $data) {
                    if (strpos($table, 'hic_retry_queue') !== false) {
                        $data = array_merge([
                            'id' => count($this->retry_rows) + 1,
                            'retry_count' => 0,
                            'max_retries' => 3,
                        ], $data);

                        $this->retry_rows[] = $data;
                    }

                    return true;
                }

                public function get_results($prepared, $output = ARRAY_A) {
                    if (is_array($prepared)
                        && isset($prepared['query'])
                        && strpos($prepared['query'], 'hic_retry_queue') !== false
                    ) {
                        $now = $prepared['args'][0] ?? null;
                        $rows = [];

                        foreach ($this->retry_rows as $row) {
                            $shouldInclude = ($row['status'] ?? null) === 'queued'
                                && (!isset($row['scheduled_retry_at']) || $row['scheduled_retry_at'] <= $now)
                                && (($row['retry_count'] ?? 0) < ($row['max_retries'] ?? 3));

                            if ($shouldInclude) {
                                $rows[] = $output === ARRAY_A ? $row : (object) $row;
                            }
                        }

                        return $rows;
                    }

                    return [];
                }

                public function update($table, $data, $where) {
                    if (strpos($table, 'hic_retry_queue') !== false) {
                        foreach ($this->retry_rows as &$row) {
                            $matches = true;

                            foreach ($where as $key => $value) {
                                if ((string) ($row[$key] ?? '') !== (string) $value) {
                                    $matches = false;
                                    break;
                                }
                            }

                            if ($matches) {
                                $row = array_merge($row, $data);
                            }
                        }
                        unset($row);

                        return true;
                    }

                    if (strpos($table, 'hic_circuit_breakers') !== false) {
                        $service = $where['service_name'] ?? null;

                        if ($service && isset($data['state'])) {
                            $this->circuit_states[$service] = $data['state'];
                        }
                    }

                    return true;
                }
            };

            $wpdb->circuit_states['hic_api'] = 'open';

            $this->manager = new CircuitBreakerManager(false);
        }

        protected function tearDown(): void {
            global $wpdb, $hic_test_current_time, $circuit_breaker_http_calls, $circuit_breaker_test_logs;

            unset($wpdb, $hic_test_current_time, $circuit_breaker_http_calls, $circuit_breaker_test_logs);

            parent::tearDown();
        }

        public function test_forced_open_circuit_retries_original_url(): void {
            global $wpdb, $circuit_breaker_http_calls;

            $url = 'https://api.hotelincloud.com/v1/test';
            $args = [
                'method' => 'POST',
                'body' => ['foo' => 'bar'],
            ];

            $fallback = $this->manager->intercept_api_requests(false, $args, $url);

            $this->assertIsArray($fallback, 'Fallback response should be returned when the circuit is open.');
            $this->assertNotEmpty($wpdb->retry_rows, 'Blocked request should be queued for retry.');

            $queuedPayload = json_decode($wpdb->retry_rows[0]['payload'], true);

            $this->assertSame($url, $queuedPayload['url'] ?? null, 'Queued payload should capture the original URL.');
            $this->assertSame($args, $queuedPayload['args'] ?? null, 'Queued payload should capture the original request arguments.');

            $wpdb->retry_rows[0]['scheduled_retry_at'] = '2000-01-01 00:00:00';

            $wpdb->retry_rows[] = [
                'id' => 2,
                'service_name' => 'hic_api',
                'operation_type' => 'api_request',
                'priority' => 'HIGH',
                'payload' => json_encode(['headers' => ['X-Legacy' => '1']]),
                'scheduled_retry_at' => '2000-01-01 00:00:00',
                'status' => 'queued',
                'retry_count' => 0,
                'max_retries' => 1,
            ];

            $wpdb->circuit_states['hic_api'] = 'closed';

            $this->manager->process_retry_queue();

            $this->assertCount(1, $circuit_breaker_http_calls, 'Only payloads with a URL should trigger HTTP retries.');
            $this->assertSame($url, $circuit_breaker_http_calls[0]['url']);
            $this->assertSame($args, $circuit_breaker_http_calls[0]['args']);
        }
    }
}
