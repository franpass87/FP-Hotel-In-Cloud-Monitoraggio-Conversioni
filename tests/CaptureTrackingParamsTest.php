<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

use FpHic\Helpers;

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string
    {
        static $counter = 0;
        $counter++;
        return sprintf('00000000-0000-4000-8000-%012d', $counter);
    }
}

if (!function_exists('hic_capture_tracking_params')) {
    require_once dirname(__DIR__) . '/includes/database.php';
}

if (!function_exists('\\FpHic\\Helpers\\hic_get_tracking_ids_by_sid')) {
    require_once dirname(__DIR__) . '/includes/helpers-tracking.php';
}

class CaptureMockWpdb
{
    public $prefix = 'wp_';
    public $last_error = '';
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
        if (empty($args)) {
            return $query;
        }

        if (is_array($args[0]) && count($args) === 1) {
            $args = $args[0];
        }

        $query = str_replace('%%', '{PERCENT}', $query);
        $pdo = $this->pdo;

        foreach ($args as $value) {
            $query = preg_replace_callback('/%d|%f|%s/', function ($matches) use ($value, $pdo) {
                switch ($matches[0]) {
                    case '%d':
                        return (string) intval($value);
                    case '%f':
                        return (string) floatval($value);
                    default:
                        return $pdo->quote((string) $value);
                }
            }, $query, 1);
        }

        return str_replace('{PERCENT}', '%', $query);
    }

    public function get_var($query)
    {
        $this->last_error = '';

        if (preg_match("/SHOW TABLES LIKE '([^']+)'/", $query, $matches)) {
            $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :name");
            $stmt->execute([':name' => $matches[1]]);
            $value = $stmt->fetchColumn();
            return $value !== false ? $value : null;
        }

        try {
            $stmt = $this->pdo->query($query);
            if (!$stmt) {
                $error = $this->pdo->errorInfo();
                $this->last_error = implode(' ', $error);
                return null;
            }
            $value = $stmt->fetchColumn();
            return $value !== false ? $value : null;
        } catch (\PDOException $e) {
            $this->last_error = $e->getMessage();
            return null;
        }
    }

    public function get_row($query)
    {
        $this->last_error = '';
        try {
            $stmt = $this->pdo->query($query);
            if (!$stmt) {
                $error = $this->pdo->errorInfo();
                $this->last_error = implode(' ', $error);
                return null;
            }
            $row = $stmt->fetchObject();
            return $row ?: null;
        } catch (\PDOException $e) {
            $this->last_error = $e->getMessage();
            return null;
        }
    }

    public function get_results($query): array
    {
        $this->last_error = '';

        if (preg_match("/SHOW COLUMNS FROM ([^ ]+) LIKE '([^']+)'/", $query, $matches)) {
            $table = preg_replace('/[^a-zA-Z0-9_]/', '', $matches[1]);
            $column = $matches[2];
            $stmt = $this->pdo->query("PRAGMA table_info($table)");
            $columns = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            foreach ($columns as $info) {
                if ($info['name'] === $column) {
                    return [(object) ['Field' => $column]];
                }
            }
            return [];
        }

        try {
            $stmt = $this->pdo->query($query);
            if (!$stmt) {
                $error = $this->pdo->errorInfo();
                $this->last_error = implode(' ', $error);
                return [];
            }
            return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
        } catch (\PDOException $e) {
            $this->last_error = $e->getMessage();
            return [];
        }
    }

    public function insert($table, $data, $format = null)
    {
        $this->last_error = '';
        $columns = array_keys($data);
        $placeholders = implode(',', array_fill(0, count($data), '?'));
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(',', $columns), $placeholders);

        try {
            $stmt = $this->pdo->prepare($sql);
            if ($stmt->execute(array_values($data))) {
                return 1;
            }
            $this->last_error = implode(' ', $stmt->errorInfo());
        } catch (\PDOException $e) {
            $this->last_error = $e->getMessage();
        }

        return false;
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $this->last_error = '';
        $set_parts = [];
        $params = [];
        foreach ($data as $column => $value) {
            $set_parts[] = "$column = ?";
            $params[] = $value;
        }
        $where_parts = [];
        foreach ($where as $column => $value) {
            $where_parts[] = "$column = ?";
            $params[] = $value;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(',', $set_parts), implode(' AND ', $where_parts));

        try {
            $stmt = $this->pdo->prepare($sql);
            if ($stmt->execute($params)) {
                return $stmt->rowCount();
            }
            $this->last_error = implode(' ', $stmt->errorInfo());
        } catch (\PDOException $e) {
            $this->last_error = $e->getMessage();
        }

        return false;
    }

    public function query($sql)
    {
        $this->last_error = '';
        try {
            return $this->pdo->exec($sql);
        } catch (\PDOException $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }
}

