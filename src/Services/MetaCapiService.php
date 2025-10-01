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

final class MetaCapiService
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
        $pixelId = trim((string) ($settings['meta_pixel_id'] ?? ''));
        $accessToken = trim((string) ($settings['meta_access_token'] ?? ''));

        if ($pixelId === '' || $accessToken === '') {
            $this->logs->log('meta', 'warning', 'Credenziali Meta CAPI mancanti', [
                'pixel_id' => $pixelId,
                'access_token' => $accessToken !== '' ? 'set' : 'missing',
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
            'https://graph.facebook.com/v17.0/%s/events?access_token=%s',
            rawurlencode($pixelId),
            rawurlencode($accessToken)
        );

        $userData = [];
        if ($includeUserData) {
            if ($payload->getGuestEmailHash() !== '') {
                $userData['em'] = $payload->getGuestEmailHash();
            }

            if ($payload->getGuestPhoneHash() !== '') {
                $userData['ph'] = $payload->getGuestPhoneHash();
            }

            $ipAddress = $payload->getClientIp();
            $userAgent = $payload->getClientUserAgent();

            if ($ipAddress !== null) {
                $userData['client_ip_address'] = $ipAddress;
            }

            if ($userAgent !== null) {
                $userData['client_user_agent'] = $userAgent;
            }
        }

        $eventSourceUrl = $payload->getEventSourceUrl();
        if ($eventSourceUrl === null) {
            $eventSourceUrl = home_url('/');
        }

        $eventTime = $payload->getEventTimestampSeconds();
        if ($eventTime === null) {
            $eventTime = time();
        }

        $eventId = $payload->getEventId();
        if ($eventId === null) {
            $eventId = $this->buildEventId($payload);
        }

        $event = [
            'event_name' => 'Purchase',
            'event_time' => $eventTime,
            'event_source_url' => $eventSourceUrl,
            'action_source' => 'website',
            'event_id' => $eventId,
            'custom_data' => [
                'currency' => $payload->getCurrency() ?: 'EUR',
                'value' => $payload->getAmount(),
                'booking_code' => $payload->getBookingCode(),
            ],
        ];

        if ($userData !== []) {
            $event['user_data'] = $userData;
        }

        /** @var array<string,mixed> $event */
        $event = apply_filters('hic_s2s_meta_event', $event, $payload);

        $body = [
            'data' => [$event],
        ];

        /** @var array<string,mixed> $body */
        $body = apply_filters('hic_s2s_meta_payload', $body, $payload);

        $encodedBody = wp_json_encode($body, JSON_UNESCAPED_UNICODE);

        if (!is_string($encodedBody)) {
            $this->logs->log('meta', 'error', 'Impossibile serializzare il payload Meta CAPI', [
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

        $loggedEndpoint = $this->redactEndpointSecret($endpoint, 'access_token');

        $logPayload = $this->sanitizePayloadForLog($body);

        $this->logs->log($result['success'] ? 'meta' : 'error', $result['success'] ? 'info' : 'error', 'Richiesta Meta CAPI eseguita', [
            'endpoint' => $loggedEndpoint,
            'code' => $result['code'],
            'attempts' => $result['attempts'],
            'response' => $responseBody,
            'payload' => $logPayload,
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

    private function buildEventId(BookingPayload $payload): string
    {
        $parts = [
            $payload->getBookingCode(),
            (string) $payload->getAmount(),
            (string) $payload->getCheckin(),
            (string) $payload->getCheckout(),
        ];

        return hash('sha256', implode('|', $parts));
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

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function sanitizePayloadForLog(array $payload): array
    {
        if (!isset($payload['data']) || !is_array($payload['data'])) {
            return $payload;
        }

        $sanitized = $payload;

        foreach ($sanitized['data'] as $index => $event) {
            if (!is_array($event)) {
                continue;
            }

            if (isset($event['user_data']) && is_array($event['user_data'])) {
                if (isset($event['user_data']['client_ip_address'])) {
                    $sanitized['data'][$index]['user_data']['client_ip_address'] = '[redacted]';
                }

                if (isset($event['user_data']['client_user_agent'])) {
                    $sanitized['data'][$index]['user_data']['client_user_agent'] = '[redacted]';
                }
            }
        }

        return $sanitized;
    }
}
