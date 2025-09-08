<?php
namespace FpHic {
    function hic_validate_api_timestamp($timestamp, $context = 'api_request') {
        $GLOBALS['poll_calls'][] = 'validate';
        return $timestamp;
    }
    function hic_api_poll_bookings_continuous() {
        $GLOBALS['poll_calls'][] = 'continuous';
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
    }
}
