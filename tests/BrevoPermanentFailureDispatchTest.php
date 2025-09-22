<?php

use PHPUnit\Framework\TestCase;

final class BrevoPermanentFailureDispatchTest extends TestCase
{
    /** @var object|null */
    private $previousWpdb;

    /** @var object */
    private $mockWpdb;

    /** @var array<int, array{message:mixed, level?:string}> */
    private static $capturedLogs = [];

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../includes/functions.php';
        require_once __DIR__ . '/../includes/integrations/brevo.php';
        require_once __DIR__ . '/../includes/api/polling.php';

        global $wpdb, $hic_test_options, $hic_test_http_mock;

        $hic_test_options = [];
        $hic_test_http_mock = null;
        self::$capturedLogs = [];

        update_option('hic_brevo_api_key', 'test-key');
        update_option('hic_brevo_enabled', '1');
        update_option('hic_realtime_brevo_sync', '1');
        update_option('hic_tracking_mode', 'none');
        update_option('hic_connection_type', 'api');
        update_option('hic_admin_email', '');
        update_option('hic_synced_res_ids', array());
        update_option('hic_brevo_event_endpoint', 'https://brevo.test/track');

        add_filter('hic_log_message', [self::class, 'captureLogMessage'], 10, 2);

        $this->mockWpdb = new class {
            public $prefix = 'wp_';
            public $last_error = '';
            public $updated = [];
            public $inserted = [];

            public function prepare($query, ...$args)
            {
                if (count($args) === 1 && is_array($args[0])) {
                    $args = $args[0];
                }

                foreach ($args as $arg) {
                    if (strpos($query, '%d') !== false) {
                        $query = preg_replace('/%d/', (string) intval($arg), $query, 1);
                        continue;
                    }

                    $query = preg_replace('/%s/', "'" . addslashes((string) $arg) . "'", $query, 1);
                }

                return $query;
            }

            public function get_var($query)
            {
                if (is_array($query)) {
                    $query = $query['query'] ?? '';
                }

                if (is_string($query) && stripos($query, 'SHOW TABLES LIKE') !== false) {
                    return 'wp_hic_gclids';
                }

                return null;
            }

            public function get_row($query)
            {
                return null;
            }

            public function update($table, $data, $where, $formats = null, $where_formats = null)
            {
                $this->updated[] = [
                    'table' => $table,
                    'data' => $data,
                    'where' => $where,
                ];

                return true;
            }

            public function insert($table, $data, $format = null)
            {
                $this->inserted[] = [
                    'table' => $table,
                    'data' => $data,
                    'format' => $format,
                ];

                return true;
            }
        };

        $this->previousWpdb = $wpdb ?? null;
        $wpdb = $this->mockWpdb;
    }

    protected function tearDown(): void
    {
        global $wpdb, $hic_test_http_mock;

        $wpdb = $this->previousWpdb;
        $hic_test_http_mock = null;
        unset($GLOBALS['hic_test_filters']['hic_log_message']);

        parent::tearDown();
    }

    public function testDispatchSkipsPermanentBrevoFailures(): void
    {
        global $hic_test_http_mock;

        $eventEndpoint = \FpHic\Helpers\hic_get_brevo_event_endpoint();

        $hic_test_http_mock = static function ($url, $args) use ($eventEndpoint) {
            if (strpos($url, $eventEndpoint) !== false) {
                return [
                    'body' => json_encode(['code' => 'forbidden', 'message' => 'Account disabled']),
                    'response' => ['code' => 403],
                ];
            }

            return [
                'body' => '{}',
                'response' => ['code' => 200],
            ];
        };

        $transformed = [
            'transaction_id' => 'RES-403',
            'original_price' => 199.0,
            'value' => 199.0,
            'currency' => 'EUR',
            'email' => 'guest@example.com',
            'sid' => 'SID-403',
        ];

        $original = [
            'id' => 'RES-403',
        ];

        $result = \FpHic\hic_dispatch_reservation($transformed, $original);

        $this->assertTrue($result, 'Dispatch should complete even when Brevo reports a permanent failure.');

        $updates = array_filter(
            $this->mockWpdb->updated,
            static function ($update) {
                return $update['table'] === 'wp_hic_realtime_sync';
            }
        );

        $this->assertNotEmpty($updates, 'Realtime sync table should record the permanent Brevo failure.');
        $lastUpdate = array_pop($updates);
        $this->assertSame('permanent_failure', $lastUpdate['data']['sync_status'] ?? null);

        $warningLog = null;
        $summaryLog = null;

        foreach (self::$capturedLogs as $entry) {
            $message = is_string($entry['message']) ? $entry['message'] : json_encode($entry['message']);

            if ($entry['level'] === HIC_LOG_LEVEL_WARNING) {
                $warningLog = $message;
            }

            if (is_string($message) && strpos($message, 'Reservation RES-403 dispatched successfully') !== false) {
                $summaryLog = $message;
            }
        }

        $this->assertNotNull($warningLog, 'A warning log should be emitted for permanent Brevo failures.');
        $this->assertStringContainsString('permanently failed', $warningLog);

        $this->assertNotNull($summaryLog, 'Dispatch summary log should be present.');
        $this->assertStringContainsString('Brevo event=skipped', $summaryLog);
        $this->assertStringContainsString('permanent Brevo failure', $summaryLog);
    }

    public static function captureLogMessage($message, $level)
    {
        self::$capturedLogs[] = [
            'message' => $message,
            'level' => $level,
        ];

        return $message;
    }
}
