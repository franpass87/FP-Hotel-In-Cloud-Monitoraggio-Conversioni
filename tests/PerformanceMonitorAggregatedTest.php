<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PerformanceMonitorAggregatedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__) . '/includes/performance-monitor.php';
        if (!isset($GLOBALS['hic_performance_monitor'])) {
            $GLOBALS['hic_performance_monitor'] = new HIC_Performance_Monitor();
        }

        $timestamp = strtotime('2024-04-02 12:00:00');
        $GLOBALS['hic_test_current_time'] = [
            'timestamp_local' => $timestamp,
            'timestamp_gmt' => $timestamp,
            'mysql_local' => gmdate('Y-m-d H:i:s', $timestamp),
            'mysql_gmt' => gmdate('Y-m-d H:i:s', $timestamp),
        ];
    }

    public function testAggregatedMetricsProvideTrendAndDailyBreakdown(): void
    {
        $metricsDayOne = [
            [
                'operation' => 'api_call',
                'duration' => 0.5,
                'memory_used' => 10,
                'timestamp' => strtotime('2024-04-01 10:00:00'),
                'date' => '2024-04-01',
                'additional_data' => ['success' => true],
            ],
            [
                'operation' => 'api_call',
                'duration' => 0.9,
                'memory_used' => 12,
                'timestamp' => strtotime('2024-04-01 11:00:00'),
                'date' => '2024-04-01',
                'additional_data' => ['success' => false],
            ],
        ];

        $metricsDayTwo = [
            [
                'operation' => 'api_call',
                'duration' => 0.4,
                'memory_used' => 8,
                'timestamp' => strtotime('2024-04-02 09:00:00'),
                'date' => '2024-04-02',
                'additional_data' => ['success' => true],
            ],
        ];

        update_option('hic_performance_metrics_2024-04-01', $metricsDayOne, false);
        update_option('hic_performance_metrics_2024-04-02', $metricsDayTwo, false);

        /** @var HIC_Performance_Monitor $monitor */
        $monitor = $GLOBALS['hic_performance_monitor'];
        $aggregated = $monitor->get_aggregated_metrics(2, 'api_call');

        self::assertSame('2024-04-01', $aggregated['start_date']);
        self::assertSame('2024-04-02', $aggregated['end_date']);
        self::assertArrayHasKey('api_call', $aggregated['operations']);

        $operationData = $aggregated['operations']['api_call'];
        self::assertSame(3, $operationData['total']);
        self::assertEqualsWithDelta(1.8, $operationData['total_duration'], 0.0001);
        self::assertSame(2, $operationData['success']['total']);
        self::assertSame(1, $operationData['success']['failed']);
        self::assertArrayHasKey('days', $operationData);
        self::assertArrayHasKey('2024-04-01', $operationData['days']);
        self::assertArrayHasKey('2024-04-02', $operationData['days']);

        self::assertEqualsWithDelta(0.6, $operationData['avg_duration'], 0.0001);
        self::assertEqualsWithDelta(66.666, $operationData['success_rate'], 0.01);
        self::assertEqualsWithDelta(-50.0, $operationData['trend']['count_change'], 0.001);
        self::assertEqualsWithDelta(100.0, $operationData['days']['2024-04-02']['success_rate'], 0.001);
        self::assertEqualsWithDelta(50.0, $operationData['days']['2024-04-01']['success_rate'], 0.001);
        self::assertEqualsWithDelta(0.4, $operationData['days']['2024-04-02']['total_duration'], 0.001);
        self::assertEqualsWithDelta(1.4, $operationData['days']['2024-04-01']['total_duration'], 0.001);
        self::assertSame(1, $operationData['days']['2024-04-02']['success_total']);
        self::assertSame(1, $operationData['days']['2024-04-01']['success_total']);
    }
}
