<?php
namespace FpHic {
    function hic_validate_api_timestamp($timestamp, $context = 'api_request') {
        $GLOBALS['poll_calls'][] = 'validate';
        return $timestamp;
    }
    function hic_api_poll_bookings_continuous() {
        $GLOBALS['poll_calls'][] = 'continuous';
        if (!empty($GLOBALS['simulate_continuous_error'])) {
            return new \WP_Error('poll_error', 'Simulated error');
        }
        update_option('hic_last_successful_poll', time());
        return true;
    }
    function hic_api_poll_bookings() {
        $GLOBALS['poll_calls'][] = 'fallback';
    }
    function hic_api_poll_bookings_deep_check() {
        $GLOBALS['poll_calls'][] = 'deep';
    }
    function hic_fetch_reservations_raw($prop_id, $mode, $from_date, $to_date, $limit) {
        $GLOBALS['poll_calls'][] = 'fetch';
        return [];
    }
}

namespace {
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../includes/booking-poller.php';

    class BookingPollerSchedulerTest extends TestCase {
        protected function setUp(): void {
            $GLOBALS['poll_calls'] = [];
            update_option('hic_last_successful_poll', 0);
        }

        public function test_execute_continuous_polling_calls_namespaced_function(): void {
            $poller = new \HIC_Booking_Poller();
            $poller->execute_continuous_polling();
            $this->assertContains('continuous', $GLOBALS['poll_calls']);
        }

        public function test_execute_deep_check_calls_namespaced_function(): void {
            $poller = new \HIC_Booking_Poller();
            $poller->execute_deep_check();
            $this->assertContains('deep', $GLOBALS['poll_calls']);
        }

        public function test_execute_continuous_polling_updates_timestamp_on_success(): void {
            $poller = new \HIC_Booking_Poller();
            update_option('hic_last_continuous_poll', 0);

            $poller->execute_continuous_polling();

            $this->assertNotEquals(0, get_option('hic_last_continuous_poll'));
            $this->assertNotEquals(0, get_option('hic_last_successful_poll'));
        }

        public function test_watchdog_detects_polling_failure(): void {
            $poller = new \HIC_Booking_Poller();
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
    }
}
