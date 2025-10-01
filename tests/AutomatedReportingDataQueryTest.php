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

        $this->pdo->sqliteCreateFunction('HOUR', static function (?string $value): ?int {
            if ($value === null) {
                return null;
            }

            try {
                return (int) (new \DateTimeImmutable($value))->format('G');
            } catch (\Exception $exception) {
                return null;
            }
        }, 1);

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

        $this->assertSame(4, (int) ($weekly['summary']['total_bookings'] ?? 0));
        $this->assertSame(2, (int) ($weekly['summary']['google_conversions'] ?? 0));
        $this->assertSame(1, (int) ($weekly['summary']['facebook_conversions'] ?? 0));
        $this->assertSame(1, (int) ($weekly['summary']['direct_conversions'] ?? 0));
        $this->assertSame(850, (int) round((float) ($weekly['summary']['estimated_revenue'] ?? 0)));
        $this->assertEqualsWithDelta(1.0, (float) ($weekly['summary']['avg_daily_bookings'] ?? 0.0), 0.01);

        $campaigns = [];
        foreach ($weekly['by_campaign'] as $row) {
            $campaigns[$row['campaign']] = $row;
        }

        $this->assertCount(3, $campaigns);
        $this->assertEqualsWithDelta(33.33, (float) ($campaigns['summer-getaway']['percentage'] ?? 0.0), 0.2);
        $this->assertEqualsWithDelta(33.33, (float) ($campaigns['social-boost']['percentage'] ?? 0.0), 0.2);
        $this->assertEqualsWithDelta(33.33, (float) ($campaigns['flash-sale']['percentage'] ?? 0.0), 0.2);
        $this->assertSame('Google', $campaigns['summer-getaway']['source'] ?? null);
        $this->assertSame('Facebook', $campaigns['social-boost']['source'] ?? null);

        $this->assertNotEmpty($weekly['daily_breakdown']);
        $this->assertCount(4, $weekly['daily_breakdown']);
        $this->assertNull($weekly['daily_breakdown'][0]['growth_percent'] ?? null);
        $this->assertEquals(0.0, (float) ($weekly['daily_breakdown'][1]['growth_percent'] ?? 0.0));
        $this->assertEquals(0.0, (float) ($weekly['daily_breakdown'][2]['growth_percent'] ?? 0.0));
        $this->assertEquals(0.0, (float) ($weekly['daily_breakdown'][3]['growth_percent'] ?? 0.0));

        $this->assertSame(5, (int) ($monthly['summary']['total_bookings'] ?? 0));
        $this->assertSame(3, (int) ($monthly['summary']['google_conversions'] ?? 0));
        $this->assertSame(1, (int) ($monthly['summary']['facebook_conversions'] ?? 0));
        $this->assertSame(1060, (int) round((float) ($monthly['summary']['estimated_revenue'] ?? 0)));
        $this->assertEqualsWithDelta(1.0, (float) ($monthly['summary']['avg_daily_bookings'] ?? 0.0), 0.01);

        $this->assertGreaterThanOrEqual(2, count($monthly['weekly_breakdown']));
        $this->assertNull($monthly['weekly_breakdown'][0]['trend_percent'] ?? null);
        $this->assertGreaterThan(0, (float) ($monthly['weekly_breakdown'][1]['trend_percent'] ?? 0.0));

        $executed = $GLOBALS['wpdb']->executedQueries;
        $this->assertNotEmpty($executed);

        foreach ($executed as $query) {
            $this->assertStringNotContainsString('OVER', strtoupper($query));
        }
    }

    public function test_daily_report_includes_top_attribution_details(): void
    {
        $manager = \FpHic\AutomatedReporting\AutomatedReportingManager::instance();

        $this->insertBooking([
            'reservation_id' => 'res-today-1',
            'sid' => 'sid-today-1',
            'channel' => 'web',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'flash-sale',
            'amount' => 230.50,
            'currency' => 'EUR',
            'created_at' => $this->formatDateAtCurrentHour(5),
        ]);

        $this->insertBooking([
            'reservation_id' => 'res-today-2',
            'sid' => 'sid-today-2',
            'channel' => 'web',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'flash-sale',
            'amount' => 210.00,
            'currency' => 'EUR',
            'created_at' => $this->formatDateAtCurrentHour(15),
        ]);

        $this->insertBooking([
            'reservation_id' => 'res-today-3',
            'sid' => 'sid-today-3',
            'channel' => 'web',
            'utm_source' => 'facebook',
            'utm_medium' => 'paid_social',
            'utm_campaign' => 'brand-awareness',
            'amount' => 140.00,
            'currency' => 'EUR',
            'created_at' => $this->formatDateAtCurrentHour(25),
        ]);

        $daily = $this->invokeCollection($manager, 'collect_daily_data');

        $this->assertNotEmpty($daily['by_hour']);

        $hourRow = $daily['by_hour'][0];
        $this->assertSame('Google', $hourRow['top_source_label'] ?? null);
        $this->assertEqualsWithDelta(66.7, (float) ($hourRow['top_source_share'] ?? 0.0), 0.2);
        $this->assertSame('flash-sale', $hourRow['top_campaign_label'] ?? null);
        $this->assertEqualsWithDelta(66.7, (float) ($hourRow['top_campaign_share'] ?? 0.0), 0.2);

        [$headers, $rows] = $this->invokeTableRows($manager, $daily, 'daily');

        $this->assertSame(['Hour', 'Bookings', 'Revenue', 'Source', 'Campaign'], $headers);
        $this->assertSame('€580.50', $rows[0][2]);
        $this->assertSame('Google (66.7%)', $rows[0][3]);
        $this->assertSame('flash-sale (66.7%)', $rows[0][4]);
    }

    public function test_pdf_report_generation_produces_binary_pdf(): void
    {
        $manager = \FpHic\AutomatedReporting\AutomatedReportingManager::instance();

        $uniqueSuffix = str_replace('.', '-', uniqid('', true));
        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'hic-pdf-' . $uniqueSuffix . DIRECTORY_SEPARATOR;

        if (!is_dir($tempDir) && !mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            $this->fail('Failed to create temporary export directory for PDF generation.');
        }

        $property = new \ReflectionProperty($manager, 'export_dir');
        $property->setAccessible(true);
        $originalDir = $property->getValue($manager);
        $property->setValue($manager, $tempDir);

        try {
            $data = $this->invokeCollection($manager, 'collect_daily_data');

            $method = new \ReflectionMethod($manager, 'generate_pdf_report');
            $method->setAccessible(true);

            /** @var string $pdfPath */
            $pdfPath = $method->invoke($manager, $data, 'daily');

            $this->assertFileExists($pdfPath);
            $contents = file_get_contents($pdfPath);
            $this->assertNotFalse($contents);
            $this->assertStringStartsWith('%PDF', $contents);
            $this->assertGreaterThan(200, strlen($contents));

            @unlink($pdfPath);
        } finally {
            $property->setValue($manager, $originalDir);
            if (is_dir($tempDir)) {
                @rmdir($tempDir);
            }
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

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    private function invokeTableRows(\FpHic\AutomatedReporting\AutomatedReportingManager $manager, array $data, string $reportType): array
    {
        $reflection = new \ReflectionMethod($manager, 'get_report_table_rows');
        $reflection->setAccessible(true);

        /** @var array{0: array<int, string>, 1: array<int, array<int, mixed>>} $result */
        $result = $reflection->invoke($manager, $data, $reportType);

        return $result;
    }

    private function createSchema(ReportingWpdbStub $wpdb): void
    {
        $table = $wpdb->prefix . 'hic_booking_metrics';
        $wpdb->exec("CREATE TABLE {$table} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reservation_id TEXT,
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
        );");
    }

    private function seedData(ReportingWpdbStub $wpdb): void
    {
        $rows = [
            [
                'reservation_id' => 'res-google-1',
                'sid' => 'sid-google',
                'channel' => 'web',
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'summer-getaway',
                'amount' => 320.00,
                'currency' => 'EUR',
                'is_refund' => 0,
                'status' => 'confirmed',
                'created_at' => $this->formatDateDaysAgo(1),
            ],
            [
                'reservation_id' => 'res-facebook-1',
                'sid' => 'sid-facebook',
                'channel' => 'web',
                'utm_source' => 'facebook',
                'utm_medium' => 'paid_social',
                'utm_campaign' => 'social-boost',
                'amount' => 180.00,
                'currency' => 'EUR',
                'is_refund' => 0,
                'status' => 'confirmed',
                'created_at' => $this->formatDateDaysAgo(2),
            ],
            [
                'reservation_id' => 'res-direct-1',
                'sid' => 'sid-direct',
                'channel' => 'call',
                'utm_source' => '',
                'utm_medium' => '',
                'utm_campaign' => '',
                'amount' => 150.00,
                'currency' => 'EUR',
                'is_refund' => 0,
                'status' => 'confirmed',
                'created_at' => $this->formatDateDaysAgo(3),
            ],
            [
                'reservation_id' => 'res-google-2',
                'sid' => 'sid-google-2',
                'channel' => 'web',
                'utm_source' => 'google',
                'utm_medium' => 'display',
                'utm_campaign' => 'flash-sale',
                'amount' => 200.00,
                'currency' => 'EUR',
                'is_refund' => 0,
                'status' => 'confirmed',
                'created_at' => $this->formatDateDaysAgo(5),
            ],
            [
                'reservation_id' => 'res-google-old',
                'sid' => 'sid-older',
                'channel' => 'web',
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'evergreen',
                'amount' => 210.00,
                'currency' => 'EUR',
                'is_refund' => 0,
                'status' => 'confirmed',
                'created_at' => $this->formatDateDaysAgo(12),
            ],
        ];

        foreach ($rows as $row) {
            $this->insertBooking($row);
        }
    }

    private function formatDateDaysAgo(int $days): string
    {
        $secondsPerDay = 86400;
        return date('Y-m-d H:i:s', $this->now - ($days * $secondsPerDay));
    }

    private function formatDateAtCurrentHour(int $minute): string
    {
        $base = strtotime(date('Y-m-d H:00:00', $this->now));

        return date('Y-m-d H:i:s', $base + ($minute * 60));
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

    private function insertBooking(array $row): void
    {
        $table = $GLOBALS['wpdb']->prefix . 'hic_booking_metrics';

        $sql = $GLOBALS['wpdb']->prepare(
            "INSERT INTO {$table} (reservation_id, sid, channel, utm_source, utm_medium, utm_campaign, utm_content, utm_term, amount, currency, is_refund, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %f, %s, %d, %s, %s, %s)",
            $row['reservation_id'] ?? null,
            $row['sid'] ?? null,
            $row['channel'] ?? null,
            $row['utm_source'] ?? null,
            $row['utm_medium'] ?? null,
            $row['utm_campaign'] ?? null,
            $row['utm_content'] ?? null,
            $row['utm_term'] ?? null,
            $row['amount'] ?? 0,
            $row['currency'] ?? 'EUR',
            isset($row['is_refund']) ? (int) $row['is_refund'] : 0,
            $row['status'] ?? 'confirmed',
            $row['created_at'] ?? null,
            $row['updated_at'] ?? ($row['created_at'] ?? null)
        );

        $GLOBALS['wpdb']->exec($sql);
    }
}

