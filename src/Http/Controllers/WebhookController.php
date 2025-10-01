<?php declare(strict_types=1);

namespace FpHic\HicS2S\Http\Controllers;

use FpHic\HicS2S\Admin\SettingsPage;
use FpHic\HicS2S\Jobs\ConversionDispatchQueue;
use FpHic\HicS2S\Repository\BookingIntents;
use FpHic\HicS2S\Repository\Conversions;
use FpHic\HicS2S\Repository\Logs;
use FpHic\HicS2S\Services\Ga4Service;
use FpHic\HicS2S\Services\MetaCapiService;
use FpHic\HicS2S\Support\ServiceContainer;
use FpHic\HicS2S\Support\UserDataConsent;
use FpHic\HicS2S\ValueObjects\BookingPayload;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class WebhookController
{
    private const SIGNATURE_HEADER = 'X-HIC-Signature';
    private const TIMESTAMP_HEADER = 'X-HIC-Timestamp';
    private const SIGNATURE_CACHE_PREFIX = 'hic_s2s_sig_';
    private const SIGNATURE_CACHE_TTL = 300;

    private Conversions $conversions;

    private BookingIntents $bookingIntents;

    private Logs $logs;

    private Ga4Service $ga4Service;

    private MetaCapiService $metaService;

    public function __construct(
        ?Conversions $conversions = null,
        ?BookingIntents $bookingIntents = null,
        ?Logs $logs = null,
        ?Ga4Service $ga4Service = null,
        ?MetaCapiService $metaService = null
    ) {
        $container = ServiceContainer::instance();
        $this->conversions = $conversions ?? $container->conversions();
        $this->bookingIntents = $bookingIntents ?? $container->bookingIntents();
        $this->logs = $logs ?? $container->logs();
        $this->ga4Service = $ga4Service ?? $container->ga4Service();
        $this->metaService = $metaService ?? $container->metaService();
    }

    public function handleConversion(WP_REST_Request $request)
    {
        $settings = SettingsPage::getSettings();
        $configuredToken = sanitize_text_field($settings['token'] ?? '');
        $providedToken = sanitize_text_field((string) $request->get_param('token'));

        if ($configuredToken === '' || !hash_equals($configuredToken, $providedToken)) {
            return new WP_Error('hic_invalid_token', __('Token non valido', 'hotel-in-cloud'), ['status' => 401]);
        }

        if (\function_exists('hic_check_webhook_rate_limit')) {
            $rateLimit = \hic_check_webhook_rate_limit($request, $providedToken);

            if (is_wp_error($rateLimit)) {
                return $rateLimit;
            }
        }

        $webhookSecret = isset($settings['webhook_secret']) ? trim((string) $settings['webhook_secret']) : '';

        if ($webhookSecret !== '') {
            $signatureHeader = $request->get_header(self::SIGNATURE_HEADER);

            if (!is_string($signatureHeader) || trim($signatureHeader) === '') {
                $this->logs->log('webhook', 'warning', 'Webhook rifiutato: firma mancante', [
                    'booking_code' => $request->get_param('booking_code'),
                ]);

                return new WP_Error('hic_missing_signature', __('Firma webhook mancante', 'hotel-in-cloud'), ['status' => 401]);
            }

            $timestampHeader = $request->get_header(self::TIMESTAMP_HEADER);

            if (!is_string($timestampHeader) || trim($timestampHeader) === '') {
                $this->logs->log('webhook', 'warning', 'Webhook rifiutato: timestamp mancante', [
                    'booking_code' => $request->get_param('booking_code'),
                ]);

                return new WP_Error('hic_missing_timestamp', __('Timestamp del webhook mancante', 'hotel-in-cloud'), ['status' => 401]);
            }

            $timestampHeader = trim($timestampHeader);

            if (!ctype_digit($timestampHeader)) {
                $this->logs->log('webhook', 'warning', 'Webhook rifiutato: timestamp non numerico', [
                    'booking_code' => $request->get_param('booking_code'),
                    'timestamp' => $timestampHeader,
                ]);

                return new WP_Error('hic_invalid_timestamp', __('Timestamp del webhook non valido', 'hotel-in-cloud'), ['status' => 401]);
            }

            $timestamp = (int) $timestampHeader;
            $now = time();

            if (abs($now - $timestamp) > 300) {
                $this->logs->log('webhook', 'warning', 'Webhook rifiutato: timestamp fuori finestra', [
                    'booking_code' => $request->get_param('booking_code'),
                    'timestamp' => $timestamp,
                    'now' => $now,
                ]);

                return new WP_Error('hic_expired_timestamp', __('Timestamp del webhook fuori finestra temporale', 'hotel-in-cloud'), ['status' => 401]);
            }

            $rawBody = (string) $request->get_body();

            if (!$this->isValidSignature($rawBody, $signatureHeader, $webhookSecret, $timestamp)) {
                $this->logs->log('webhook', 'warning', 'Webhook rifiutato: firma non valida', [
                    'booking_code' => $request->get_param('booking_code'),
                    'timestamp' => $timestamp,
                ]);

                return new WP_Error('hic_invalid_signature', __('Firma webhook non valida', 'hotel-in-cloud'), ['status' => 401]);
            }

            $cacheKey = $this->buildSignatureCacheKey($timestamp, $rawBody);

            if ($this->hasRecentSignature($cacheKey)) {
                $this->logs->log('webhook', 'warning', 'Webhook rifiutato: firma riutilizzata', [
                    'booking_code' => $request->get_param('booking_code'),
                    'timestamp' => $timestamp,
                ]);

                return new WP_Error('hic_replay_signature', __('Firma webhook già utilizzata', 'hotel-in-cloud'), ['status' => 409]);
            }

            $this->rememberSignature($cacheKey);
        }

        try {
            $payload = BookingPayload::fromArray($this->extractPayload($request, $webhookSecret === '', ['token']));
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

        $sendUserData = UserDataConsent::shouldSend($payload);

        if (!$sendUserData) {
            $this->logs->log('webhook', 'info', 'User data esclusi per consenso non disponibile', [
                'booking_code' => $payload->getBookingCode(),
            ]);
        }

        $this->logs->log('webhook', 'info', 'Conversione accodata per dispatch asincrono', [
            'conversion_id' => $conversionId,
            'booking_code' => $payload->getBookingCode(),
            'send_user_data' => $sendUserData,
        ]);

        ConversionDispatchQueue::enqueue($conversionId);

        return new WP_REST_Response([
            'ok' => true,
            'queued' => true,
            'conversion_id' => $conversionId,
            'bucket' => $bucket,
            'ga4_sent' => false,
            'meta_sent' => false,
            'send_user_data' => $sendUserData,
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

        $queueMetrics = $this->conversions->queueMetrics();

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
            'queue' => $queueMetrics,
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
    private function extractPayload(WP_REST_Request $request, bool $allowQueryMerge = true, array $allowedQueryKeys = []): array
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
            if ($allowQueryMerge) {
                $data = array_merge($data, $query);
            } elseif ($allowedQueryKeys !== []) {
                foreach ($allowedQueryKeys as $allowedKey) {
                    if (array_key_exists($allowedKey, $query)) {
                        $data[$allowedKey] = $query[$allowedKey];
                    }
                }
            }
        }

        $forwardedFor = $request->get_header('X-Forwarded-For');
        if ((!isset($data['client_ip']) || !is_string($data['client_ip']) || trim($data['client_ip']) === '') && is_string($forwardedFor) && trim($forwardedFor) !== '') {
            $parts = array_filter(array_map('trim', explode(',', $forwardedFor)));
            if ($parts !== []) {
                $data['client_ip'] = reset($parts);
            }
        }

        $hasClientIp = isset($data['client_ip']) && is_string($data['client_ip']) && trim($data['client_ip']) !== '';

        if (!$hasClientIp && ($realIp = $request->get_header('X-Real-IP'))) {
            if (is_string($realIp) && trim($realIp) !== '') {
                $data['client_ip'] = trim($realIp);
            }
        }

        $hasClientIp = isset($data['client_ip']) && is_string($data['client_ip']) && trim($data['client_ip']) !== '';

        if (!$hasClientIp && isset($_SERVER['REMOTE_ADDR'])) {
            $remoteAddr = filter_var((string) $_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP);

            if ($remoteAddr !== false) {
                $data['client_ip'] = $remoteAddr;
            }
        }

        $forwardedUa = $request->get_header('X-Forwarded-User-Agent');
        if (is_string($forwardedUa) && trim($forwardedUa) !== '') {
            $data['client_user_agent'] = trim($forwardedUa);
        } else {
            $hicUa = $request->get_header('X-HIC-User-Agent');
            if (is_string($hicUa) && trim($hicUa) !== '' && (!isset($data['client_user_agent']) || !is_string($data['client_user_agent']) || trim($data['client_user_agent']) === '')) {
                $data['client_user_agent'] = trim($hicUa);
            }
        }

        if (!isset($data['client_user_agent']) || !is_string($data['client_user_agent']) || trim($data['client_user_agent']) === '') {
            $ua = $request->get_header('User-Agent');

            if (!is_string($ua) || trim($ua) === '') {
                $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
            }

            if (is_string($ua) && trim($ua) !== '') {
                $ua = trim($ua);
                if (function_exists('mb_substr')) {
                    $ua = mb_substr($ua, 0, 500);
                } else {
                    $ua = substr($ua, 0, 500);
                }

                $data['client_user_agent'] = $ua;
            }
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

    private function buildSignatureCacheKey(int $timestamp, string $payload): string
    {
        return self::SIGNATURE_CACHE_PREFIX . hash('sha256', sprintf('%d.%s', $timestamp, $payload));
    }

    private function hasRecentSignature(string $cacheKey): bool
    {
        if (function_exists('get_transient')) {
            $value = get_transient($cacheKey);

            if ($value !== false) {
                return true;
            }
        }

        if (function_exists('wp_cache_get')) {
            $value = wp_cache_get($cacheKey, 'hic_s2s_webhook');

            if ($value !== false) {
                return true;
            }
        }

        return false;
    }

    private function rememberSignature(string $cacheKey): void
    {
        if (function_exists('set_transient')) {
            set_transient($cacheKey, 1, self::SIGNATURE_CACHE_TTL);
        }

        if (function_exists('wp_cache_set')) {
            wp_cache_set($cacheKey, 1, 'hic_s2s_webhook', self::SIGNATURE_CACHE_TTL);
        }
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

    private function isValidSignature(string $payload, string $providedSignature, string $secret, int $timestamp): bool
    {
        if ($secret === '') {
            return true;
        }

        $signature = trim($providedSignature);

        if ($signature === '') {
            return false;
        }

        if (stripos($signature, 'sha256=') === 0) {
            $signature = substr($signature, 7);
        }

        $signature = trim($signature);

        if ($signature === '') {
            return false;
        }

        $canonicalPayload = sprintf('%d.%s', $timestamp, $payload);
        $expectedHex = hash_hmac('sha256', $canonicalPayload, $secret);
        $hexCandidate = strtolower($signature);

        if (strlen($hexCandidate) === strlen($expectedHex) && hash_equals($expectedHex, $hexCandidate)) {
            return true;
        }

        $binary = hex2bin($expectedHex);
        if ($binary !== false) {
            $expectedBase64 = base64_encode($binary);

            if (hash_equals($expectedBase64, $signature)) {
                return true;
            }
        }

        return false;
    }
}
