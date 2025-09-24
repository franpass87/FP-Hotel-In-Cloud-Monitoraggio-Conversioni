<?php
require_once __DIR__ . '/../includes/api/webhook.php';
require_once __DIR__ . '/../includes/input-validator.php';

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private $method;
        private $route;
        private $params = [];
        private $headers = [];

        public function __construct($method = 'GET', $route = '', $attributes = [])
        {
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

        public function set_param($key, $value): void
        {
            $this->params[$key] = $value;
        }

        public function get_param($key)
        {
            return $this->params[$key] ?? null;
        }

        public function set_header($key, $value): void
        {
            $this->headers[strtolower($key)] = $value;
        }

        public function get_header($key)
        {
            $key = strtolower($key);
            return $this->headers[$key] ?? '';
        }
    }
}

if (!class_exists('WebhookSignatureStream')) {
    class WebhookSignatureStream {
        public static $content = '';
        private $offset = 0;

        public function stream_open($path, $mode, $options, &$opened_path)
        {
            $this->offset = 0;
            return true;
        }

        public function stream_read($count)
        {
            $chunk = substr(self::$content, $this->offset, $count);
            $this->offset += strlen($chunk);
            return $chunk;
        }

        public function stream_eof()
        {
            return $this->offset >= strlen(self::$content);
        }

        public function stream_stat()
        {
            return [];
        }
    }
}

final class WebhookSignatureValidationTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        update_option('hic_connection_type', 'webhook');
        update_option('hic_webhook_token', 'signature_token');
        update_option('hic_webhook_secret', 'super-secret-key');
        \FpHic\Helpers\hic_clear_option_cache();
    }

    protected function tearDown(): void
    {
        delete_option('hic_webhook_secret');
        delete_option('hic_webhook_token');
        delete_option('hic_connection_type');
        \FpHic\Helpers\hic_clear_option_cache();
        parent::tearDown();
    }

    private function withWebhookBody(string $body, callable $callback)
    {
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', WebhookSignatureStream::class);
        WebhookSignatureStream::$content = $body;

        try {
            return $callback();
        } finally {
            stream_wrapper_restore('php');
            WebhookSignatureStream::$content = '';
        }
    }

    private function buildPayload(): array
    {
        return [
            'email' => 'mario.rossi@example.com',
            'reservation_id' => 'HIC_SIG_123',
            'guest_first_name' => 'Mario',
            'guest_last_name' => 'Rossi',
            'amount' => 120.0,
            'currency' => 'EUR',
            'checkin' => '2025-06-10',
            'checkout' => '2025-06-12',
        ];
    }

    public function test_webhook_requires_signature_when_secret_is_configured(): void
    {
        $payload = $this->buildPayload();
        $json_payload = wp_json_encode($payload);

        $result = $this->withWebhookBody($json_payload, function () {
            $request = new WP_REST_Request(
                ['token' => 'signature_token', 'email' => 'mario.rossi@example.com'],
                ['content-type' => 'application/json']
            );

            return hic_webhook_handler($request);
        });

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('missing_signature', $result->get_error_code());
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $payload = $this->buildPayload();
        $json_payload = wp_json_encode($payload);

        $result = $this->withWebhookBody($json_payload, function () {
            $request = new WP_REST_Request(
                ['token' => 'signature_token', 'email' => 'mario.rossi@example.com'],
                [
                    'content-type' => 'application/json',
                    HIC_WEBHOOK_SIGNATURE_HEADER => 'sha256=invalid',
                ]
            );

            return hic_webhook_handler($request);
        });

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_signature', $result->get_error_code());
    }

    public function test_signature_helper_accepts_hex_and_base64_formats(): void
    {
        $payload = wp_json_encode($this->buildPayload());
        $secret = 'super-secret-key';
        $hex_signature = hic_generate_webhook_signature($payload, $secret);
        $this->assertTrue(hic_verify_webhook_signature($payload, 'sha256=' . strtoupper($hex_signature), $secret));

        $binary = hex2bin($hex_signature);
        $this->assertNotFalse($binary);
        $base64_signature = base64_encode($binary);
        $this->assertTrue(hic_verify_webhook_signature($payload, $base64_signature, $secret));
    }
}
