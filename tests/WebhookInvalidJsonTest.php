<?php
require_once __DIR__ . '/../includes/api/webhook.php';
require_once __DIR__ . '/../includes/input-validator.php';

if (!class_exists('MockPhpStream')) {
    class MockPhpStream {
        public static $content = '';
        private $index = 0;

        public function stream_open($path, $mode, $options, &$opened_path) {
            $this->index = 0;
            return true;
        }

        public function stream_read($count) {
            $chunk = substr(self::$content, $this->index, $count);
            $this->index += strlen($chunk);
            return $chunk;
        }

        public function stream_eof() {
            return $this->index >= strlen(self::$content);
        }

        public function stream_stat() {
            return [];
        }
    }
}

if (!class_exists('MockPhpStreamFailure')) {
    class MockPhpStreamFailure {
        public function stream_open($path, $mode, $options, &$opened_path) {
            return false;
        }

        public function stream_stat() {
            return [];
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private $method;
        private $route;
        private $params = [];
        private $headers = [];

        public function __construct($method = 'GET', $route = '', $attributes = []) {
            if (is_array($method)) {
                $this->params = $method;
                if (is_array($route)) {
                    $this->headers = array_change_key_case($route, CASE_LOWER);
                }
                return;
            }

            $this->method = $method;
            $this->route  = $route;

            if (is_array($attributes)) {
                $this->params = $attributes;
            }
        }

        public function set_param($key, $value): void {
            $this->params[$key] = $value;
        }

        public function get_param($key) {
            return $this->params[$key] ?? null;
        }

        public function set_header($key, $value): void {
            $this->headers[strtolower($key)] = $value;
        }

        public function get_header($key) {
            $key = strtolower($key);
            return $this->headers[$key] ?? '';
        }
    }
}

use PHPUnit\Framework\TestCase;

final class WebhookInvalidJsonTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        update_option('hic_connection_type', 'webhook');
        update_option('hic_webhook_token', 'secret');
        \FpHic\Helpers\hic_clear_option_cache('connection_type');
        \FpHic\Helpers\hic_clear_option_cache('webhook_token');
    }

    public function test_returns_wp_error_on_invalid_json(): void {
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', MockPhpStream::class);
        MockPhpStream::$content = '{"bad":';

        $request = new WP_REST_Request(
            ['token' => 'secret', 'email' => 'user@example.com'],
            ['content-type' => 'application/json']
        );

        $result = hic_webhook_handler($request);

        stream_wrapper_restore('php');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_json', $result->get_error_code());
    }

    public function test_returns_wp_error_when_body_unreadable(): void {
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', MockPhpStreamFailure::class);

        $request = new WP_REST_Request(
            ['token' => 'secret', 'email' => 'user@example.com'],
            ['content-type' => 'application/json']
        );

        $result = null;

        try {
            $result = hic_webhook_handler($request);
        } finally {
            stream_wrapper_restore('php');
        }

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_body', $result->get_error_code());

        $error_data = $result->get_error_data();
        $this->assertIsArray($error_data);
        $this->assertArrayHasKey('status', $error_data);
        $this->assertSame(400, $error_data['status']);
    }


    public function test_webhook_allows_missing_email_payload(): void {
        $logFile = sys_get_temp_dir() . '/hic-webhook-missing-email.log';
        if (file_exists($logFile)) {
            unlink($logFile);
        }

        update_option('hic_log_file', $logFile);
        \FpHic\Helpers\hic_clear_option_cache('log_file');
        unset($GLOBALS['hic_log_manager']);

        update_option('hic_brevo_enabled', '1');
        update_option('hic_brevo_api_key', 'test-key');
        \FpHic\Helpers\hic_clear_option_cache('brevo_enabled');
        \FpHic\Helpers\hic_clear_option_cache('brevo_api_key');

        update_option('hic_synced_res_ids', []);
        \FpHic\Helpers\hic_clear_option_cache('synced_res_ids');

        $capturedLogs = [];
        $logFilter = function ($message) use (&$capturedLogs) {
            if (is_array($message) || is_object($message)) {
                $message = json_encode($message, JSON_UNESCAPED_UNICODE);
            } elseif (!is_string($message)) {
                $message = (string) $message;
            }

            $capturedLogs[] = $message;

            return $message;
        };
        add_filter('hic_log_message', $logFilter, 20, 2);

        stream_wrapper_unregister('php');
        stream_wrapper_register('php', MockPhpStream::class);

        $payload = [
            'reservation_id' => 'MISSING_EMAIL_TEST',
            'amount' => 99.95,
            'currency' => 'EUR',
        ];
        MockPhpStream::$content = wp_json_encode($payload);

        $request = new WP_REST_Request('POST', '/hic/v1/conversion');
        $request->set_param('token', 'secret');
        $request->set_header('content-type', 'application/json');

        $response = null;

        try {
            $response = hic_webhook_handler($request);
        } finally {
            stream_wrapper_restore('php');
            unset($GLOBALS['hic_test_filters']['hic_log_message']);
        }

        if (file_exists($logFile)) {
            unlink($logFile);
        }

        $this->assertIsArray($response);
        $this->assertSame('ok', $response['status']);
        $this->assertArrayHasKey('processed', $response);
        $this->assertTrue($response['processed']);
        $this->assertArrayHasKey('result', $response);
        $this->assertArrayNotHasKey('reason', $response);

        $result = $response['result'];
        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertTrue(in_array($result['status'], ['success', 'partial'], true));
        $this->assertArrayHasKey('should_mark_processed', $result);
        $this->assertTrue($result['should_mark_processed']);

        $this->assertArrayHasKey('summary', $result);
        $this->assertStringContainsString('Brevo contact=skipped (missing email)', $result['summary']);

        $synced = get_option('hic_synced_res_ids', []);
        $this->assertIsArray($synced);
        $this->assertContains('MISSING_EMAIL_TEST', $synced, 'Reservation should be marked as processed even without email.');

        $logFound = false;
        foreach ($capturedLogs as $entry) {
            if (strpos($entry, 'Brevo contact dispatch skipped - missing email') !== false) {
                $logFound = true;
                break;
            }
        }

        $this->assertTrue($logFound, 'hic_process_booking_data should log the Brevo contact skip when email is absent.');

        update_option('hic_synced_res_ids', []);
        \FpHic\Helpers\hic_clear_option_cache('synced_res_ids');
    }

}
