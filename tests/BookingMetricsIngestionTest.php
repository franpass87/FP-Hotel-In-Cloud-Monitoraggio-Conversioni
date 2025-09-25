<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use FpHic\Analytics\BookingMetrics;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/helpers-logging.php';
require_once __DIR__ . '/../includes/helpers-tracking.php';
require_once __DIR__ . '/../includes/booking-metrics.php';

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

final class BookingMetricsIngestionTest extends TestCase
{
    private BookingMetricsWpdbDouble $wpdbDouble;

    /** @var object|null */
    private $originalWpdb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetBookingMetricsSingleton();

        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;
        $this->wpdbDouble = new BookingMetricsWpdbDouble();
        $GLOBALS['wpdb'] = $this->wpdbDouble;

        $this->setCurrentTime('2024-01-01 12:00:00');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['hic_test_current_time']);

        $GLOBALS['wpdb'] = $this->originalWpdb;

        $this->resetBookingMetricsSingleton();

        parent::tearDown();
    }

    public function testCaptureMetricsPersistsNormalizedBooking(): void
    {
        $this->wpdbDouble->setUtmRow('BM-PRIMARY', [
            'utm_source'   => 'google',
            'utm_medium'   => 'cpc',
            'utm_campaign' => 'Winter Deals',
            'utm_content'  => 'Ad Variation',
            'utm_term'     => 'rome hotel',
        ]);

        $bookingPayload = [
            'reservation_id' => 'res-001',
            'sid'            => 'BM-PRIMARY',
            'revenue'        => '199.95',
            'currency'       => 'eur',
            'gclid'          => 'test-gclid',
            'status'         => 'CONFIRMED',
        ];

        $this->bookingMetrics()->capture_booking_metrics($bookingPayload, []);

        $stored = $this->wpdbDouble->getMetricRow('RES-001');
        $this->assertNotNull($stored, 'Booking metrics row should be persisted.');

        $this->assertSame('RES-001', $stored['reservation_id']);
        $this->assertSame('BM-PRIMARY', $stored['sid']);
        $this->assertSame('Google Ads', $stored['channel']);
        $this->assertSame('google', $stored['utm_source']);
        $this->assertSame('cpc', $stored['utm_medium']);
        $this->assertSame('Winter Deals', $stored['utm_campaign']);
        $this->assertSame('Ad Variation', $stored['utm_content']);
        $this->assertSame('rome hotel', $stored['utm_term']);
        $this->assertSame(199.95, $stored['amount']);
        $this->assertSame('EUR', $stored['currency']);
        $this->assertSame(0, $stored['is_refund']);
        $this->assertSame('CONFIRMED', $stored['status']);
        $this->assertSame('2024-01-01 12:00:00', $stored['created_at']);
        $this->assertSame('2024-01-01 12:00:00', $stored['updated_at']);
    }

    public function testCaptureMetricsMergesExistingRefund(): void
    {
        $this->wpdbDouble->setMetricRow([
            'reservation_id' => 'RES-002',
            'sid'            => 'SID-OLD',
            'channel'        => 'Direct',
            'utm_source'     => 'newsletter',
            'utm_medium'     => 'email',
            'utm_campaign'   => 'Autumn',
            'utm_content'    => 'Initial',
            'utm_term'       => 'stay',
            'amount'         => 150.00,
            'currency'       => 'USD',
            'is_refund'      => 0,
            'status'         => 'CONFIRMED',
            'created_at'     => '2023-12-31 10:00:00',
            'updated_at'     => '2023-12-31 10:00:00',
        ]);

        $this->wpdbDouble->setUtmRow('BM-REFUND-NEW', [
            'utm_source'   => 'newsletter',
            'utm_medium'   => 'email',
            'utm_campaign' => 'Return Campaign',
            'utm_content'  => 'Follow Up',
            'utm_term'     => 'repeat guest',
        ]);

        $this->setCurrentTime('2024-02-10 08:30:00');

        $bookingPayload = [
            'booking_id' => 'res-002',
            'sid'        => '  BM-REFUND-NEW  ',
            'currency'   => 'usd',
            'is_refund'  => true,
            'status'     => 'cancelled',
        ];

        $this->bookingMetrics()->capture_booking_metrics($bookingPayload, []);

        $stored = $this->wpdbDouble->getMetricRow('RES-002');
        $this->assertNotNull($stored, 'Existing booking metrics row should be updated.');

        $this->assertSame('BM-REFUND-NEW', $stored['sid']);
        $this->assertSame('Newsletter', $stored['channel']);
        $this->assertSame('newsletter', $stored['utm_source']);
        $this->assertSame('email', $stored['utm_medium']);
        $this->assertSame('Return Campaign', $stored['utm_campaign']);
        $this->assertSame('Follow Up', $stored['utm_content']);
        $this->assertSame('repeat guest', $stored['utm_term']);
        $this->assertSame(-150.0, $stored['amount']);
        $this->assertSame('USD', $stored['currency']);
        $this->assertSame(1, $stored['is_refund']);
        $this->assertSame('cancelled', $stored['status']);
        $this->assertSame('2023-12-31 10:00:00', $stored['created_at'], 'Original creation timestamp must be preserved.');
        $this->assertSame('2024-02-10 08:30:00', $stored['updated_at'], 'Updated timestamp should reflect latest capture.');
    }

    private function setCurrentTime(string $mysqlTime): void
    {
        $timestamp = strtotime($mysqlTime) ?: time();
        $GLOBALS['hic_test_current_time'] = [
            'mysql_local'     => $mysqlTime,
            'mysql_gmt'       => $mysqlTime,
            'timestamp_local' => $timestamp,
            'timestamp_gmt'   => $timestamp,
            'value'           => $timestamp,
        ];
    }

    private function resetBookingMetricsSingleton(): void
    {
        BookingMetrics::resetInstance();
    }

    private function bookingMetrics(): BookingMetrics
    {
        $instance = BookingMetrics::instance();

        $instance->overrideTableEnsuredState(true);

        return $instance;
    }
}

