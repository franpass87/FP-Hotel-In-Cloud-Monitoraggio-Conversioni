<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/preload.php';

if (!function_exists('hic_log')) {
    function hic_log($message, $level = null): void
    {
        $GLOBALS['hic_test_logs'][] = [$message, $level];
    }
}

if (!defined('HIC_RETENTION_GCLID_DAYS')) {
    require_once dirname(__DIR__) . '/includes/constants.php';
}

if (!function_exists('hic_cleanup_old_gclids')) {
    require_once dirname(__DIR__) . '/includes/database.php';
}

final class DataRetentionComplianceTest extends TestCase
{
    private RetentionMockWpdb $mockWpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockWpdb = new RetentionMockWpdb();
        $GLOBALS['wpdb'] = $this->mockWpdb;
    }

    public function testGclidCleanupUsesRetentionFilters(): void
    {
        $this->mockWpdb->exec("CREATE TABLE wp_hic_gclids (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT);");
        $this->mockWpdb->exec(sprintf("INSERT INTO wp_hic_gclids (created_at) VALUES ('%s');", gmdate('Y-m-d H:i:s', strtotime('-120 days'))));
        $this->mockWpdb->exec(sprintf("INSERT INTO wp_hic_gclids (created_at) VALUES ('%s');", gmdate('Y-m-d H:i:s', strtotime('-5 days'))));

        $filter = static function (int $days): int {
            return 60;
        };
        add_filter('hic_retention_gclid_days', $filter);

        $deleted = hic_cleanup_old_gclids();
        $this->assertSame(1, $deleted);

        $remaining = (int) $this->mockWpdb->get_var('SELECT COUNT(*) FROM wp_hic_gclids');
        $this->assertSame(1, $remaining);

        remove_filter('hic_retention_gclid_days', $filter);
    }

    public function testBookingEventsCleanupSkipsUnprocessedRows(): void
    {
        $this->mockWpdb->exec("CREATE TABLE wp_hic_booking_events (id INTEGER PRIMARY KEY AUTOINCREMENT, processed INTEGER DEFAULT 0, processed_at TEXT);");
        $this->mockWpdb->exec(sprintf("INSERT INTO wp_hic_booking_events (processed, processed_at) VALUES (1, '%s');", gmdate('Y-m-d H:i:s', strtotime('-45 days'))));
        $this->mockWpdb->exec(sprintf("INSERT INTO wp_hic_booking_events (processed, processed_at) VALUES (1, '%s');", gmdate('Y-m-d H:i:s', strtotime('-5 days'))));
        $this->mockWpdb->exec(sprintf("INSERT INTO wp_hic_booking_events (processed, processed_at) VALUES (0, '%s');", gmdate('Y-m-d H:i:s', strtotime('-120 days'))));

        $filter = static function (int $days): int {
            return 30;
        };
        add_filter('hic_retention_booking_event_days', $filter);

        $deleted = hic_cleanup_booking_events();
        $this->assertSame(1, $deleted);

        $remainingProcessed = (int) $this->mockWpdb->get_var('SELECT COUNT(*) FROM wp_hic_booking_events WHERE processed = 1');
        $remainingPending = (int) $this->mockWpdb->get_var('SELECT COUNT(*) FROM wp_hic_booking_events WHERE processed = 0');

        $this->assertSame(1, $remainingProcessed, 'Only recently processed bookings should be retained.');
        $this->assertSame(1, $remainingPending, 'Pending events must be preserved regardless of age.');

        remove_filter('hic_retention_booking_event_days', $filter);
    }

    public function testRealtimeSyncCleanupTargetsConfiguredStatuses(): void
    {
        $this->mockWpdb->exec("CREATE TABLE wp_hic_realtime_sync (id INTEGER PRIMARY KEY AUTOINCREMENT, reservation_id TEXT, sync_status TEXT, first_seen TEXT);");
        $this->mockWpdb->exec(sprintf("INSERT INTO wp_hic_realtime_sync (reservation_id, sync_status, first_seen) VALUES ('old_notified', 'notified', '%s');", gmdate('Y-m-d H:i:s', strtotime('-200 days'))));
        $this->mockWpdb->exec(sprintf("INSERT INTO wp_hic_realtime_sync (reservation_id, sync_status, first_seen) VALUES ('old_new', 'new', '%s');", gmdate('Y-m-d H:i:s', strtotime('-200 days'))));
        $this->mockWpdb->exec(sprintf("INSERT INTO wp_hic_realtime_sync (reservation_id, sync_status, first_seen) VALUES ('recent_failed', 'failed', '%s');", gmdate('Y-m-d H:i:s', strtotime('-2 days'))));

        $daysFilter = static function (int $days): int {
            return 90;
        };
        add_filter('hic_retention_realtime_sync_days', $daysFilter);

        $deleted = hic_cleanup_realtime_sync();
        $this->assertSame(1, $deleted, 'Only aged, completed entries should be pruned with default statuses.');

        remove_filter('hic_retention_realtime_sync_days', $daysFilter);

        $statuses = [];
        $statement = $this->mockWpdb->query('SELECT sync_status FROM wp_hic_realtime_sync ORDER BY id ASC');
        if ($statement instanceof \PDOStatement) {
            $statuses = $statement->fetchAll(\PDO::FETCH_COLUMN);
        }

        $this->assertSame(['new', 'failed'], $statuses, 'New reservations should survive the initial cleanup pass.');

        $statusFilter = static function (array $statuses): array {
            return ['new'];
        };
        add_filter('hic_retention_realtime_sync_statuses', $statusFilter);

        $deletedNew = hic_cleanup_realtime_sync();
        $this->assertSame(1, $deletedNew, 'Custom status filters should allow purging additional records.');

        remove_filter('hic_retention_realtime_sync_statuses', $statusFilter);

        $remainingCount = (int) $this->mockWpdb->get_var('SELECT COUNT(*) FROM wp_hic_realtime_sync');
        $this->assertSame(1, $remainingCount);
    }
}

final class RetentionMockWpdb
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
}
