<?php
namespace FpHic\Helpers {
    if (!function_exists(__NAMESPACE__ . '\\wp_get_schedules')) {
        function wp_get_schedules() {
            $schedules = [
                'hourly' => ['interval' => 3600, 'display' => 'Once Hourly'],
                'twicedaily' => ['interval' => 12 * 3600, 'display' => 'Twice Daily'],
                'daily' => ['interval' => 24 * 3600, 'display' => 'Once Daily'],
            ];
            if (!empty($GLOBALS['schedule_defined'])) {
                $schedules['hic_every_fifteen_minutes'] = [
                    'interval' => 15 * 60,
                    'display'  => 'Every 15 Minutes (HIC Failed Requests)'
                ];
            }
            return $schedules;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\wp_next_scheduled')) {
        function wp_next_scheduled($hook) {
            return $GLOBALS['next_scheduled'] ?? false;
        }
    }
}

namespace {
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/../includes/helpers-scheduling.php';

    final class ZZFailedRequestRetrySchedulerTest extends TestCase {
        protected function setUp(): void {
            \FpHic\Helpers\hic_clear_option_cache();
            $GLOBALS['next_scheduled'] = false;
            $GLOBALS['schedule_defined'] = true;
            $GLOBALS['wp_scheduled_events'] = [];
            $GLOBALS['should_schedule_retry_event_schedules'] = [
                'hic_every_fifteen_minutes' => [
                    'interval' => 15 * 60,
                    'display'  => 'Every 15 Minutes (HIC Failed Requests)'
                ],
            ];
            global $wpdb;
            $wpdb = new class {
                public $prefix = 'wp_';
                public $count = 1;
                public $queries = [];
                public function get_var($sql) {
                    $this->queries[] = $sql;
                    return $this->count;
                }
            };
        }

        protected function tearDown(): void {
            unset($GLOBALS['should_schedule_retry_event_schedules']);
        }

        public function test_schedules_retry_when_conditions_met(): void {
            $this->assertTrue(\FpHic\Helpers\hic_should_schedule_retry_event());
            $this->assertTrue(\FpHic\Helpers\hic_has_failed_requests_to_retry());
            \FpHic\Helpers\hic_schedule_failed_request_retry();
            $this->assertNotEmpty($GLOBALS['wp_scheduled_events']);
            $this->assertSame('hic_retry_failed_requests', $GLOBALS['wp_scheduled_events'][0]['hook']);
        }

        public function test_does_not_schedule_when_no_failed_requests_are_pending(): void {
            global $wpdb;
            $wpdb->count = 0;
            $GLOBALS['wp_scheduled_events'] = [];
            \FpHic\Helpers\hic_schedule_failed_request_retry();
            $this->assertEmpty($GLOBALS['wp_scheduled_events']);
        }

        public function test_does_not_schedule_when_schedule_missing(): void {
            $GLOBALS['schedule_defined'] = false;
            $GLOBALS['should_schedule_retry_event_schedules'] = [];
            $GLOBALS['wp_scheduled_events'] = [];
            \FpHic\Helpers\hic_schedule_failed_request_retry();
            $this->assertEmpty($GLOBALS['wp_scheduled_events']);
        }
    }
}
