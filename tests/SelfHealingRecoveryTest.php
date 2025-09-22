<?php
namespace {
    use FpHic\HIC_Booking_Poller;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../includes/booking-poller.php';
    require_once __DIR__ . '/../includes/log-manager.php';
    require_once __DIR__ . '/../includes/helpers-logging.php';

    final class SelfHealingRecoveryTest extends TestCase {
        /** @var callable|null */
        private $logFilter;

        protected function setUp(): void {
            global $recovery_log_messages, $wp_scheduled_events, $wp_schedule_event_invalid;

            $recovery_log_messages = [];
            $wp_scheduled_events = [];
            $wp_schedule_event_invalid = [
                'hic_daily' => true,
            ];

            \FpHic\Helpers\hic_clear_option_cache();

            update_option('hic_reliable_polling_enabled', '1');
            update_option('hic_connection_type', 'api');
            update_option('hic_api_url', 'https://example.com');
            update_option('hic_property_id', '123');
            update_option('hic_api_email', 'test@example.com');
            update_option('hic_api_password', 'secret');
            update_option('hic_last_continuous_poll', 0);
            update_option('hic_last_deep_check', 0);
            update_option('hic_last_successful_poll', 0);
            update_option('hic_last_successful_deep_check', 0);
            update_option('hic_log_file', sys_get_temp_dir() . '/hic-test.log');

            \FpHic\Helpers\hic_clear_option_cache('log_file');
            $GLOBALS['hic_log_manager'] = null;

            $this->logFilter = function ($msg, $level) {
                global $recovery_log_messages;
                $recovery_log_messages[] = [
                    'msg' => $msg,
                    'level' => $level,
                ];
                return $msg;
            };

            add_filter('hic_log_message', $this->logFilter, 10, 2);
        }

        protected function tearDown(): void {
            global $wp_schedule_event_invalid, $hic_test_filters;

            $wp_schedule_event_invalid = [];

            if ($this->logFilter && isset($hic_test_filters['hic_log_message'][10])) {
                foreach ($hic_test_filters['hic_log_message'][10] as $index => $callback) {
                    if ($callback['function'] === $this->logFilter) {
                        unset($hic_test_filters['hic_log_message'][10][$index]);
                    }
                }
            }

            $this->logFilter = null;
        }

        public function test_recovery_schedules_cleanup_events_with_daily_recurrence(): void {
            global $recovery_log_messages, $wp_scheduled_events;

            $poller = new HIC_Booking_Poller();
            $poller->execute_self_healing_recovery();

            $cleanupEvents = array_values(array_filter(
                $wp_scheduled_events,
                function ($event) {
                    return in_array($event['hook'], ['hic_cleanup_event', 'hic_booking_events_cleanup'], true);
                }
            ));

            $this->assertCount(2, $cleanupEvents, 'Both cleanup events should be scheduled during recovery.');

            foreach ($cleanupEvents as $event) {
                $this->assertSame('daily', $event['recurrence']);
            }

            $invalidErrors = array_filter(
                $recovery_log_messages,
                function ($entry) {
                    return $entry['level'] === HIC_LOG_LEVEL_ERROR
                        && strpos($entry['msg'], 'Invalid schedule') !== false;
                }
            );

            $this->assertEmpty($invalidErrors, 'Recovery should not log invalid schedule errors for cleanup hooks.');
        }
    }
}
