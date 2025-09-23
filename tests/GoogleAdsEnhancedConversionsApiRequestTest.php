<?php declare(strict_types=1);

namespace FpHic\GoogleAdsEnhanced {
    if (!function_exists(__NAMESPACE__ . '\\wp_remote_post')) {
        function wp_remote_post($url, $args = []) {
            global $hic_test_google_ads_requests, $hic_test_google_ads_response_code;

            if (!is_array($hic_test_google_ads_requests ?? null)) {
                $hic_test_google_ads_requests = [];
            }

            $hic_test_google_ads_requests[] = [
                'url' => $url,
                'args' => $args,
            ];

            if (strpos($url, 'oauth2.googleapis.com/token') !== false) {
                return [
                    'body' => \wp_json_encode(['access_token' => 'stub-access-token']),
                    'response' => ['code' => 200],
                ];
            }

            $status_code = $hic_test_google_ads_response_code ?? 200;

            return [
                'body' => \wp_json_encode(['uploadResults' => []]),
                'response' => ['code' => $status_code],
            ];
        }
    }
}

namespace {
    use FpHic\GoogleAdsEnhanced\GoogleAdsEnhancedConversions;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../includes/google-ads-enhanced.php';

    final class GoogleAdsEnhancedConversionsApiRequestTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            global $hic_test_options, $hic_test_option_autoload;
            global $hic_test_google_ads_requests, $hic_test_google_ads_response_code;

            $hic_test_options = [];
            $hic_test_option_autoload = [];
            $hic_test_google_ads_requests = [];
            $hic_test_google_ads_response_code = 200;
        }

        protected function tearDown(): void
        {
            global $hic_test_google_ads_response_code;
            $hic_test_google_ads_response_code = 200;

            parent::tearDown();
        }

        public function test_upload_uses_associative_headers_and_accepts_successful_response(): void
        {
            global $hic_test_google_ads_requests, $hic_test_google_ads_response_code;

            $hic_test_google_ads_response_code = 204;

            update_option('hic_google_ads_enhanced_enabled', true);
            update_option('hic_google_ads_enhanced_settings', [
                'customer_id' => '123-456-7890',
                'developer_token' => 'developer-token',
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
                'refresh_token' => 'refresh-token',
                'conversion_action_id' => '987654321',
            ]);
            update_option('timezone_string', 'UTC');

            $conversion = [
                'id' => 1,
                'gclid' => 'test-gclid',
                'conversion_action_id' => '987654321',
                'created_at' => '2024-01-10 12:00:00',
                'conversion_value' => 199.99,
                'conversion_currency' => 'EUR',
            ];

            $enhanced = new GoogleAdsEnhancedConversions();

            $method = new \ReflectionMethod($enhanced, 'upload_enhanced_conversions_to_google_ads');
            $method->setAccessible(true);
            $result = $method->invoke($enhanced, [$conversion]);

            $this->assertTrue($result['success']);
            $this->assertCount(2, $hic_test_google_ads_requests, 'Token and upload requests should be captured');

            $api_request = $hic_test_google_ads_requests[1];
            $this->assertSame(
                'https://googleads.googleapis.com/v14/customers/1234567890/conversionUploads:uploadClickConversions',
                $api_request['url']
            );

            $expected_headers = [
                'Authorization' => 'Bearer stub-access-token',
                'Content-Type' => 'application/json',
                'developer-token' => 'developer-token',
                'login-customer-id' => '1234567890',
            ];

            $this->assertSame($expected_headers, $api_request['args']['headers']);

            $payload = json_decode($api_request['args']['body'], true);
            $this->assertIsArray($payload);
            $this->assertArrayHasKey('conversions', $payload);
            $this->assertTrue($payload['partialFailureEnabled']);
            $this->assertCount(1, $payload['conversions']);

            $conversion_payload = $payload['conversions'][0];
            $this->assertSame('test-gclid', $conversion_payload['gclid']);
            $this->assertSame(
                'customers/1234567890/conversionActions/987654321',
                $conversion_payload['conversionAction']
            );
            $this->assertSame('EUR', $conversion_payload['currencyCode']);
            $this->assertEquals(199.99, $conversion_payload['conversionValue']);
            $this->assertMatchesRegularExpression(
                '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}[+-]\d{4}/',
                $conversion_payload['conversionDateTime']
            );
        }

        public function test_get_google_ads_access_token_aborts_when_credentials_missing(): void
        {
            global $hic_test_google_ads_requests;

            update_option('hic_google_ads_enhanced_settings', [
                'client_id' => '',
                'client_secret' => '',
                'refresh_token' => null,
            ]);

            $enhanced = new GoogleAdsEnhancedConversions();

            $method = new \ReflectionMethod($enhanced, 'get_google_ads_access_token');
            $method->setAccessible(true);

            $result = $method->invoke($enhanced);

            $this->assertFalse($result);
            $this->assertSame([], $hic_test_google_ads_requests, 'Token request should not be made when credentials are incomplete');
        }
    }
}

