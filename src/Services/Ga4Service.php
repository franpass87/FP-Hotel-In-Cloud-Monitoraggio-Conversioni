<?php declare(strict_types=1);

namespace FpHic\HicS2S\Services;

use FpHic\HicS2S\Admin\SettingsPage;
use FpHic\HicS2S\Repository\Logs;
use FpHic\HicS2S\Support\Http;
use FpHic\HicS2S\Support\WpHttp;
use FpHic\HicS2S\ValueObjects\BookingPayload;

if (!defined('ABSPATH')) {
    exit;
}

final class Ga4Service
{
    private Logs $logs;

    public function __construct(?Logs $logs = null)
    {
        $this->logs = $logs ?? new Logs();
    }

    /**
     * @return array{sent:bool,code:int|null,body:string|null,attempts:int,reason?:string,retry_after?:int|null}
     */
    public function sendPurchase(BookingPayload $payload, bool $includeUserData = true): array
    {
        $settings = SettingsPage::getSettings();
        $measurementId = trim((string) ($settings['ga4_measurement_id'] ?? ''));
        $apiSecret = trim((string) ($settings['ga4_api_secret'] ?? ''));

        if ($measurementId === '' || $apiSecret === '') {
            $this->logs->log('ga4', 'warning', 'Credenziali GA4 mancanti', [
                'measurement_id' => $measurementId,
                'api_secret' => $apiSecret !== '' ? 'set' : 'missing',
            ]);

            return [
                'sent' => false,
                'code' => null,
                'body' => null,
                'attempts' => 0,
                'reason' => 'missing_credentials',
            ];
        }

        $endpoint = sprintf(
            'https://www.google-analytics.com/mp/collect?measurement_id=%s&api_secret=%s',
            rawurlencode($measurementId),
            rawurlencode($apiSecret)
        );

        $event = [
            'name' => 'purchase',
            'params' => [
                'currency' => $payload->getCurrency() ?: 'EUR',
                'value' => $payload->getAmount(),
                'transaction_id' => $payload->getBookingCode(),
                'items' => [
                    [
                        'item_id' => $payload->getBookingCode(),
                        'item_name' => 'Room Booking',
                        'quantity' => 1,
                        'price' => $payload->getAmount(),
                    ],
                ],
            ],
        ];

        $timestampMicros = $payload->getEventTimestampMicros();
        if ($timestampMicros === null) {
            $timestampMicros = (int) round(microtime(true) * 1_000_000);
        }

        $body = [
            'client_id' => $this->determineClientId($payload),
            'timestamp_micros' => $timestampMicros,
            'events' => [$event],
        ];

        if ($includeUserData) {
            $userData = [];

            if ($payload->getGuestEmailHash() !== '') {
                $userData['email'] = $payload->getGuestEmailHash();
            }

            if ($payload->getGuestPhoneHash() !== '') {
                $userData['phone_number'] = $payload->getGuestPhoneHash();
            }

            if ($userData !== []) {
                $body['user_data'] = $userData;
            }
        }

        /** @var array<string,mixed> $body */
        $body = apply_filters('hic_s2s_ga4_payload', $body, $payload);

        $encodedBody = wp_json_encode($body, JSON_UNESCAPED_UNICODE);

        if (!is_string($encodedBody)) {
            $this->logs->log('ga4', 'error', 'Impossibile serializzare il payload GA4', [
                'payload' => $body,
                'json_error' => function_exists('json_last_error_msg') ? json_last_error_msg() : null,
            ]);

            return [
                'sent' => false,
                'code' => null,
                'body' => null,
                'attempts' => 0,
                'reason' => 'json_encode_failed',
            ];
        }

        $result = Http::postWithRetry(static function () use ($endpoint, $encodedBody): array {
            return [
                'url' => $endpoint,
                'args' => [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'body' => $encodedBody,
                    'timeout' => 10,
                ],
            ];
        });

        $responseBody = null;
        if (is_array($result['response'])) {
            $responseBody = wp_remote_retrieve_body($result['response']);
        }

        $retryAfter = $this->extractRetryAfter($result['response'] ?? null, $result['code']);

        $errorCode = null;
        $errorMessage = null;
        if ($result['error'] instanceof \WP_Error) {
            $errorCode = $result['error']->get_error_code();
            $errorMessage = $result['error']->get_error_message();
        }

        $loggedEndpoint = $this->redactEndpointSecret($endpoint, 'api_secret');

        $this->logs->log($result['success'] ? 'ga4' : 'error', $result['success'] ? 'info' : 'error', 'Richiesta GA4 eseguita', [
            'endpoint' => $loggedEndpoint,
            'code' => $result['code'],
            'attempts' => $result['attempts'],
            'response' => $responseBody,
            'payload' => $body,
            'retry_after' => $retryAfter,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ]);

        $reason = $result['success'] ? 'ok' : $this->resolveFailureReason($result['code'], $result['error'] ?? null);

        return [
            'sent' => $result['success'],
            'code' => $result['code'],
            'body' => $responseBody,
            'attempts' => $result['attempts'],
            'reason' => $reason,
            'retry_after' => $retryAfter,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ];
    }

    private function determineClientId(BookingPayload $payload): string
    {
        $sid = $payload->getSid();

        if (is_string($sid) && $sid !== '') {
            return $sid;
        }

        $hash = hash('sha256', $payload->getBookingCode());

        $first = substr($hash, 0, 16);
        $second = substr($hash, 16, 16);

        $part1 = sprintf('%010u', self::unsignedCrc32($first));
        $part2 = sprintf('%010u', self::unsignedCrc32($second));

        return sprintf('%s.%s', $part1, $part2);
    }

    /**
     * @param int|null $code
     * @param mixed $error
     */
    private function resolveFailureReason($code, $error): string
    {
        if ($code === 429) {
            return 'rate_limited';
        }

        if (is_int($code)) {
            if ($code >= 500) {
                return 'http_5xx';
            }

            if ($code >= 400) {
                return 'http_4xx';
            }
        }

        if ($error instanceof \WP_Error) {
            return 'network_error';
        }

        return 'unknown_error';
    }

    /**
     * @param mixed $response
     */
    private function extractRetryAfter($response, ?int $code): ?int
    {
        if ($code !== 429 && ($code === null || $code < 500 || $code >= 600)) {
            return null;
        }

        $header = WpHttp::retrieveHeader($response, 'retry-after');

        if (!is_string($header) || trim($header) === '') {
            return null;
        }

        $header = trim($header);

        if (ctype_digit($header)) {
            $seconds = (int) $header;
            return $seconds > 0 ? $seconds : null;
        }

        $timestamp = strtotime($header);

        if ($timestamp === false) {
            return null;
        }

        $seconds = $timestamp - time();

        return $seconds > 0 ? $seconds : null;
    }

    private function redactEndpointSecret(string $endpoint, string $parameter): string
    {
        if (function_exists('remove_query_arg') && function_exists('add_query_arg')) {
            $stripped = remove_query_arg($parameter, $endpoint);

            return add_query_arg($parameter, '[redacted]', $stripped);
        }

        $pattern = sprintf('/(%s=)[^&]+/i', preg_quote($parameter, '/'));

        return preg_replace($pattern, '$1[redacted]', $endpoint) ?? $endpoint;
    }

    private static function unsignedCrc32(string $value): int
    {
        return (int) sprintf('%u', crc32($value));
    }
}
