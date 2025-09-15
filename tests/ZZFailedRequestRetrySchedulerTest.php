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
    if (!function_exists(__NAMESPACE__ . '\\wp_schedule_event')) {
        function wp_schedule_event($timestamp, $recurrence, $hook, $args = array(), $wp_error = false) {
            $GLOBALS['scheduled_events'][] = [
                'timestamp' => $timestamp,
                'recurrence' => $recurrence,
                'hook' => $hook,
                'args' => $args,
            ];
            return true;
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
            update_option('hic_realtime_brevo_sync', '1');
            update_option('hic_brevo_api_key', 'test-key');
            $GLOBALS['next_scheduled'] = false;
            $GLOBALS['schedule_defined'] = true;
            $GLOBALS['scheduled_events'] = [];
        }

        public function test_schedules_retry_when_conditions_met(): void {
            $this->assertTrue(\FpHic\Helpers\hic_should_schedule_retry_event());
            \FpHic\Helpers\hic_schedule_failed_request_retry();
            $this->assertNotEmpty($GLOBALS['scheduled_events']);
            $this->assertSame('hic_retry_failed_requests', $GLOBALS['scheduled_events'][0]['hook']);
        }

        public function test_does_not_schedule_when_brevo_sync_disabled(): void {
            update_option('hic_realtime_brevo_sync', '0');
            \FpHic\Helpers\hic_clear_option_cache();
            $GLOBALS['scheduled_events'] = [];
            \FpHic\Helpers\hic_schedule_failed_request_retry();
            $this->assertEmpty($GLOBALS['scheduled_events']);
        }

        public function test_does_not_schedule_when_schedule_missing(): void {
            $GLOBALS['schedule_defined'] = false;
            $GLOBALS['scheduled_events'] = [];
            \FpHic\Helpers\hic_schedule_failed_request_retry();
            $this->assertEmpty($GLOBALS['scheduled_events']);
        }
    }
}
