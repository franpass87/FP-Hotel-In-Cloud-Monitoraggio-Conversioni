<?php

use FpHic\HicS2S\Admin\SettingsPage;
use FpHic\HicS2S\Http\Controllers\WebhookController;
use FpHic\HicS2S\Repository\BookingIntents;
use FpHic\HicS2S\Repository\Conversions;
use FpHic\HicS2S\Repository\Logs;
use FpHic\HicS2S\Services\Ga4Service;
use FpHic\HicS2S\Services\MetaCapiService;
use FpHic\HicS2S\Support\Hasher;
use FpHic\HicS2S\Support\Http;
use FpHic\HicS2S\Support\ServiceContainer;
use FpHic\HicS2S\Support\UserDataConsent;
use FpHic\HicS2S\ValueObjects\BookingPayload;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Support/Hasher.php';
require_once __DIR__ . '/../src/Support/Http.php';
require_once __DIR__ . '/../src/Support/ServiceContainer.php';
require_once __DIR__ . '/../src/Support/UserDataConsent.php';
require_once __DIR__ . '/../src/Repository/Logs.php';
require_once __DIR__ . '/../src/Repository/Conversions.php';
require_once __DIR__ . '/../src/Repository/BookingIntents.php';
require_once __DIR__ . '/../src/Services/Ga4Service.php';
require_once __DIR__ . '/../src/Services/MetaCapiService.php';
require_once __DIR__ . '/../src/ValueObjects/BookingPayload.php';
require_once __DIR__ . '/../src/Jobs/ConversionDispatchQueue.php';
require_once __DIR__ . '/../src/Admin/SettingsPage.php';
require_once __DIR__ . '/../src/Http/Controllers/WebhookController.php';

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private array $params = [];
        private array $headers = [];
        private string $body = '';

        public function set_param(string $key, $value): void
        {
            $this->params[$key] = $value;
        }

        public function get_param(string $key)
        {
            return $this->params[$key] ?? null;
        }

        public function set_header(string $key, string $value): void
        {
            $this->headers[strtolower($key)] = $value;
        }

        public function get_header(string $key): string
        {
            $key = strtolower($key);
            return $this->headers[$key] ?? '';
        }

        public function set_body(string $body): void
        {
            $this->body = $body;
        }

        public function get_body(): string
        {
            return $this->body;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        private $data;

        public function __construct($data)
        {
            $this->data = $data;
        }

        public function get_data()
        {
            return $this->data;
        }
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private string $code;

        public function __construct(string $code)
        {
            $this->code = $code;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }
    }
}

final class FakeWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public string $last_error = '';
    public array $insertedRows = [];

    public function get_charset_collate(): string
    {
        return 'utf8mb4_unicode_ci';
    }

    public function insert(string $table, array $data, array $format)
    {
        $this->insert_id++;
        $this->insertedRows[] = $data;

        return 1;
    }

    public function prepare(string $query, ...$args)
    {
        foreach ($args as &$arg) {
            if (is_string($arg)) {
                $arg = addslashes($arg);
            }
        }

        return vsprintf($query, $args);
    }

    public function query($query)
    {
        return 0;
    }

    public function get_var($query)
    {
        return 0;
    }

    public function get_row($query, $output = ARRAY_A)
    {
        return null;
    }

    public function get_results($query, $output = ARRAY_A)
    {
        return [];
    }
}

final class WebhookAntiReplayTest extends TestCase
{
    private FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $this->wpdb;
        ServiceContainer::flush();
        SettingsPage::clearCache();
        update_option('hic_s2s_settings', [
            'token' => 'test-token',
            'webhook_secret' => 'secret-key',
        ]);
        SettingsPage::clearCache();
    }

    protected function tearDown(): void
    {
        ServiceContainer::flush();
        SettingsPage::clearCache();
        delete_option('hic_s2s_settings');
        unset($GLOBALS['wpdb']);
        parent::tearDown();
    }

    public function testRejectsReplayWithinWindow(): void
    {
        $controller = new WebhookController(
            new Conversions(new Logs()),
            new BookingIntents(new Logs()),
            new Logs(),
            new Ga4Service(new Logs()),
            new MetaCapiService(new Logs())
        );

        $body = wp_json_encode([
            'booking_code' => 'ABC123',
            'status' => 'confirmed',
            'currency' => 'EUR',
            'amount' => 120,
        ]);

        $this->assertIsString($body);

        $timestamp = time();
        $signature = hash_hmac('sha256', sprintf('%d.%s', $timestamp, $body), 'secret-key');

        $request = new WP_REST_Request();
        $request->set_param('token', 'test-token');
        $request->set_body($body);
        $request->set_header('X-HIC-Timestamp', (string) $timestamp);
        $request->set_header('X-HIC-Signature', 'sha256=' . $signature);

        $first = $controller->handleConversion($request);
        $this->assertInstanceOf(WP_REST_Response::class, $first);

        $second = $controller->handleConversion($request);
        $this->assertInstanceOf(WP_Error::class, $second);
        $this->assertSame('hic_replay_signature', $second->get_error_code());
    }
}
