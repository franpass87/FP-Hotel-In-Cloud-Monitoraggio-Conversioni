<?php
namespace FpHic\Helpers {
    if (!function_exists(__NAMESPACE__ . '\\wp_get_schedules')) {
        function wp_get_schedules() {
            return [
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
            update_option('hic_realtime_brevo_sync', '1');
            update_option('hic_brevo_api_key', 'test-key');
        }

        public function test_returns_true_when_brevo_sync_active(): void {
            $this->assertTrue(\FpHic\Helpers\hic_should_schedule_retry_event());
        }
    }
}
