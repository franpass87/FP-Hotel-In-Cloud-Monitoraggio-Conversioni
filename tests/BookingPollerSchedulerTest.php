<?php
namespace FpHic {
    function hic_validate_api_timestamp($timestamp, $context = 'api_request') {
        $GLOBALS['poll_calls'][] = 'validate';
        return $timestamp;
    }
    function hic_api_poll_bookings_continuous() {
        $GLOBALS['poll_calls'][] = 'continuous';
        if (!empty($GLOBALS['simulate_continuous_exception'])) {
            throw new \Exception('Simulated exception');
        }
        if (!empty($GLOBALS['simulate_continuous_error'])) {
            return new \WP_Error('poll_error', 'Simulated error');
        }
        if (!empty($GLOBALS['simulate_continuous_skip'])) {
            return false;
        }
        update_option('hic_last_successful_poll', time());
        return true;
    }
    function hic_api_poll_bookings() {
        $GLOBALS['poll_calls'][] = 'fallback';
    }
    function hic_api_poll_bookings_deep_check() {
        $GLOBALS['poll_calls'][] = 'deep';
        if (!empty($GLOBALS['simulate_deep_exception'])) {
            throw new \Exception('Simulated exception');
        }
        if (!empty($GLOBALS['simulate_deep_error'])) {
            return new \WP_Error('poll_error', 'Simulated error');
        }
        if (!empty($GLOBALS['simulate_deep_skip'])) {
            return null;
        }
        update_option('hic_last_successful_poll', time());
        return true;
    }
    function hic_fetch_reservations_raw($prop_id, $mode, $from_date, $to_date, $limit) {
        $GLOBALS['poll_calls'][] = 'fetch';
        return [];
    }
}

namespace {
    use FpHic\HIC_Booking_Poller;
    use PHPUnit\Framework\TestCase;

    function hic_force_wp_cron_disabled_filter($disabled)
    {
        return true;
    }

    require_once __DIR__ . '/../includes/booking-poller.php';

    class BookingPollerSchedulerTest extends TestCase {
        protected function setUp(): void {
            $GLOBALS['poll_calls'] = [];
            if (!isset($GLOBALS['hic_test_hooks'])) {
                $GLOBALS['hic_test_hooks'] = [];
            }
            if (!isset($GLOBALS['hic_test_filters'])) {
                $GLOBALS['hic_test_filters'] = [];
            }
            if (!isset($GLOBALS['wp_scheduled_events'])) {
                $GLOBALS['wp_scheduled_events'] = [];
            }
            update_option('hic_last_successful_poll', 0);
            update_option('hic_last_deep_check', 0);
        }

        public function test_execute_continuous_polling_calls_namespaced_function(): void {
            $poller = new HIC_Booking_Poller();
            $poller->execute_continuous_polling();
            $this->assertContains('continuous', $GLOBALS['poll_calls']);
        }

        public function test_execute_deep_check_calls_namespaced_function(): void {
            $poller = new HIC_Booking_Poller();
            $poller->execute_deep_check();
            $this->assertContains('deep', $GLOBALS['poll_calls']);
        }

        public function test_execute_deep_check_updates_timestamp_on_success(): void {
            $poller = new HIC_Booking_Poller();
            update_option('hic_last_deep_check', 0);

            $poller->execute_deep_check();

            $this->assertNotEquals(0, get_option('hic_last_deep_check'));
        }

        public function test_execute_deep_check_failure_does_not_update_timestamp(): void {
            $poller = new HIC_Booking_Poller();
            $old = time() - 100;
            update_option('hic_last_deep_check', $old);

            $GLOBALS['simulate_deep_error'] = true;
            $poller->execute_deep_check();

            $this->assertSame($old, get_option('hic_last_deep_check'));
            unset($GLOBALS['simulate_deep_error']);
        }

        public function test_execute_deep_check_skipped_does_not_update_timestamp(): void {
            $poller = new HIC_Booking_Poller();
            $old = time() - 100;
            update_option('hic_last_deep_check', $old);

            $GLOBALS['simulate_deep_skip'] = true;
            $poller->execute_deep_check();

            $this->assertSame($old, get_option('hic_last_deep_check'));
            unset($GLOBALS['simulate_deep_skip']);
        }

