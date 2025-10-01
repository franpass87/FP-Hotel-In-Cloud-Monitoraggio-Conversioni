<?php
namespace FpHic\Helpers {
    if (!function_exists(__NAMESPACE__ . '\\wp_get_schedules')) {
        function wp_get_schedules(): array
        {
            if (isset($GLOBALS['hic_test_schedules'])) {
                return $GLOBALS['hic_test_schedules'];
            }

            if (isset($GLOBALS['should_schedule_retry_event_schedules'])) {
                return $GLOBALS['should_schedule_retry_event_schedules'];
            }

            return [
                'hic_every_fifteen_minutes' => [
                    'interval' => 15 * 60,
                    'display'  => 'Every 15 Minutes (HIC Failed Requests)',
                ],
            ];
        }
    }
}

namespace {
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/../includes/helpers-scheduling.php';
    require_once __DIR__ . '/../includes/helpers/api.php';

    final class FailedRequestSchedulingTest extends TestCase
    {
        /** @var mixed */
        private $previousWpdb;

        protected function setUp(): void
        {
            parent::setUp();

            $GLOBALS['hic_test_schedules'] = [
                'hic_every_fifteen_minutes' => [
                    'interval' => 15 * MINUTE_IN_SECONDS,
                    'display'  => 'Every 15 Minutes (HIC Failed Requests)',
                ],
            ];

            $GLOBALS['wp_scheduled_events'] = [];
            $GLOBALS['wp_schedule_event_invalid'] = [];
            $GLOBALS['next_scheduled'] = false;

            global $wpdb;
            $this->previousWpdb = $wpdb ?? null;
            $wpdb = new class {
                public $prefix = 'wp_';
                public $count = 0;
                public $last_error = '';

                public function insert($table, $data, $format = null)
                {
                    $this->count++;

                    return 1;
                }

                public function get_var($query)
                {
                    return $this->count;
                }
            };
        }

        protected function tearDown(): void
        {
            unset(
                $GLOBALS['hic_test_schedules'],
                $GLOBALS['wp_scheduled_events'],
                $GLOBALS['wp_schedule_event_invalid'],
                $GLOBALS['next_scheduled']
            );

            global $wpdb;
            $wpdb = $this->previousWpdb;
            $this->previousWpdb = null;

            parent::tearDown();
        }

        public function test_storing_failed_request_triggers_retry_scheduler(): void
        {
            \FpHic\Helpers\hic_store_failed_request('https://example.com', ['body' => []], 'timeout');

            $this->assertNotEmpty($GLOBALS['wp_scheduled_events']);
            $event = $GLOBALS['wp_scheduled_events'][0] ?? null;
            $this->assertIsArray($event);
            $this->assertSame('hic_retry_failed_requests', $event['hook']);
        }
    }
}
