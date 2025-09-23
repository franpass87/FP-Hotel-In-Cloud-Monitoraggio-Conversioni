<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

require_once __DIR__ . '/../includes/automated-reporting.php';

final class ReportingWpdbStub
{
    public string $prefix = 'wp_';

    /** @var array<int, string> */
    public array $executedQueries = [];

    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->pdo->sqliteCreateFunction('DAYNAME', static function (?string $value): ?string {
            if ($value === null) {
                return null;
            }

            try {
                return (new \DateTimeImmutable($value))->format('l');
            } catch (\Exception $exception) {
                return null;
            }
        }, 1);

        $this->pdo->sqliteCreateFunction('WEEK', static function (?string $value, $mode = 0): ?int {
            if ($value === null) {
                return null;
            }

            try {
                return (int) (new \DateTimeImmutable($value))->format('W');
            } catch (\Exception $exception) {
                return null;
            }
        }, 2);

        $this->pdo->sqliteCreateFunction('CONCAT', static function (...$parts): string {
            $pieces = array_map(static fn($part) => $part ?? '', $parts);
            return implode('', $pieces);
        });
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

        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $query = str_replace('%%', '{PERCENT}', (string) $query);

        foreach ($args as $value) {
            $query = preg_replace_callback('/%d|%f|%s/', function (array $matches) use ($value) {
                switch ($matches[0]) {
                    case '%d':
                        return (string) (int) $value;
                    case '%f':
                        return (string) (float) $value;
                    default:
                        if ($value === null) {
                            return 'NULL';
                        }

                        return $this->pdo->quote((string) $value);
                }
            }, $query, 1);
        }

        return str_replace('{PERCENT}', '%', $query);
    }

    public function get_row($query, $output = OBJECT)
    {
        $this->executedQueries[] = (string) $query;
        $statement = $this->pdo->query((string) $query);

        if ($statement === false) {
            return null;
        }

        if ($output === ARRAY_A) {
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
        } elseif ($output === ARRAY_N) {
            $row = $statement->fetch(\PDO::FETCH_NUM);
        } else {
            $data = $statement->fetch(\PDO::FETCH_ASSOC);
            $row = $data === false ? false : (object) $data;
        }

        if ($row === false || $row === null) {
            return null;
        }

        return $row;
    }

    public function get_results($query, $output = OBJECT)
    {
        $this->executedQueries[] = (string) $query;
        $statement = $this->pdo->query((string) $query);

        if ($statement === false) {
            return [];
        }

        if ($output === ARRAY_A) {
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } elseif ($output === ARRAY_N) {
            $rows = $statement->fetchAll(\PDO::FETCH_NUM) ?: [];
        } else {
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $rows = array_map(static fn(array $row) => (object) $row, $rows);
        }

        return $rows;
    }
}

final class AutomatedReportingDataQueryTest extends TestCase
{
    private ?object $originalWpdb = null;

    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetManagerInstance();

        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new ReportingWpdbStub();

