<?php

require_once __DIR__ . '/../includes/api/webhook.php';

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        /** @var array<string,mixed> */
        private $params = [];

        /** @var array<string,string> */
        private $headers = [];

        /** @var string */
        private $body = '';

        public function __construct($method = 'GET', $route = '', $attributes = [])
        {
            if (is_array($method)) {
                $this->params = $method;
                if (is_array($route)) {
                    $this->headers = array_change_key_case($route, CASE_LOWER);
                }
                return;
            }

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
            $this->headers[strtolower((string) $key)] = (string) $value;
        }

        public function get_header($key)
        {
            $key = strtolower((string) $key);
            return $this->headers[$key] ?? '';
        }

        /** @return array<string,string> */
        public function get_headers(): array
        {
            return $this->headers;
        }

        public function set_body($body): void
        {
            $this->body = is_string($body) ? $body : '';
        }

        public function get_body(): string
        {
            return $this->body;
        }

        public function get_content(): string
        {
            return $this->body;
        }
    }
}

class WebhookPermissionCallbackTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        update_option('hic_webhook_token', 'expected-token');
        \FpHic\Helpers\hic_clear_option_cache('webhook_token');
    }

    public function test_permission_callback_allows_valid_token(): void
    {
        $request = new WP_REST_Request('POST', '/hic/v1/conversion');
        $request->set_param('token', 'expected-token');

        $this->assertTrue(hic_webhook_permission_callback($request));
    }

    public function test_permission_callback_rejects_invalid_token(): void
    {
        $request = new WP_REST_Request('POST', '/hic/v1/conversion');
        $request->set_param('token', 'invalid-token');

        $result = hic_webhook_permission_callback($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_token', $result->get_error_code());
        $this->assertSame(403, $result->get_error_data()['status'] ?? null);
    }

    public function test_permission_callback_requires_configured_token(): void
    {
        delete_option('hic_webhook_token');
        \FpHic\Helpers\hic_clear_option_cache('webhook_token');

        $request = new WP_REST_Request('POST', '/hic/v1/conversion');
        $request->set_param('token', 'expected-token');

        $result = hic_webhook_permission_callback($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('missing_token', $result->get_error_code());
        $this->assertSame(500, $result->get_error_data()['status'] ?? null);
    }

    public function test_permission_callback_trims_token_whitespace(): void
    {
        $request = new WP_REST_Request('POST', '/hic/v1/conversion');
        $request->set_param('token', '  expected-token  ');

        $this->assertTrue(hic_webhook_permission_callback($request));
    }
}