        public function test_execute_deep_check_exception_is_handled(): void {
            $poller = new HIC_Booking_Poller();
            update_option('hic_deep_check_failures', 0);

            $GLOBALS['simulate_deep_exception'] = true;
            $poller->execute_deep_check();

            $this->assertSame(0, get_option('hic_last_deep_check'));
            $this->assertSame(1, get_option('hic_deep_check_failures'));
            unset($GLOBALS['simulate_deep_exception']);
        }

        public function test_deep_check_recovers_after_exception(): void {
            $poller = new HIC_Booking_Poller();
            update_option('hic_deep_check_failures', 0);
            update_option('hic_last_deep_check', 0);

            $GLOBALS['simulate_deep_exception'] = true;
            $poller->execute_deep_check();
            unset($GLOBALS['simulate_deep_exception']);

            $poller->execute_deep_check();

            $this->assertNotEquals(0, get_option('hic_last_deep_check'));
            $this->assertSame(1, get_option('hic_deep_check_failures'));
        }

        public function test_execute_continuous_polling_updates_timestamp_on_success(): void {
            $poller = new HIC_Booking_Poller();
            update_option('hic_last_continuous_poll', 0);

            $poller->execute_continuous_polling();

            $this->assertNotEquals(0, get_option('hic_last_continuous_poll'));
            $this->assertNotEquals(0, get_option('hic_last_successful_poll'));
        }

        public function test_execute_continuous_polling_skipped_does_not_update_timestamp(): void {
            $poller = new HIC_Booking_Poller();
            $old = time() - 100;
            $old_success = time() - 50;
            update_option('hic_last_continuous_poll', $old);
            update_option('hic_last_successful_poll', $old_success);

            $GLOBALS['simulate_continuous_skip'] = true;
            $poller->execute_continuous_polling();

            $this->assertSame($old, get_option('hic_last_continuous_poll'));
            $this->assertSame($old_success, get_option('hic_last_successful_poll'));
            unset($GLOBALS['simulate_continuous_skip']);
        }

        public function test_execute_continuous_polling_exception_is_handled(): void {
            $poller = new HIC_Booking_Poller();
            update_option('hic_continuous_poll_failures', 0);
            update_option('hic_last_continuous_poll', 0);
            update_option('hic_last_successful_poll', 0);

            $GLOBALS['simulate_continuous_exception'] = true;
            $poller->execute_continuous_polling();

            $this->assertSame(0, get_option('hic_last_continuous_poll'));
            $this->assertSame(1, get_option('hic_continuous_poll_failures'));
            unset($GLOBALS['simulate_continuous_exception']);
        }

        public function test_continuous_polling_recovers_after_exception(): void {
            $poller = new HIC_Booking_Poller();
            update_option('hic_continuous_poll_failures', 0);
            update_option('hic_last_continuous_poll', 0);
            update_option('hic_last_successful_poll', 0);

            $GLOBALS['simulate_continuous_exception'] = true;
            $poller->execute_continuous_polling();
            unset($GLOBALS['simulate_continuous_exception']);

            $poller->execute_continuous_polling();

            $this->assertNotEquals(0, get_option('hic_last_continuous_poll'));
            $this->assertNotEquals(0, get_option('hic_last_successful_poll'));
            $this->assertSame(1, get_option('hic_continuous_poll_failures'));
        }

        public function test_watchdog_detects_polling_failure(): void {
            $poller = new HIC_Booking_Poller();
            $old = time() - (HIC_WATCHDOG_THRESHOLD + 10);
            $old_success = time();
            update_option('hic_last_continuous_poll', $old);
            update_option('hic_last_successful_poll', $old_success);

            $GLOBALS['simulate_continuous_error'] = true;
            $poller->execute_continuous_polling();
            $this->assertSame($old, get_option('hic_last_continuous_poll'));
            $this->assertSame($old_success, get_option('hic_last_successful_poll'));

            $poller->run_watchdog_check();
            $this->assertSame($old_success, get_option('hic_last_successful_poll'));
            $continuous = array_filter(
                $GLOBALS['poll_calls'],
                function ($call) { return $call === 'continuous'; }
            );
            $this->assertCount(2, $continuous);
            unset($GLOBALS['simulate_continuous_error']);
        }

