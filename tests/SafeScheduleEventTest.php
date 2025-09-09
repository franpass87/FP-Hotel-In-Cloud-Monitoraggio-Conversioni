<?php

namespace FpHic\Helpers {
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = array(), $wp_error = false) {
        if (!empty($GLOBALS['simulate_schedule_error'])) {
            return new \WP_Error('schedule_error', 'Simulated scheduling error');
        }
        return \wp_schedule_event($timestamp, $recurrence, $hook, $args);
    }
}

namespace {
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/../includes/log-manager.php';

    class SafeScheduleEventTest extends TestCase {
        protected function setUp(): void {
            global $schedule_log_messages;
            $schedule_log_messages = [];
            update_option('hic_log_file', sys_get_temp_dir() . '/hic-test.log');
            add_filter('hic_log_message', function($msg, $level) {
                global $schedule_log_messages;
                $schedule_log_messages[] = ['msg' => $msg, 'level' => $level];
                return $msg;
            }, 10, 2);
        }

        public function test_logs_error_on_schedule_failure(): void {
            global $schedule_log_messages;
            $GLOBALS['simulate_schedule_error'] = true;
            $result = \FpHic\Helpers\hic_safe_wp_schedule_event(time(), 'hourly', 'test_hook');
            unset($GLOBALS['simulate_schedule_error']);
            $this->assertFalse($result);
            $this->assertNotEmpty($schedule_log_messages);
            $this->assertSame(HIC_LOG_LEVEL_ERROR, $schedule_log_messages[0]['level']);
            $this->assertStringContainsString('Scheduling error for test_hook: Simulated scheduling error', $schedule_log_messages[0]['msg']);
        }
    }
}