final class CaptureTrackingParamsTest extends TestCase
{
    private CaptureMockWpdb $mockWpdb;
    private $previousWpdb;

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;

        $this->previousWpdb = $wpdb ?? null;

        $this->mockWpdb = new CaptureMockWpdb();
        $this->mockWpdb->exec("CREATE TABLE wp_hic_gclids (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            gclid TEXT,
            fbclid TEXT,
            msclkid TEXT,
            ttclid TEXT,
            gbraid TEXT,
            wbraid TEXT,
            sid TEXT,
            utm_source TEXT,
            utm_medium TEXT,
            utm_campaign TEXT,
            utm_content TEXT,
            utm_term TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");

        $wpdb = $this->mockWpdb;

        $_GET = [];
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        global $wpdb;

        unset($GLOBALS['hic_test_filters']['hic_booking_data']);
        $_GET = [];
        $_COOKIE = [];
        $wpdb = $this->previousWpdb;
        parent::tearDown();
    }

    public function test_capture_tracking_params_stores_tracking_ids_with_generated_sid(): void
    {
        $_GET = [
            'gclid' => 'test-gclid-12345',
            'fbclid' => 'fbclid-67890',
            'msclkid' => 'msclkid-1234567890',
            'ttclid' => 'ttclid-1234567890',
            'gbraid' => 'GBRAID-abc1234567',
            'wbraid' => 'WBRAID-def7654321',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'spring',
            'utm_content' => 'ad-variant',
            'utm_term' => 'hotel',
        ];

        $this->assertTrue(hic_capture_tracking_params());

        $this->assertArrayHasKey('hic_sid', $_COOKIE);
        $sid = $_COOKIE['hic_sid'];
        $this->assertNotSame($_GET['gclid'], $sid);
        $this->assertGreaterThanOrEqual(HIC_SID_MIN_LENGTH, strlen($sid));
        $this->assertLessThanOrEqual(HIC_SID_MAX_LENGTH, strlen($sid));

        $tracking = Helpers\hic_get_tracking_ids_by_sid($sid);
        $this->assertSame('test-gclid-12345', $tracking['gclid']);
        $this->assertSame('fbclid-67890', $tracking['fbclid']);
        $this->assertSame('msclkid-1234567890', $tracking['msclkid']);
        $this->assertSame('ttclid-1234567890', $tracking['ttclid']);
        $this->assertSame('GBRAID-abc1234567', $tracking['gbraid']);
        $this->assertSame('WBRAID-def7654321', $tracking['wbraid']);

        $this->assertSame('GBRAID-abc1234567', $_COOKIE['hic_gbraid']);
        $this->assertSame('WBRAID-def7654321', $_COOKIE['hic_wbraid']);

        $utm = Helpers\hic_get_utm_params_by_sid($sid);
        $this->assertSame('google', $utm['utm_source']);
        $this->assertSame('cpc', $utm['utm_medium']);
        $this->assertSame('spring', $utm['utm_campaign']);
        $this->assertSame('ad-variant', $utm['utm_content']);
        $this->assertSame('hotel', $utm['utm_term']);
    }

    public function test_booking_processing_receives_tracking_ids_for_sid(): void
    {
        $_GET = [
            'gclid' => 'booking-gclid-12345',
            'gbraid' => 'booking-gbraid-12345',
            'utm_source' => 'meta',
        ];

        $this->assertTrue(hic_capture_tracking_params());
        $sid = $_COOKIE['hic_sid'];

        $capturedTracking = null;
        add_filter('hic_booking_data', function ($data, $tracking) use (&$capturedTracking) {
            if (is_array($tracking) && array_key_exists('gclid', $tracking)) {
                $capturedTracking = $tracking;
            }
            return $data;
        }, 10, 2);

        $trackingBeforeBooking = Helpers\hic_get_tracking_ids_by_sid($sid);
        $this->assertSame('booking-gclid-12345', $trackingBeforeBooking['gclid']);
        $this->assertSame('booking-gbraid-12345', $trackingBeforeBooking['gbraid']);
        $result = \FpHic\hic_process_booking_data([
            'email' => 'guest@example.com',
            'sid' => $sid,
            'reservation_id' => 'ABC123',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertTrue(
            in_array($result['status'], ['success', 'partial'], true),
            'La prenotazione deve essere processata con successo o parzialmente'
        );
        $this->assertIsArray($capturedTracking);
        $this->assertSame('booking-gclid-12345', $capturedTracking['gclid']);
        $this->assertSame($sid, $capturedTracking['sid']);
    }
}

