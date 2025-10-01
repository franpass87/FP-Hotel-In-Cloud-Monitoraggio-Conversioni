<?php
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

require_once __DIR__ . '/../includes/health-monitor.php';
require_once __DIR__ . '/../includes/cli.php';

use PHPUnit\Framework\TestCase;

final class HealthCliCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WP_CLI::reset();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['hic_health_monitor']);
        parent::tearDown();
    }

    public function test_health_cli_warns_and_defaults_to_basic(): void
    {
        $monitor = new class {
            public array $levels = [];

            public function check_health($level)
            {
                $this->levels[] = $level;

                return [
                    'status' => 'healthy',
                    'timestamp' => '2024-05-01 12:00:00',
                    'version' => '3.4.1',
                    'checks' => [
                        'plugin_active' => [
                            'status' => 'pass',
                            'message' => 'Plugin OK',
                            'details' => ['functions_loaded' => true],
                        ],
                    ],
                    'metrics' => [],
                    'alerts' => [],
                ];
            }
        };

        $GLOBALS['hic_health_monitor'] = $monitor;

        $command = new \HIC_CLI_Commands();
        $command->health([], ['level' => 'invalid-level']);

        $this->assertSame([HIC_DIAGNOSTIC_BASIC], $monitor->levels);

        $warnings = array_filter(
            WP_CLI::$messages,
            static fn ($entry) => ($entry['type'] ?? '') === 'warning'
        );

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('Livello non valido', reset($warnings)['message']);
    }

    public function test_health_cli_outputs_json_when_requested(): void
    {
        $monitor = new class {
            public array $levels = [];

            public function check_health($level)
            {
                $this->levels[] = $level;

                return [
                    'status' => 'healthy',
                    'timestamp' => '2024-05-01 12:00:00',
                    'version' => '3.4.1',
                    'checks' => [],
                    'metrics' => ['uptime' => '100%'],
                    'alerts' => [],
                ];
            }
        };

        $GLOBALS['hic_health_monitor'] = $monitor;

        $command = new \HIC_CLI_Commands();
        $command->health([], ['format' => 'json', 'level' => HIC_DIAGNOSTIC_FULL]);

        $this->assertSame([HIC_DIAGNOSTIC_FULL], $monitor->levels);

        $lines = array_values(
            array_filter(
                WP_CLI::$messages,
                static fn ($entry) => ($entry['type'] ?? '') === 'line'
            )
        );

        $this->assertNotEmpty($lines);

        $payload = json_decode($lines[0]['message'], true);
        $this->assertIsArray($payload);
        $this->assertSame('healthy', $payload['status']);
        $this->assertSame('3.4.1', $payload['version']);
    }
}
