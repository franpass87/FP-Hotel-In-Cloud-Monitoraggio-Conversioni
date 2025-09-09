<?php
namespace FpHic {
    function hic_backfill_reservations($from_date, $to_date, $date_type, $limit) {
        $GLOBALS['backfill_params'] = compact('from_date', 'to_date', 'date_type', 'limit');
        return [
            'success' => true,
            'stats' => [
                'total_found' => 0,
                'total_processed' => 0,
                'total_skipped' => 0,
                'total_errors' => 0,
                'execution_time' => 0,
            ],
            'message' => 'ok',
        ];
    }
}
namespace {
    use PHPUnit\Framework\TestCase;
    require_once __DIR__ . '/bootstrap.php';

    if (!function_exists('check_ajax_referer')) {
        function check_ajax_referer($action, $arg = false, $die = true) {
            return true;
        }
    }
    if (!function_exists('__')) {
        function __($text, $domain = null) {
            return $text;
        }
    }
    if (!function_exists('wp_unslash')) {
        function wp_unslash($value) {
            return $value;
        }
    }
    if (!function_exists('wp_send_json_error')) {
        function wp_send_json_error($data) {
            $GLOBALS['ajax_response'] = ['success' => false, 'data' => $data];
        }
    }
    if (!function_exists('wp_send_json_success')) {
        function wp_send_json_success($data) {
            $GLOBALS['ajax_response'] = ['success' => true, 'data' => $data];
        }
    }

    require_once dirname(__DIR__) . '/includes/admin/diagnostics.php';

    final class BackfillLimitTest extends TestCase {
        protected function setUp(): void {
            $GLOBALS['ajax_response'] = null;
            $GLOBALS['backfill_params'] = null;
        }

        public function test_limit_clamped_to_valid_range(): void {
            $_POST = [
                'nonce' => 'abc',
                'from_date' => '2024-01-01',
                'to_date' => '2024-01-05',
                'date_type' => 'checkin',
                'limit' => '500',
            ];
            hic_ajax_backfill_reservations();
            $this->assertSame(200, $GLOBALS['backfill_params']['limit']);

            $_POST['limit'] = '0';
            hic_ajax_backfill_reservations();
            $this->assertSame(1, $GLOBALS['backfill_params']['limit']);
        }
    }
}
