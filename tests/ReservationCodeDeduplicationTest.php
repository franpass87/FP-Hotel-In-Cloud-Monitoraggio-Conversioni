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
        delete_option('hic_integration_retry_queue');
        Helpers\hic_clear_option_cache();
        Helpers\hic_clear_option_cache('hic_integration_retry_queue');

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

    public function test_webhook_deduplicates_mixed_case_reservation_ids(): void
    {
        $payload = [
            'reservation_code' => 'RCODE-MIXED-1',
            'amount' => 199.99,
            'currency' => 'EUR',
            'checkin' => '2024-11-01',
            'checkout' => '2024-11-03',
        ];

        $request = new WP_REST_Request(
            ['token' => 'secret-token', 'email' => 'mixed@example.com'],
            ['content-type' => 'application/json']
        );

        $firstResult = $this->dispatchWebhook($payload, $request);

        $this->assertIsArray($firstResult);
        $this->assertArrayHasKey('processed', $firstResult);
        $this->assertTrue($firstResult['processed']);

        $this->assertTrue(Helpers\hic_is_reservation_already_processed('RCODE-MIXED-1'));
        $this->assertTrue(Helpers\hic_is_reservation_already_processed('rcode-mixed-1'));

        $followUp = $payload;
        $followUp['reservation_code'] = 'rcode-mixed-1';

        $secondResult = $this->dispatchWebhook($followUp, $request);

        $this->assertIsArray($secondResult);
        $this->assertFalse($secondResult['processed']);
        $this->assertSame('already_processed', $secondResult['reason']);

        $this->assertLogContains('Webhook skipped: reservation RCODE-MIXED-1 already processed');
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

    public function test_polling_detects_alias_switch_without_duplicate_dispatch(): void
    {
        $initialReservation = [
            'reservation_code' => 'ALIAS-PRIMARY-1',
            'id' => 'ALIAS-SECONDARY-1',
            'checkin' => '2024-09-10',
            'checkout' => '2024-09-12',
            'valid' => 1,
        ];

        \FpHic\hic_mark_reservation_processed($initialReservation);

        $this->assertTrue(Helpers\hic_is_reservation_already_processed('ALIAS-PRIMARY-1'));
        $this->assertTrue(Helpers\hic_is_reservation_already_processed('ALIAS-SECONDARY-1'));

        $updatedReservation = [
            'id' => 'ALIAS-SECONDARY-1',
            'checkin' => '2024-09-10',
            'checkout' => '2024-09-12',
            'valid' => 1,
        ];

        $this->assertFalse(\FpHic\hic_should_process_reservation($updatedReservation));

        $this->assertLogContains('Reservation ALIAS-SECONDARY-1 already processed, skipping');
    }

    public function test_polling_skips_follow_up_update_when_alias_field_changes(): void
    {
        $initialReservation = [
            'reservation_code' => 'ALIAS-FOLLOW-1',
            'checkin' => '2024-10-15',
            'checkout' => '2024-10-17',
            'valid' => 1,
        ];

        \FpHic\hic_mark_reservation_processed($initialReservation);

        $this->assertTrue(Helpers\hic_is_reservation_already_processed('ALIAS-FOLLOW-1'));

        $updateReservation = [
            'code' => 'ALIAS-FOLLOW-1',
            'checkin' => '2024-10-15',
            'checkout' => '2024-10-17',
            'valid' => 1,
        ];

        $result = \FpHic\hic_process_reservations_batch([$updateReservation]);

        $this->assertIsArray($result);
        $this->assertSame(0, $result['new']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['errors']);

        $this->assertLogContains('Reservation ALIAS-FOLLOW-1 already processed, skipping');
    }

    public function test_polling_learns_new_aliases_from_duplicate_payload(): void
    {
        $initialReservation = [
            'reservation_code' => 'PRIMARY-1',
            'checkin' => '2024-12-01',
            'checkout' => '2024-12-03',
            'valid' => 1,
        ];

        \FpHic\hic_mark_reservation_processed($initialReservation);

        $this->assertTrue(Helpers\hic_is_reservation_already_processed('PRIMARY-1'));
        $this->assertFalse(Helpers\hic_is_reservation_already_processed('ALIAS-NEW'));

        $followUpReservation = [
            'reservation_code' => 'PRIMARY-1',
            'id' => 'ALIAS-NEW',
            'checkin' => '2024-12-01',
            'checkout' => '2024-12-03',
            'valid' => 1,
        ];

        $followUpResult = \FpHic\hic_process_reservations_batch([$followUpReservation]);

        $this->assertIsArray($followUpResult);
        $this->assertSame(0, $followUpResult['new']);
        $this->assertSame(1, $followUpResult['skipped']);
        $this->assertTrue(Helpers\hic_is_reservation_already_processed('ALIAS-NEW'));
        $this->assertLogContains('Reservation PRIMARY-1 already processed, skipping');

        $aliasOnlyReservation = [
            'reservation_code' => 'ALIAS-NEW',
            'checkin' => '2024-12-01',
            'checkout' => '2024-12-03',
            'valid' => 1,
        ];

        $aliasOnlyResult = \FpHic\hic_process_reservations_batch([$aliasOnlyReservation]);

        $this->assertIsArray($aliasOnlyResult);
        $this->assertSame(0, $aliasOnlyResult['new']);
        $this->assertSame(1, $aliasOnlyResult['skipped']);
        $this->assertLogContains('Reservation ALIAS-NEW already processed, skipping');
    }

    public function test_webhook_partial_success_marks_processed_and_queues_retry(): void
    {
        delete_option('hic_integration_retry_queue');
        Helpers\hic_clear_option_cache('hic_integration_retry_queue');

        update_option('hic_tracking_mode', 'hybrid');
        update_option('hic_measurement_id', 'G-PARTIAL');
        update_option('hic_api_secret', 'partial-secret');
        update_option('hic_fb_pixel_id', '1234567890');
        update_option('hic_fb_access_token', 'test-token');
        Helpers\hic_clear_option_cache('tracking_mode');
        Helpers\hic_clear_option_cache('measurement_id');
        Helpers\hic_clear_option_cache('api_secret');
        Helpers\hic_clear_option_cache('fb_pixel_id');
        Helpers\hic_clear_option_cache('fb_access_token');

        $http_interceptor = function ($preempt, $args, $url) {
            if (strpos($url, 'google-analytics.com/mp/collect') !== false) {
                return [
                    'headers' => [],
                    'body' => '',
                    'response' => ['code' => 204],
                ];
            }

            if (strpos($url, 'graph.facebook.com') !== false) {
                return new \WP_Error('simulated_failure', 'Simulated Facebook failure');
            }

            return $preempt;
        };

        add_filter('pre_http_request', $http_interceptor, 10, 3);

        $payload = [
            'reservation_code' => 'RCODE-PARTIAL-1',
            'amount' => 180.0,
            'currency' => 'EUR',
            'email' => 'partial@example.com',
            'checkin' => '2024-07-01',
            'checkout' => '2024-07-05',
        ];

        $request = new WP_REST_Request(
            ['token' => 'secret-token', 'email' => 'partial@example.com'],
            ['content-type' => 'application/json']
        );

        try {
            $firstResult = $this->dispatchWebhook($payload, $request);
        } finally {
            remove_filter('pre_http_request', $http_interceptor, 10);
        }

        $this->assertIsArray($firstResult);
        $this->assertTrue($firstResult['processed']);
        $this->assertArrayHasKey('result', $firstResult);
        $this->assertIsArray($firstResult['result']);
        $this->assertSame('partial', $firstResult['result']['status']);
        $this->assertContains('GA4', $firstResult['result']['successful_integrations']);
        $this->assertContains('Meta Pixel', $firstResult['result']['failed_integrations']);

        $this->assertTrue(Helpers\hic_is_reservation_already_processed('RCODE-PARTIAL-1'));

        $queue = get_option('hic_integration_retry_queue', []);
        $this->assertIsArray($queue);
        $this->assertArrayHasKey('RCODE-PARTIAL-1', $queue);
        $this->assertArrayHasKey('integrations', $queue['RCODE-PARTIAL-1']);
        $this->assertArrayHasKey('Meta Pixel', $queue['RCODE-PARTIAL-1']['integrations']);
        $this->assertArrayNotHasKey('GA4', $queue['RCODE-PARTIAL-1']['integrations']);

        $secondResult = $this->dispatchWebhook($payload, $request);
        $this->assertIsArray($secondResult);
        $this->assertFalse($secondResult['processed']);
        $this->assertSame('already_processed', $secondResult['reason']);

        delete_option('hic_measurement_id');
        delete_option('hic_api_secret');
        delete_option('hic_fb_pixel_id');
        delete_option('hic_fb_access_token');
        delete_option('hic_tracking_mode');
        Helpers\hic_clear_option_cache('measurement_id');
        Helpers\hic_clear_option_cache('api_secret');
        Helpers\hic_clear_option_cache('fb_pixel_id');
        Helpers\hic_clear_option_cache('fb_access_token');
        Helpers\hic_clear_option_cache('tracking_mode');
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
