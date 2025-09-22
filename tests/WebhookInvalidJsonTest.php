<?php
require_once __DIR__ . '/../includes/api/webhook.php';

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
}