        public function test_watchdog_not_blocked_with_deep_check_only(): void {
            $poller = new HIC_Booking_Poller();
            $sentinel = 123;
            update_option('hic_last_updates_since', $sentinel);
            update_option('hic_last_successful_poll', time() - 7200);
            update_option('hic_last_continuous_poll', 0);
            update_option('hic_last_deep_check', 0);

            $poller->execute_deep_check();

            $this->assertNotEquals(0, get_option('hic_last_successful_poll'));

            $poller->run_watchdog_check();

            $this->assertSame($sentinel, get_option('hic_last_updates_since'));
        }

        public function test_fallback_polling_runs_immediately_when_wp_cron_disabled(): void {
            $poller = new HIC_Booking_Poller();

            update_option('hic_reliable_polling_enabled', '1');
            update_option('hic_connection_type', 'api');
            update_option('hic_api_url', 'https://api.example.com');
            update_option('hic_property_id', 'prop_123');
            update_option('hic_api_email', 'user@example.com');
            update_option('hic_api_password', 'secret');
            update_option('hic_last_continuous_poll', time() - 7200);
            update_option('hic_last_successful_poll', time() - 7200);

            delete_transient('hic_fallback_polling_lock');

            add_filter('hic_is_wp_cron_disabled', '\\hic_force_wp_cron_disabled_filter');

            $poller->fallback_polling_check();

            remove_filter('hic_is_wp_cron_disabled', '\\hic_force_wp_cron_disabled_filter');

            $this->assertContains('continuous', $GLOBALS['poll_calls']);
        }

        public function test_fallback_polling_runs_immediately_when_single_event_errors(): void
        {
            $poller = new HIC_Booking_Poller();

            update_option('hic_reliable_polling_enabled', '1');
            update_option('hic_connection_type', 'api');
            update_option('hic_api_url', 'https://api.example.com');
            update_option('hic_property_id', 'prop_123');
            update_option('hic_api_email', 'user@example.com');
            update_option('hic_api_password', 'secret');
            update_option('hic_last_continuous_poll', time() - 7200);
            update_option('hic_last_successful_poll', time() - 7200);

            delete_transient('hic_fallback_polling_lock');

            $GLOBALS['hic_test_schedule_single_event_return'] = new \WP_Error('schedule_failed', 'Forced failure');

            $poller->fallback_polling_check();

            unset($GLOBALS['hic_test_schedule_single_event_return']);

            $this->assertContains('continuous', $GLOBALS['poll_calls']);
        }

        public function test_fallback_event_handler_not_registered_multiple_times(): void
        {
            $poller = new HIC_Booking_Poller();

            $initial_count = $this->get_hook_registration_count('hic_fallback_poll_event');

            update_option('hic_reliable_polling_enabled', '1');
            update_option('hic_connection_type', 'api');
            update_option('hic_api_url', 'https://api.example.com');
            update_option('hic_property_id', 'prop_123');
            update_option('hic_api_email', 'user@example.com');
            update_option('hic_api_password', 'secret');
            update_option('hic_last_continuous_poll', time() - 7200);
            update_option('hic_last_successful_poll', time() - 7200);

            delete_transient('hic_fallback_polling_lock');

            $poller->fallback_polling_check();

            $this->assertSame($initial_count, $this->get_hook_registration_count('hic_fallback_poll_event'));
        }

        private function get_hook_registration_count(string $hook, int $priority = 10): int
        {
            if (empty($GLOBALS['hic_test_hooks'][$hook][$priority])) {
                return 0;
            }

            return \count($GLOBALS['hic_test_hooks'][$hook][$priority]);
        }
    }
}
