<?php declare(strict_types=1);

namespace FpHic\HicS2S\Services;

use FpHic\HicS2S\Admin\SettingsPage;
use FpHic\HicS2S\Repository\Logs;
use FpHic\HicS2S\Support\Http;
use FpHic\HicS2S\ValueObjects\BookingPayload;

if (!defined('ABSPATH')) {
    exit;
}

final class MetaCapiService
{
    private Logs $logs;

    public function __construct()
    {
        $this->logs = new Logs();
    }

    /**
     * @return array{sent:bool,code:int|null,body:string|null,attempts:int,reason?:string}
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

            $ipAddress = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) $_SERVER['REMOTE_ADDR']) : '';
            $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field((string) $_SERVER['HTTP_USER_AGENT']) : '';

            if ($ipAddress !== '') {
                $userData['client_ip_address'] = $ipAddress;
            }

            if ($userAgent !== '') {
                $userData['client_user_agent'] = $userAgent;
            }
        }

        $event = [
            'event_name' => 'Purchase',
            'event_time' => time(),
            'event_source_url' => home_url('/'),
            'action_source' => 'website',
            'event_id' => $this->buildEventId($payload),
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

        $result = Http::postWithRetry(static function () use ($endpoint, $body): array {
            return [
                'url' => $endpoint,
                'args' => [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE),
                    'timeout' => 10,
                ],
            ];
        });

        $responseBody = null;
        if (is_array($result['response'])) {
            $responseBody = wp_remote_retrieve_body($result['response']);
        }

        $this->logs->log($result['success'] ? 'meta' : 'error', $result['success'] ? 'info' : 'error', 'Richiesta Meta CAPI eseguita', [
            'endpoint' => $endpoint,
            'code' => $result['code'],
            'attempts' => $result['attempts'],
            'response' => $responseBody,
            'payload' => $body,
        ]);

        return [
            'sent' => $result['success'],
            'code' => $result['code'],
            'body' => $responseBody,
            'attempts' => $result['attempts'],
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
}
