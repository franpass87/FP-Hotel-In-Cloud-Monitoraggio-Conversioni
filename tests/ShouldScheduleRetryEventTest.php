<?php
namespace FpHic\Helpers {
    if (!function_exists(__NAMESPACE__ . '\\wp_get_schedules')) {
        function wp_get_schedules() {
            return $GLOBALS['should_schedule_retry_event_schedules'] ?? [
                'hic_every_fifteen_minutes' => [
                    'interval' => 15 * 60,
                    'display' => 'Every 15 Minutes (HIC Failed Requests)'
                ],
            ];
        }
    }
}

namespace {
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../includes/functions.php';

    class ShouldScheduleRetryEventTest extends TestCase {
        protected function setUp(): void {
            \FpHic\Helpers\hic_clear_option_cache();
            $GLOBALS['should_schedule_retry_event_schedules'] = [
                'hic_every_fifteen_minutes' => [
                    'interval' => 15 * 60,
                    'display' => 'Every 15 Minutes (HIC Failed Requests)'
                ],
            ];
        }

        protected function tearDown(): void {
            unset($GLOBALS['should_schedule_retry_event_schedules']);
        }

        public function test_returns_true_when_custom_interval_registered(): void {
            $this->assertTrue(\FpHic\Helpers\hic_should_schedule_retry_event());
        }

        public function test_returns_false_when_custom_interval_missing(): void {
            $GLOBALS['should_schedule_retry_event_schedules'] = [];
            $this->assertFalse(\FpHic\Helpers\hic_should_schedule_retry_event());
        }
    }
}
