<?php declare(strict_types=1);

namespace FpHic\HicS2S\Http\Controllers;

use FpHic\HicS2S\Admin\SettingsPage;
use FpHic\HicS2S\Repository\BookingIntents;
use FpHic\HicS2S\Repository\Conversions;
use FpHic\HicS2S\Repository\Logs;
use FpHic\HicS2S\Services\Ga4Service;
use FpHic\HicS2S\Services\MetaCapiService;
use FpHic\HicS2S\ValueObjects\BookingPayload;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class WebhookController
{
    private Conversions $conversions;

    private BookingIntents $bookingIntents;

    private Logs $logs;

    private Ga4Service $ga4Service;

    private MetaCapiService $metaService;

    public function __construct()
    {
        $this->conversions = new Conversions();
        $this->bookingIntents = new BookingIntents();
        $this->logs = new Logs();
        $this->ga4Service = new Ga4Service();
        $this->metaService = new MetaCapiService();
    }

    public function handleConversion(WP_REST_Request $request)
    {
        $settings = SettingsPage::getSettings();
        $configuredToken = sanitize_text_field($settings['token'] ?? '');
        $providedToken = sanitize_text_field((string) $request->get_param('token'));

        if ($configuredToken === '' || !hash_equals($configuredToken, $providedToken)) {
            return new WP_Error('hic_invalid_token', __('Token non valido', 'hotel-in-cloud'), ['status' => 401]);
        }

        try {
            $payload = BookingPayload::fromArray($this->extractPayload($request));
        } catch (\InvalidArgumentException $exception) {
            $this->logs->log('webhook', 'error', 'Payload conversione non valido', [
                'error' => $exception->getMessage(),
            ]);

            return new WP_Error('hic_invalid_payload', __('Payload non valido', 'hotel-in-cloud'), ['status' => 400]);
        }

        $bucket = $this->resolveBucket($payload);

        $conversionId = $this->conversions->insert(array_merge(
            $payload->toDatabaseArray(),
            [
                'bucket' => $bucket,
                'ga4_sent' => 0,
                'meta_sent' => 0,
            ]
        ));

        if ($conversionId === null) {
            $this->logs->log('webhook', 'error', 'Impossibile salvare la conversione', [
                'booking_code' => $payload->getBookingCode(),
            ]);

            return new WP_Error('hic_persistence_error', __('Errore nel salvataggio della conversione', 'hotel-in-cloud'), ['status' => 500]);
        }

        $this->logs->log('webhook', 'info', 'Conversione salvata', [
            'conversion_id' => $conversionId,
            'booking_code' => $payload->getBookingCode(),
            'bucket' => $bucket,
        ]);

        $sendUserData = $this->shouldSendUserData($payload);

        if (!$sendUserData) {
            $this->logs->log('webhook', 'info', 'User data esclusi per consenso non disponibile', [
                'booking_code' => $payload->getBookingCode(),
            ]);
        }

        $ga4Result = $this->ga4Service->sendPurchase($payload, $sendUserData);
        $ga4Sent = (bool) ($ga4Result['sent'] ?? false);

        if ($ga4Sent) {
            $this->conversions->markGa4Status($conversionId, true);
        }

        $metaResult = $this->metaService->sendPurchase($payload, $sendUserData);
        $metaSent = (bool) ($metaResult['sent'] ?? false);

        if ($metaSent) {
            $this->conversions->markMetaStatus($conversionId, true);
        }

        return new WP_REST_Response([
            'ok' => true,
            'conversion_id' => $conversionId,
            'bucket' => $bucket,
            'ga4_sent' => $ga4Sent,
            'meta_sent' => $metaSent,
        ]);
    }

    public function health(WP_REST_Request $request)
    {
        $settings = SettingsPage::getSettings();

        $ga4Configured = ($settings['ga4_measurement_id'] ?? '') !== '' && ($settings['ga4_api_secret'] ?? '') !== '';
        $metaConfigured = ($settings['meta_pixel_id'] ?? '') !== '' && ($settings['meta_access_token'] ?? '') !== '';

        $ga4Reachable = $ga4Configured ? $this->pingUrl('https://www.google-analytics.com') : null;
        $metaReachable = $metaConfigured ? $this->pingUrl('https://graph.facebook.com') : null;

        $conversions = array_map(
            static function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'booking_code' => (string) ($row['booking_code'] ?? ''),
                    'status' => (string) ($row['status'] ?? ''),
                    'bucket' => (string) ($row['bucket'] ?? ''),
                    'ga4_sent' => (bool) ($row['ga4_sent'] ?? false),
                    'meta_sent' => (bool) ($row['meta_sent'] ?? false),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            },
            $this->conversions->latest(10)
        );

        return new WP_REST_Response([
            'ok' => true,
            'settings' => [
                'token_set' => ($settings['token'] ?? '') !== '',
                'ga4_configured' => $ga4Configured,
                'meta_configured' => $metaConfigured,
            ],
            'ga4' => [
                'configured' => $ga4Configured,
                'reachable' => $ga4Reachable,
            ],
            'meta' => [
                'configured' => $metaConfigured,
                'reachable' => $metaReachable,
            ],
            'conversions' => $conversions,
        ]);
    }

    public function healthPermissions(): bool
    {
        if (!\function_exists('current_user_can')) {
            return false;
        }

        return \current_user_can('hic_manage');
    }

    /**
     * @return array<string,mixed>
     */
    private function extractPayload(WP_REST_Request $request): array
    {
        $data = [];

        $json = $request->get_json_params();
        if (is_array($json)) {
            $data = array_merge($data, $json);
        }

        $body = $request->get_body_params();
        if (is_array($body)) {
            $data = array_merge($data, $body);
        }

        $query = $request->get_query_params();
        if (is_array($query)) {
            $data = array_merge($data, $query);
        }

        unset($data['token']);

        return $data;
    }

    private function resolveBucket(BookingPayload $payload): string
    {
        $bucket = $payload->getBucket();

        if ($bucket !== 'unknown') {
            return $bucket;
        }

        $intentId = $payload->getBookingIntentId();

        if ($intentId === null) {
            return $bucket;
        }

        $intent = $this->bookingIntents->findByIntentId($intentId);

        if (!$intent) {
            return $bucket;
        }

        $ids = [];
        if (!empty($intent['ids'])) {
            $decoded = json_decode((string) $intent['ids'], true);
            if (is_array($decoded)) {
                $ids = $decoded;
            }
        }

        if (!empty($ids['gclid']) || !empty($ids['gbraid']) || !empty($ids['wbraid'])) {
            return 'gads';
        }

        if (!empty($ids['fbclid'])) {
            return 'fbads';
        }

        return $bucket;
    }

    private function shouldSendUserData(BookingPayload $payload): bool
    {
        $raw = $payload->getRaw();
        $consentKeys = ['consent', 'marketing_consent', 'analytics_consent', 'privacy_consent', 'tcf_consent', 'cmode'];

        $consent = null;

        foreach ($consentKeys as $key) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }

            $value = $raw[$key];

            if (is_string($value)) {
                $value = strtolower(trim($value));
            }

            if ($value === true || $value === 'granted' || $value === 'yes' || $value === 'true' || $value === '1' || $value === 1) {
                $consent = true;
                // Continue checking in case a more specific key (e.g. analytics_consent) denies consent.
                continue;
            }

            if ($value === false || $value === 'denied' || $value === 'no' || $value === 'false' || $value === '0' || $value === 0) {
                $consent = false;
                break;
            }

            if ($value !== null && $value !== '') {
                // An explicit value that we do not recognise is treated as a denial to stay on the safe side.
                $consent = false;
                break;
            }
        }

        $filtered = apply_filters('hic_s2s_user_data_consent', $consent, $raw, $payload);

        if ($filtered !== null) {
            return (bool) $filtered;
        }

        if ($consent !== null) {
            return (bool) $consent;
        }

        return true;
    }

    private function pingUrl(string $url): ?bool
    {
        $response = wp_remote_head($url, ['timeout' => 5]);

        if (is_wp_error($response)) {
            $response = wp_remote_get($url, ['timeout' => 5]);
        }

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code === 0) {
            return null;
        }

        if ($code >= 200 && $code < 400) {
            return true;
        }

        if (in_array($code, [401, 403, 405], true)) {
            return true;
        }

        return false;
    }
}
