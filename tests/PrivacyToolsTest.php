<?php declare(strict_types=1);

namespace FpHic {
    if (!function_exists('FpHic\\hic_normalize_reservation_id')) {
        function hic_normalize_reservation_id($value): string
        {
            if (!is_scalar($value)) {
                return '';
            }

            $normalized = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $value);
            if (!is_string($normalized)) {
                return '';
            }

            return strtolower($normalized);
        }
    }
}

namespace {
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/bootstrap.php';
    require_once __DIR__ . '/preload.php';

    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }
    if (!defined('ARRAY_N')) {
        define('ARRAY_N', 'ARRAY_N');
    }
    if (!defined('OBJECT')) {
        define('OBJECT', 'OBJECT');
    }

    if (!function_exists('hic_log')) {
        function hic_log($message, $level = null): void
        {
            // Silence logs during tests.
        }
    }

    if (!function_exists('FpHic\\hic_get_wpdb')) {
        require_once dirname(__DIR__) . '/includes/database.php';
    }

    if (!function_exists('FpHic\\Privacy\\export_personal_data')) {
        require_once dirname(__DIR__) . '/includes/privacy.php';
    }

    final class PrivacyToolsTest extends TestCase
    {
        private PrivacyToolsWpdbStub $wpdb;

        protected function setUp(): void
        {
            parent::setUp();
            $this->wpdb = new PrivacyToolsWpdbStub();
            $GLOBALS['wpdb'] = $this->wpdb;
            $this->createTables();

            update_option('hic_res_email_map', [
                'ABC123' => 'guest@example.com',
                'OTHER' => 'other@example.com',
            ]);
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['wpdb']);
            parent::tearDown();
        }

        public function testExporterReturnsStructuredDataForEmail(): void
        {
            $this->seedData();

            $result = \FpHic\Privacy\export_personal_data('guest@example.com', 1);

            $this->assertTrue($result['done']);
            $this->assertNotEmpty($result['data']);

            $reservationItems = array_values(array_filter($result['data'], static function (array $item): bool {
                return isset($item['group_id']) && $item['group_id'] === 'fp-hic-monitor-reservations';
            }));
            $this->assertCount(1, $reservationItems);

            $reservationData = $this->indexItemData($reservationItems[0]['data']);
            $this->assertSame('abc123', $reservationData['ID prenotazione']);
            $this->assertSame('guest@example.com', $reservationData['Email associata']);
            $this->assertSame('direct', $reservationData['Canale']);

            $trackingItems = array_values(array_filter($result['data'], static function (array $item): bool {
                return isset($item['group_id']) && $item['group_id'] === 'fp-hic-monitor-tracking';
            }));
            $this->assertNotEmpty($trackingItems);

            $trackingData = $this->indexItemData($trackingItems[0]['data']);
            $this->assertSame('HIC123SID', $trackingData['Sessione HIC SID']);
            $this->assertSame('G-123', $trackingData['gclid']);

            $realtimeItems = array_values(array_filter($result['data'], static function (array $item): bool {
                return isset($item['group_id']) && $item['group_id'] === 'fp-hic-monitor-realtime-sync';
            }));
            $this->assertCount(1, $realtimeItems);

            $payloadValue = $this->indexItemData($realtimeItems[0]['data'])['Payload registrato'];
            $this->assertStringContainsString('"reservation_id": "ABC123"', $payloadValue);
            $this->assertStringContainsString("\n", $payloadValue, 'Pretty-printed JSON should contain line breaks.');
        }

        public function testEraserRemovesDataAndUpdatesMap(): void
        {
            $this->seedData();

            $response = \FpHic\Privacy\erase_personal_data('guest@example.com', 1);

            $this->assertTrue($response['done']);
            $this->assertTrue($response['items_removed']);
            $this->assertFalse($response['items_retained']);

            $this->assertSame(0, (int) $this->wpdb->get_var('SELECT COUNT(*) FROM wp_hic_booking_metrics'));
            $this->assertSame(0, (int) $this->wpdb->get_var('SELECT COUNT(*) FROM wp_hic_realtime_sync'));
            $this->assertSame(0, (int) $this->wpdb->get_var('SELECT COUNT(*) FROM wp_hic_booking_events'));
            $this->assertSame(0, (int) $this->wpdb->get_var('SELECT COUNT(*) FROM wp_hic_gclids'));

            $this->assertSame(['other' => 'other@example.com'], get_option('hic_res_email_map'));
        }

        /**
         * @param array<int, array{name:string, value:mixed}> $data
         * @return array<string, mixed>
         */
        private function indexItemData(array $data): array
        {
            $indexed = [];
            foreach ($data as $row) {
                if (!isset($row['name'])) {
                    continue;
                }

                $indexed[$row['name']] = $row['value'] ?? null;
            }

            return $indexed;
        }

        private function createTables(): void
        {
            $pdo = $this->wpdb->getPdo();

            $pdo->exec('CREATE TABLE wp_hic_booking_metrics (
                reservation_id TEXT PRIMARY KEY,
                sid TEXT,
                channel TEXT,
                utm_source TEXT,
                utm_medium TEXT,
                utm_campaign TEXT,
                utm_content TEXT,
                utm_term TEXT,
                amount REAL,
                currency TEXT,
                is_refund INTEGER,
                status TEXT,
                created_at TEXT,
                updated_at TEXT
            )');

            $pdo->exec('CREATE TABLE wp_hic_realtime_sync (
                reservation_id TEXT PRIMARY KEY,
                sync_status TEXT,
                first_seen TEXT,
                last_attempt TEXT,
                attempt_count INTEGER,
                brevo_event_sent INTEGER,
                last_error TEXT,
                payload_json TEXT
            )');

            $pdo->exec('CREATE TABLE wp_hic_booking_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                booking_id TEXT,
                poll_timestamp TEXT,
                processed INTEGER,
                processed_at TEXT,
                process_attempts INTEGER,
                last_error TEXT,
                raw_data TEXT
            )');

            $pdo->exec('CREATE TABLE wp_hic_gclids (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sid TEXT,
                gclid TEXT,
                fbclid TEXT,
                msclkid TEXT,
                ttclid TEXT,
                gbraid TEXT,
                wbraid TEXT,
                utm_source TEXT,
                utm_medium TEXT,
                utm_campaign TEXT,
                utm_content TEXT,
                utm_term TEXT,
                created_at TEXT
            )');
        }

        private function seedData(): void
        {
            $pdo = $this->wpdb->getPdo();

            $pdo->exec("INSERT INTO wp_hic_booking_metrics (reservation_id, sid, channel, utm_source, utm_medium, utm_campaign, utm_content, utm_term, amount, currency, is_refund, status, created_at, updated_at)
                VALUES ('abc123', 'HIC123SID', 'direct', 'google', 'cpc', 'brand', 'cta', 'keyword', 199.50, 'EUR', 0, 'confirmed', '2023-01-01 10:00:00', '2023-01-02 12:00:00')");

            $realtimePayload = $pdo->quote(json_encode([
                'reservation_id' => 'ABC123',
                'email' => 'guest@example.com',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $pdo->exec("INSERT INTO wp_hic_realtime_sync (reservation_id, sync_status, first_seen, last_attempt, attempt_count, brevo_event_sent, last_error, payload_json)
                VALUES ('abc123', 'notified', '2023-01-01 10:05:00', '2023-01-01 11:00:00', 2, 1, 'Timeout', $realtimePayload)");

            $eventPayload = $pdo->quote(json_encode([
                'reservation_id' => 'ABC123',
                'total' => 199.50,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $pdo->exec("INSERT INTO wp_hic_booking_events (booking_id, poll_timestamp, processed, processed_at, process_attempts, last_error, raw_data)
                VALUES ('abc123', '2023-01-01 09:55:00', 1, '2023-01-01 10:10:00', 1, '', $eventPayload)");

            $pdo->exec("INSERT INTO wp_hic_gclids (sid, gclid, fbclid, msclkid, ttclid, gbraid, wbraid, utm_source, utm_medium, utm_campaign, utm_content, utm_term, created_at)
                VALUES ('HIC123SID', 'G-123', 'FB-456', 'MS-789', 'TT-111', 'GB-222', 'WB-333', 'google', 'cpc', 'brand', 'cta', 'keyword', '2023-01-01 09:50:00')");
        }
    }

    final class PrivacyToolsWpdbStub
    {
        public string $prefix = 'wp_';
        public string $last_error = '';
        private \PDO $pdo;

        public function __construct()
        {
            $this->pdo = new \PDO('sqlite::memory:');
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }

        public function exec(string $sql): void
        {
            $this->pdo->exec($sql);
        }

        public function prepare($query, ...$args)
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }

            foreach ($args as $arg) {
                if (is_int($arg) || is_float($arg)) {
                    $replacement = (string) $arg;
                } else {
                    $replacement = $this->pdo->quote((string) $arg);
                }

                $query = preg_replace('/%[sdFf]/', $replacement, $query, 1);
            }

            return $query;
        }

        public function get_row($query, $output = ARRAY_A)
        {
            $statement = $this->pdo->query($query);
            if (!$statement) {
                $this->last_error = implode(' ', $this->pdo->errorInfo());
                return null;
            }

            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }

            return $this->formatRow($row, $output);
        }

        public function get_results($query, $output = ARRAY_A)
        {
            $statement = $this->pdo->query($query);
            if (!$statement) {
                $this->last_error = implode(' ', $this->pdo->errorInfo());
                return false;
            }

            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
            $formatted = [];

            foreach ($rows as $row) {
                $formatted[] = $this->formatRow($row, $output);
            }

            return $formatted;
        }

        public function get_var($query)
        {
            if (preg_match("/SHOW TABLES LIKE '([^']+)'/", $query, $matches)) {
                $statement = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name");
                $statement->execute([':name' => $matches[1]]);
                $value = $statement->fetchColumn();
                return $value === false ? null : $value;
            }

            $statement = $this->pdo->query($query);
            if (!$statement) {
                $this->last_error = implode(' ', $this->pdo->errorInfo());
                return null;
            }

            $value = $statement->fetchColumn();
            return $value === false ? null : $value;
        }

        public function query($query)
        {
            $trimmed = ltrim($query);
            if (stripos($trimmed, 'select') === 0) {
                $statement = $this->pdo->query($query);
                if (!$statement) {
                    $this->last_error = implode(' ', $this->pdo->errorInfo());
                    return false;
                }

                return $statement;
            }

            try {
                $result = $this->pdo->exec($query);
                if ($result === false) {
                    $this->last_error = implode(' ', $this->pdo->errorInfo());
                }

                return $result;
            } catch (\PDOException $exception) {
                $this->last_error = $exception->getMessage();
                return false;
            }
        }

        public function delete($table, $where, $where_format = null)
        {
            if (!is_array($where) || $where === []) {
                return false;
            }

            $clauses = [];
            $params = [];
            foreach ($where as $column => $value) {
                $clauses[] = $column . ' = ?';
                $params[] = $value;
            }

            $sql = 'DELETE FROM ' . $table . ' WHERE ' . implode(' AND ', $clauses);
            $statement = $this->pdo->prepare($sql);
            $result = $statement->execute($params);

            if ($result === false) {
                $this->last_error = implode(' ', $statement->errorInfo());
                return false;
            }

            return $statement->rowCount();
        }

        public function getPdo(): \PDO
        {
            return $this->pdo;
        }

        private function formatRow(array $row, $output)
        {
            if ($output === OBJECT) {
                return (object) $row;
            }

            if ($output === ARRAY_N) {
                return array_values($row);
            }

            return $row;
        }
    }
}
