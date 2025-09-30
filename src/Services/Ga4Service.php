<?php declare(strict_types=1);

namespace FpHic\HicS2S\Services;

use FpHic\HicS2S\Admin\SettingsPage;
use FpHic\HicS2S\Repository\Logs;
use FpHic\HicS2S\Support\Http;
use FpHic\HicS2S\ValueObjects\BookingPayload;

if (!defined('ABSPATH')) {
    exit;
}

final class Ga4Service
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

        $body = [
            'client_id' => $this->determineClientId($payload),
            'timestamp_micros' => (int) round(microtime(true) * 1000000),
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

        $this->logs->log($result['success'] ? 'ga4' : 'error', $result['success'] ? 'info' : 'error', 'Richiesta GA4 eseguita', [
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

    private function determineClientId(BookingPayload $payload): string
    {
        $sid = $payload->getSid();

        if (is_string($sid) && $sid !== '') {
            return $sid;
        }

        $hash = hash('sha256', $payload->getBookingCode());

        $part1 = substr($hash, 0, 10);
        $part2 = substr($hash, 10, 10);

        return sprintf('%s.%s', $part1, $part2);
    }
}
