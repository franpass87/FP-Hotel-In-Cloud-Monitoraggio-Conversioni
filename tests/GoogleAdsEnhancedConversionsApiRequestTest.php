<?php declare(strict_types=1);

namespace FpHic\Helpers {
    if (!function_exists(__NAMESPACE__ . '\\hic_log')) {
        function hic_log($message, $level = 'info', $context = []) {
            global $hic_test_logged_messages;

            if (!is_array($hic_test_logged_messages ?? null)) {
                $hic_test_logged_messages = [];
            }

            $hic_test_logged_messages[] = $message;

            return true;
        }
    }
}

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
        private $previous_log_manager;

        protected function setUp(): void
        {
            parent::setUp();

            global $hic_test_options, $hic_test_option_autoload;
            global $hic_test_google_ads_requests, $hic_test_google_ads_response_code;
            global $hic_test_logged_messages;

            $hic_test_options = [];
            $hic_test_option_autoload = [];
            $hic_test_google_ads_requests = [];
            $hic_test_google_ads_response_code = 200;
            $hic_test_logged_messages = [];

            $this->previous_log_manager = $GLOBALS['hic_log_manager'] ?? null;
            $GLOBALS['hic_log_manager'] = new class {
                public function log($message, $level = 'info', $context = []) {
                    global $hic_test_logged_messages;

                    if (!is_array($hic_test_logged_messages ?? null)) {
                        $hic_test_logged_messages = [];
                    }

                    $hic_test_logged_messages[] = $message;

                    return true;
                }
            };
        }

        protected function tearDown(): void
        {
            global $hic_test_google_ads_response_code;
            $hic_test_google_ads_response_code = 200;

            if ($this->previous_log_manager !== null) {
                $GLOBALS['hic_log_manager'] = $this->previous_log_manager;
            } else {
                unset($GLOBALS['hic_log_manager']);
            }

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
            update_option('timezone_string', 'Europe/Rome');

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
                '/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}/',
                $conversion_payload['conversionDateTime']
            );
            $this->assertSame('2024-01-10 12:00:00+01:00', $conversion_payload['conversionDateTime']);
        }

        public function test_upload_preserves_non_eur_currency(): void
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
                'id' => 2,
                'gclid' => 'non-eur-gclid',
                'conversion_action_id' => '987654321',
                'created_at' => '2024-01-11 15:00:00',
                'conversion_value' => 250.75,
                'conversion_currency' => 'USD',
            ];

            $enhanced = new GoogleAdsEnhancedConversions();

            $method = new \ReflectionMethod($enhanced, 'upload_enhanced_conversions_to_google_ads');
            $method->setAccessible(true);
            $result = $method->invoke($enhanced, [$conversion]);

            $this->assertTrue($result['success']);
            $this->assertCount(2, $hic_test_google_ads_requests, 'Token and upload requests should be captured');

            $payload = json_decode($hic_test_google_ads_requests[1]['args']['body'], true);
            $this->assertIsArray($payload);
            $this->assertSame('USD', $payload['conversions'][0]['currencyCode']);
            $this->assertEquals(250.75, $payload['conversions'][0]['conversionValue']);
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

        public function test_conversion_action_id_configuration_and_queueing_behaviour(): void
        {
            global $wpdb;

            update_option('hic_google_ads_enhanced_enabled', true);

            $previous_wpdb = $wpdb ?? null;
            $wpdb = new class {
                public $prefix = 'wp_';
                public $insert_calls = 0;
                public $inserted_rows = [];
                public $insert_id = 0;

                public function insert($table, $data)
                {
                    $this->insert_calls++;
                    $this->inserted_rows[] = ['table' => $table, 'data' => $data];
                    $this->insert_id = 1000 + $this->insert_calls;

                    return true;
                }
            };

            try {
                $enhanced = new GoogleAdsEnhancedConversions();

                $get_conversion_action_id = new \ReflectionMethod($enhanced, 'get_conversion_action_id');
                $get_conversion_action_id->setAccessible(true);

                update_option('hic_google_ads_enhanced_settings', [
                    'conversion_action_id' => 'fallback-id',
                    'conversion_actions' => [
                        'booking_completed' => ['action_id' => 'specific-id'],
                    ],
                ]);

                $this->assertSame('specific-id', $get_conversion_action_id->invoke($enhanced, 'booking_completed'));

                update_option('hic_google_ads_enhanced_settings', [
                    'conversion_action_id' => 'fallback-id',
                    'conversion_actions' => [
                        'booking_completed' => ['action_id' => ''],
                    ],
                ]);

                $this->assertSame('fallback-id', $get_conversion_action_id->invoke($enhanced, 'booking_completed'));

                update_option('hic_google_ads_enhanced_settings', []);

                $this->assertNull($get_conversion_action_id->invoke($enhanced, 'booking_completed'));

                delete_option('hic_enhanced_conversions_queue');

                $enhanced->process_enhanced_conversion(
                    [
                        'booking_id' => 'skip-booking',
                        'gclid' => 'skip-gclid',
                        'total_amount' => 199.99,
                    ],
                    [
                        'email' => 'skip@example.com',
                    ]
                );

                $this->assertSame([], get_option('hic_enhanced_conversions_queue', []));
                $this->assertSame(0, $wpdb->insert_calls, 'Database insert should not be attempted without a conversion action ID.');

                update_option('hic_google_ads_enhanced_settings', [
                    'conversion_action_id' => 'fallback-id',
                ]);

                $enhanced->process_enhanced_conversion(
                    [
                        'booking_id' => 'valid-booking',
                        'gclid' => 'valid-gclid',
                        'total_amount' => 299.99,
                    ],
                    [
                        'email' => 'valid@example.com',
                    ]
                );

                $queue = get_option('hic_enhanced_conversions_queue', []);
                $this->assertSame([1001], $queue, 'Conversion should be queued once a valid action ID is configured.');

                $this->assertSame(1, $wpdb->insert_calls, 'Conversion record should be written when the action ID is configured.');
                $this->assertSame('fallback-id', $wpdb->inserted_rows[0]['data']['conversion_action_id']);
            } finally {
                if ($previous_wpdb !== null) {
                    $wpdb = $previous_wpdb;
                } else {
                    unset($GLOBALS['wpdb']);
                }
            }
        }

        public function test_format_conversions_for_api_skips_entries_without_conversion_action_id(): void
        {
            update_option('hic_google_ads_enhanced_enabled', true);
            update_option('hic_google_ads_enhanced_settings', [
                'customer_id' => '123-456-7890',
                'developer_token' => 'developer-token',
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
                'refresh_token' => 'refresh-token',
                'conversion_action_id' => 'fallback-id',
            ]);

            $enhanced = new GoogleAdsEnhancedConversions();

            $format_conversions = new \ReflectionMethod($enhanced, 'format_conversions_for_api');
            $format_conversions->setAccessible(true);

            $conversions = [
                [
                    'id' => 1,
                    'gclid' => 'retain-gclid',
                    'conversion_action_id' => '123456789',
                    'created_at' => '2024-01-10 12:00:00',
                    'conversion_value' => 150.0,
                    'conversion_currency' => 'EUR',
                ],
                [
                    'id' => 2,
                    'gclid' => 'missing-id',
                    'created_at' => '2024-01-10 12:00:00',
                    'conversion_value' => 180.0,
                    'conversion_currency' => 'EUR',
                ],
                [
                    'id' => 3,
                    'gclid' => 'empty-id',
                    'conversion_action_id' => '',
                    'created_at' => '2024-01-10 12:00:00',
                    'conversion_value' => 200.0,
                    'conversion_currency' => 'EUR',
                ],
            ];

            $formatted = $format_conversions->invoke($enhanced, $conversions);

            $this->assertCount(1, $formatted, 'Only conversions with a valid action ID should be formatted for upload.');
            $this->assertSame('retain-gclid', $formatted[0]['gclid']);
            $this->assertSame(
                'customers/1234567890/conversionActions/123456789',
                $formatted[0]['conversionAction']
            );
        }

        public function test_format_conversions_for_api_defaults_currency_when_missing(): void
        {
            global $hic_test_logged_messages;

            update_option('hic_google_ads_enhanced_enabled', true);
            update_option('hic_google_ads_enhanced_settings', [
                'customer_id' => '123-456-7890',
                'developer_token' => 'developer-token',
                'client_id' => 'client-id',
                'client_secret' => 'client-secret',
                'refresh_token' => 'refresh-token',
                'conversion_action_id' => 'fallback-id',
            ]);

            $enhanced = new GoogleAdsEnhancedConversions();

            $format_conversions = new \ReflectionMethod($enhanced, 'format_conversions_for_api');
            $format_conversions->setAccessible(true);

            $conversions = [
                [
                    'id' => 10,
                    'gclid' => 'missing-currency',
                    'conversion_action_id' => '123456789',
                    'created_at' => '2024-01-10 12:00:00',
                    'conversion_value' => 180.0,
                ],
            ];

            $formatted = $format_conversions->invoke($enhanced, $conversions);

            $this->assertCount(1, $formatted, 'Valid conversions should still be formatted.');
            $this->assertSame('EUR', $formatted[0]['currencyCode'], 'Missing currency should fall back to EUR.');

            $this->assertNotEmpty($hic_test_logged_messages, 'Fallback should trigger a log entry.');
            $last_message = end($hic_test_logged_messages);
            $this->assertIsString($last_message);
            $this->assertStringContainsString('Missing or invalid conversion currency', $last_message);
            $this->assertStringContainsString('defaulting to EUR', $last_message);
        }

        public function test_phone_hash_uses_fallback_country_for_italian_landline(): void
        {
            update_option('hic_google_ads_enhanced_settings', [
                'default_phone_country' => 'IT',
            ]);

            $enhanced = new GoogleAdsEnhancedConversions();

            $hash_method = new \ReflectionMethod($enhanced, 'hash_customer_data');
            $hash_method->setAccessible(true);

            $customer = [
                'phone' => '041 123 4567',
                'phone_language' => 'it',
            ];

            $booking = [
                'language' => 'it_IT',
            ];

            $hashed = $hash_method->invoke($enhanced, $customer, $booking);

            $this->assertArrayHasKey('phone_hash', $hashed);
            $this->assertSame(hash('sha256', '+390411234567'), $hashed['phone_hash']);
        }

        public function test_phone_hash_preserves_international_numbers(): void
        {
            update_option('hic_google_ads_enhanced_settings', []);

            $enhanced = new GoogleAdsEnhancedConversions();

            $hash_method = new \ReflectionMethod($enhanced, 'hash_customer_data');
            $hash_method->setAccessible(true);

            $customer = [
                'phone' => '+44 20 7946 0958',
            ];

            $hashed = $hash_method->invoke($enhanced, $customer, []);

            $this->assertArrayHasKey('phone_hash', $hashed);
            $this->assertSame(hash('sha256', '+442079460958'), $hashed['phone_hash']);
        }

        public function test_phone_hash_skips_when_prefix_unknown(): void
        {
            global $hic_test_logged_messages;

            update_option('hic_google_ads_enhanced_settings', [
                'default_phone_country' => '',
            ]);

            $enhanced = new GoogleAdsEnhancedConversions();

            $hash_method = new \ReflectionMethod($enhanced, 'hash_customer_data');
            $hash_method->setAccessible(true);

            $hashed = $hash_method->invoke($enhanced, [
                'phone' => '5551234',
            ], []);

            $this->assertArrayNotHasKey('phone_hash', $hashed, 'Phone hash should be skipped without a known prefix.');
            $this->assertNotEmpty($hic_test_logged_messages, 'Missing prefix should be logged.');
            $last_message = end($hic_test_logged_messages);
            $this->assertIsString($last_message);
            $this->assertStringContainsString('Unable to determine country prefix', $last_message);
        }
    }
}