        $this->now = time();
        $this->createSchema($GLOBALS['wpdb']);
        $this->seedData($GLOBALS['wpdb']);
    }

    protected function tearDown(): void
    {
        $this->resetManagerInstance();

        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
        } else {
            unset($GLOBALS['wpdb']);
        }

        parent::tearDown();
    }

    public function test_weekly_and_monthly_queries_compute_expected_metrics_without_windows(): void
    {
        $manager = \FpHic\AutomatedReporting\AutomatedReportingManager::instance();

        $weekly = $this->invokeCollection($manager, 'collect_weekly_data');
        $monthly = $this->invokeCollection($manager, 'collect_monthly_data');

        $this->assertSame(3, (int) ($weekly['summary']['total_bookings'] ?? 0));
        $this->assertSame(2, (int) ($weekly['summary']['google_conversions'] ?? 0));
        $this->assertSame(1, (int) ($weekly['summary']['facebook_conversions'] ?? 0));
        $this->assertSame(1, (int) ($weekly['summary']['direct_conversions'] ?? 0));
        $this->assertSame(450, (int) ($weekly['summary']['estimated_revenue'] ?? 0));
        $this->assertEquals(1.0, (float) ($weekly['summary']['avg_daily_bookings'] ?? 0.0));

        $campaigns = [];
        foreach ($weekly['by_campaign'] as $row) {
            $campaigns[$row['campaign']] = $row;
        }

        $this->assertCount(2, $campaigns);
        $this->assertEquals(50.0, (float) ($campaigns['summer-getaway']['percentage'] ?? 0.0));
        $this->assertEquals(50.0, (float) ($campaigns['social-boost']['percentage'] ?? 0.0));

        $this->assertSame(4, (int) ($monthly['summary']['total_bookings'] ?? 0));
        $this->assertSame(3, (int) ($monthly['summary']['google_conversions'] ?? 0));
        $this->assertSame(1, (int) ($monthly['summary']['facebook_conversions'] ?? 0));
        $this->assertSame(600, (int) ($monthly['summary']['estimated_revenue'] ?? 0));
        $this->assertEquals(1.0, (float) ($monthly['summary']['avg_daily_bookings'] ?? 0.0));

        $executed = $GLOBALS['wpdb']->executedQueries;
        $this->assertNotEmpty($executed);

        foreach ($executed as $query) {
            $this->assertStringNotContainsString('OVER', strtoupper($query));
        }
    }

    private function invokeCollection(\FpHic\AutomatedReporting\AutomatedReportingManager $manager, string $method): array
    {
        $reflection = new \ReflectionMethod($manager, $method);
        $reflection->setAccessible(true);

        /** @var array $result */
        $result = $reflection->invoke($manager);

        return $result;
    }

    private function createSchema(ReportingWpdbStub $wpdb): void
    {
        $table = $wpdb->prefix . 'hic_gclids';
        $wpdb->exec("CREATE TABLE {$table} (\n            id INTEGER PRIMARY KEY AUTOINCREMENT,\n            gclid TEXT,\n            fbclid TEXT,\n            sid TEXT,\n            utm_source TEXT,\n            utm_campaign TEXT,\n            created_at TEXT\n        );");
    }

    private function seedData(ReportingWpdbStub $wpdb): void
    {
        $table = $wpdb->prefix . 'hic_gclids';
        $rows = [
            [
                'gclid' => 'g1',
                'fbclid' => null,
                'sid' => 'sid-google',
                'utm_source' => 'google',
                'utm_campaign' => 'summer-getaway',
                'created_at' => $this->formatDateDaysAgo(1),
            ],
            [
                'gclid' => null,
                'fbclid' => 'fb1',
                'sid' => 'sid-facebook',
                'utm_source' => 'facebook',
                'utm_campaign' => 'social-boost',
                'created_at' => $this->formatDateDaysAgo(2),
            ],
            [
                'gclid' => 'g2',
                'fbclid' => null,
                'sid' => 'sid-direct',
                'utm_source' => '',
                'utm_campaign' => '',
                'created_at' => $this->formatDateDaysAgo(3),
            ],
            [
                'gclid' => 'g3',
                'fbclid' => null,
                'sid' => 'sid-older',
                'utm_source' => 'google',
                'utm_campaign' => 'evergreen',
                'created_at' => $this->formatDateDaysAgo(8),
            ],
        ];

        foreach ($rows as $row) {
            $sql = $wpdb->prepare(
                "INSERT INTO {$table} (gclid, fbclid, sid, utm_source, utm_campaign, created_at) VALUES (%s, %s, %s, %s, %s, %s)",
                $row['gclid'],
                $row['fbclid'],
                $row['sid'],
                $row['utm_source'],
                $row['utm_campaign'],
                $row['created_at']
            );

            $wpdb->exec($sql);
        }
    }

    private function formatDateDaysAgo(int $days): string
    {
        $secondsPerDay = 86400;
        return date('Y-m-d H:i:s', $this->now - ($days * $secondsPerDay));
    }

    private function resetManagerInstance(): void
    {
        $reflection = new \ReflectionClass(\FpHic\AutomatedReporting\AutomatedReportingManager::class);
        if ($reflection->hasProperty('instance')) {
            $property = $reflection->getProperty('instance');
            $property->setAccessible(true);
            $property->setValue(null, null);
        }
    }
}
