<?php
namespace WP_CLI\Utils {
    if (!function_exists(__NAMESPACE__ . '\\format_items')) {
        function format_items($format, $items, $fields)
        {
            \WP_CLI::$messages[] = [
                'type' => 'table',
                'format' => $format,
                'items' => $items,
                'fields' => $fields,
            ];
        }
    }
}

namespace {
    if (!class_exists('WP_CLI')) {
        class WP_CLI
        {
            public static array $messages = [];

            public static function reset(): void
            {
                self::$messages = [];
            }

            public static function log($message): void
            {
                self::$messages[] = ['type' => 'log', 'message' => (string) $message];
            }

            public static function line($message): void
            {
                self::$messages[] = ['type' => 'line', 'message' => (string) $message];
            }

            public static function warning($message): void
            {
                self::$messages[] = ['type' => 'warning', 'message' => (string) $message];
            }

            public static function success($message): void
            {
                self::$messages[] = ['type' => 'success', 'message' => (string) $message];
            }

            public static function error($message, $exit = true): void
            {
                throw new \RuntimeException((string) $message);
            }

            public static function halt($code): void
            {
                throw new \RuntimeException('halt:' . (string) $code);
            }

            public static function add_command($name, $callable): void
            {
                self::$messages[] = ['type' => 'command', 'name' => (string) $name];
            }
        }
    }

    if (!defined('HIC_FORCE_CLI_LOADER')) {
        define('HIC_FORCE_CLI_LOADER', true);
    }

    require_once __DIR__ . '/../includes/performance-monitor.php';
    require_once __DIR__ . '/../includes/cli.php';

    use PHPUnit\Framework\TestCase;

    final class PerformanceCliCommandTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            \WP_CLI::reset();
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['hic_performance_monitor']);
            parent::tearDown();
        }

        public function test_performance_cli_outputs_table_and_daily_breakdown(): void
        {
            $monitor = new class {
                public array $calls = [];

                public function get_aggregated_metrics($days, $operation)
                {
                    $this->calls[] = [$days, $operation];

                    return [
                        'start_date' => '2024-05-01',
                        'end_date' => '2024-05-03',
                        'operations' => [
                            'booking_processing' => [
                                'total' => 3,
                                'avg_duration' => 0.123,
                                'p95_duration' => 0.2,
                                'success_rate' => 66.6667,
                                'success' => ['total' => 2, 'failed' => 1],
                                'trend' => [
                                    'count_change' => 25.0,
                                    'duration_change' => -10.0,
                                    'success_change' => 5.0,
                                ],
                                'days' => [
                                    '2024-05-01' => [
                                        'count' => 1,
                                        'avg_duration' => 0.100,
                                        'p95_duration' => 0.100,
                                        'success_rate' => 100.0,
                                        'success_total' => 1,
                                        'total_duration' => 0.100,
                                    ],
                                    '2024-05-02' => [
                                        'count' => 2,
                                        'avg_duration' => 0.130,
                                        'p95_duration' => 0.200,
                                        'success_rate' => 50.0,
                                        'success_total' => 1,
                                        'total_duration' => 0.260,
                                    ],
                                ],
                            ],
                        ],
                    ];
                }
            };

            $GLOBALS['hic_performance_monitor'] = $monitor;

            $command = new \HIC_CLI_Commands();
            $command->performance([], ['days' => 3, 'operation' => 'booking_processing']);

            $this->assertSame([[3, 'booking_processing']], $monitor->calls);

            $tables = array_values(array_filter(
                \WP_CLI::$messages,
                static fn ($entry) => ($entry['type'] ?? '') === 'table'
            ));

            $this->assertCount(2, $tables);
            $summary = $tables[0];
            $this->assertSame('table', $summary['format']);
            $this->assertSame('booking_processing', $summary['items'][0]['operation']);
            $this->assertSame('3', $summary['items'][0]['count']);
            $this->assertSame('66.67%', $summary['items'][0]['success_rate']);

            $daily = $tables[1];
            $this->assertSame('2024-05-01', $daily['items'][0]['date']);
            $this->assertSame('1', $daily['items'][0]['count']);
            $this->assertSame('100.00%', $daily['items'][0]['success_rate']);
        }

        public function test_performance_cli_outputs_json(): void
        {
            $monitor = new class {
                public function get_aggregated_metrics($days, $operation)
                {
                    return [
                        'start_date' => '2024-05-01',
                        'end_date' => '2024-05-02',
                        'operations' => [
                            'webhook' => [
                                'total' => 1,
                                'avg_duration' => 0.050,
                                'p95_duration' => 0.050,
                                'success_rate' => 100.0,
                                'total_duration' => 0.050,
                                'success' => ['total' => 1, 'failed' => 0],
                                'trend' => ['count_change' => 0.0, 'duration_change' => 0.0, 'success_change' => 0.0],
                                'days' => [],
                            ],
                        ],
                    ];
                }
            };

            $GLOBALS['hic_performance_monitor'] = $monitor;

            $command = new \HIC_CLI_Commands();
            $command->performance([], ['format' => 'json']);

            $lines = array_values(array_filter(
                \WP_CLI::$messages,
                static fn ($entry) => ($entry['type'] ?? '') === 'line'
            ));

            $this->assertNotEmpty($lines);
            $payload = json_decode($lines[0]['message'], true);
            $this->assertIsArray($payload);
            $this->assertSame(1, $payload['operations']['webhook']['total']);
            $this->assertEqualsWithDelta(50.0, $payload['operations']['webhook']['avg_duration_ms'], 0.0001);
        }

        public function test_performance_cli_validates_format(): void
        {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Invalid format. Use "table" or "json".');

            $monitor = new class {
                public function get_aggregated_metrics($days, $operation)
                {
                    return ['operations' => []];
                }
            };

            $GLOBALS['hic_performance_monitor'] = $monitor;

            $command = new \HIC_CLI_Commands();
            $command->performance([], ['format' => 'xml']);
        }
    }
}