final class BookingMetricsWpdbDouble
{
    public string $prefix = 'wp_';
    public string $last_error = '';

    /** @var array<string,array<string,mixed>> */
    private array $metrics = [];

    /** @var array<string,array<string,mixed>> */
    private array $utm = [];

    public function setUtmRow(string $sid, array $row): void
    {
        $this->utm[$sid] = $row;
    }

    public function setMetricRow(array $row): void
    {
        $this->metrics[$row['reservation_id']] = $row;
    }

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        return [$query, $args];
    }

    public function get_row($prepared, $output = OBJECT)
    {
        [$query, $args] = is_array($prepared) ? $prepared : [$prepared, []];

        if (stripos($query, $this->prefix . 'hic_booking_metrics') !== false && !empty($args)) {
            $reservationId = strtoupper((string) $args[0]);
            if (!array_key_exists($reservationId, $this->metrics)) {
                return null;
            }
            $row = $this->metrics[$reservationId];
        } elseif (stripos($query, $this->prefix . 'hic_gclids') !== false && !empty($args)) {
            $sid = (string) $args[0];
            if (!array_key_exists($sid, $this->utm)) {
                return null;
            }
            $row = $this->utm[$sid];
        } else {
            return null;
        }

        if ($output === ARRAY_A) {
            return $row;
        }

        return (object) $row;
    }

    public function replace($table, $data, $format = null)
    {
        if (stripos($table, 'hic_booking_metrics') === false) {
            $this->last_error = 'Unsupported table: ' . $table;
            return false;
        }

        $reservationId = $data['reservation_id'];
        $this->metrics[$reservationId] = $data;
        return 1;
    }

    public function get_var($prepared)
    {
        [$query, $args] = is_array($prepared) ? $prepared : [$prepared, []];
        if (stripos($query, 'SHOW TABLES LIKE') !== false && !empty($args)) {
            $needle = (string) $args[0];
            if ($needle === $this->prefix . 'hic_booking_metrics') {
                return $needle;
            }
            if ($needle === $this->prefix . 'hic_gclids') {
                return $needle;
            }
        }

        return null;
    }

    public function getMetricRow(string $reservationId): ?array
    {
        $reservationId = strtoupper($reservationId);
        return $this->metrics[$reservationId] ?? null;
    }
}
