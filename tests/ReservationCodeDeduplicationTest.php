<?php declare(strict_types=1);

use FpHic\Helpers;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/api/webhook.php';
require_once __DIR__ . '/../includes/api/polling.php';
require_once __DIR__ . '/../includes/input-validator.php';

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        private $data;
        private $status;

        public function __construct($data = null, $status = 200) {
            $this->data   = $data;
            $this->status = $status;
        }

        public function get_data() {
            return $this->data;
        }

        public function get_status() {
            return $this->status;
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

if (!class_exists('ReservationCodeStream')) {
    class ReservationCodeStream {
        public static $content = '';
        private $position = 0;

        public function stream_open($path, $mode, $options, &$opened_path) {
            $this->position = 0;
            return true;
        }

        public function stream_read($count) {
            $chunk = substr(self::$content, $this->position, $count);
            $this->position += strlen($chunk);
            return $chunk;
        }

        public function stream_eof() {
            return $this->position >= strlen(self::$content);
        }

        public function stream_stat() {
            return [];
        }
    }
}

final class ReservationCodeDeduplicationTest extends TestCase
{
    private string $logFile;
    private int $initialLogLength = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = Helpers\hic_get_log_file();
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        if (!file_exists($this->logFile)) {
            touch($this->logFile);
            $this->initialLogLength = 0;
        } else {
            $size = filesize($this->logFile);
            $this->initialLogLength = $size === false ? 0 : $size;
        }

        update_option('hic_connection_type', 'webhook');
        update_option('hic_webhook_token', 'secret-token');
        Helpers\hic_clear_option_cache('connection_type');
        Helpers\hic_clear_option_cache('webhook_token');

        delete_option('hic_synced_res_ids');
        Helpers\hic_clear_option_cache();
    }

    protected function tearDown(): void
    {
        delete_option('hic_synced_res_ids');
        Helpers\hic_clear_option_cache();

        parent::tearDown();
    }

    public function test_webhook_deduplicates_reservation_code(): void
    {
        $payload = [
            'reservation_code' => 'RCODE-WEB-1',
            'amount' => 210.50,
            'currency' => 'EUR',
            'checkin' => '2024-05-01',
            'checkout' => '2024-05-02',
        ];

        $request = new WP_REST_Request(
            ['token' => 'secret-token', 'email' => 'guest@example.com'],
            ['content-type' => 'application/json']
        );

        $firstResult = $this->dispatchWebhook($payload, $request);

        $this->assertIsArray($firstResult);
        $this->assertArrayHasKey('processed', $firstResult);
        $this->assertTrue($firstResult['processed']);
        $this->assertTrue(Helpers\hic_is_reservation_already_processed('RCODE-WEB-1'));

        $secondResult = $this->dispatchWebhook($payload, $request);

        $this->assertIsArray($secondResult);
        $this->assertFalse($secondResult['processed']);
        $this->assertSame('already_processed', $secondResult['reason']);

        $this->assertLogContains('Webhook skipped: reservation RCODE-WEB-1 already processed');
    }

    public function test_polling_deduplicates_reservation_code(): void
    {
        $reservation = [
            'reservation_code' => 'RCODE-POLL-1',
            'checkin' => '2024-06-10',
            'checkout' => '2024-06-12',
            'valid' => 1,
        ];

        $this->assertTrue(\FpHic\hic_should_process_reservation($reservation));

        Helpers\hic_mark_reservation_processed_by_id('RCODE-POLL-1');

        $this->assertFalse(\FpHic\hic_should_process_reservation($reservation));

        $this->assertLogContains('Reservation RCODE-POLL-1 already processed, skipping');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array|WP_Error|WP_REST_Response
     */
    private function dispatchWebhook(array $payload, WP_REST_Request $request)
    {
        $encoded = json_encode($payload);
        if ($encoded === false) {
            $this->fail('Unable to encode webhook payload to JSON');
        }

        ReservationCodeStream::$content = $encoded;

        if (!stream_wrapper_unregister('php')) {
            $this->fail('Unable to unregister php stream wrapper');
        }
        if (!stream_wrapper_register('php', ReservationCodeStream::class)) {
            $this->fail('Unable to register ReservationCodeStream wrapper');
        }

        try {
            return hic_webhook_handler($request);
        } finally {
            stream_wrapper_restore('php');
        }
    }

    private function assertLogContains(string $expected): void
    {
        $content = $this->consumeNewLogContent();
        $this->assertStringContainsString($expected, $content);
    }

    private function consumeNewLogContent(): string
    {
        if (!file_exists($this->logFile)) {
            return '';
        }

        $content = file_get_contents($this->logFile);
        if ($content === false) {
            return '';
        }

        $newContent = substr($content, $this->initialLogLength);
        $this->initialLogLength = strlen($content);

        return $newContent;
    }
}
