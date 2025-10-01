<?php declare(strict_types=1);

namespace FpHic\HicS2S\Jobs;

use FpHic\HicS2S\Repository\Logs;
use FpHic\HicS2S\Support\ServiceContainer;
use FpHic\HicS2S\Support\UserDataConsent;
use FpHic\HicS2S\ValueObjects\BookingPayload;

if (!defined('ABSPATH')) {
    exit;
}

final class ConversionDispatchQueue
{
    private const HOOK = 'hic_s2s_dispatch_conversion';
    private const MAX_ATTEMPTS = 3;

    public static function bootstrap(): void
    {
        if (!\function_exists('add_action')) {
            return;
        }

        \add_action(self::HOOK, [self::class, 'dispatch'], 10, 2);
    }

    public static function enqueue(int $conversionId, int $attempt = 0): void
    {
        $conversionId = (int) $conversionId;
        if ($conversionId <= 0) {
            return;
        }

        if (!\function_exists('wp_schedule_single_event')) {
            ServiceContainer::instance()->logs()->log('queue', 'error', 'Impossibile accodare conversione: funzione wp_schedule_single_event assente', [
                'conversion_id' => $conversionId,
            ]);
            self::dispatchImmediately($conversionId, $attempt, 'missing_wp_schedule_single_event');
            return;
        }

        if ($attempt === 0 && self::hasScheduled($conversionId)) {
            return;
        }

        $scheduled = \wp_schedule_single_event(time(), self::HOOK, [$conversionId, $attempt]);

        if ($scheduled === false) {
            if (self::isEventAlreadyScheduled($conversionId, $attempt)) {
                ServiceContainer::instance()->logs()->log('queue', 'warning', 'Conversione già programmata, nessun dispatch immediato', [
                    'conversion_id' => $conversionId,
                    'attempt' => $attempt,
                ]);

                return;
            }

            ServiceContainer::instance()->logs()->log('queue', 'error', 'Impossibile accodare conversione: wp_schedule_single_event ha restituito false', [
                'conversion_id' => $conversionId,
                'attempt' => $attempt,
            ]);

            self::dispatchImmediately($conversionId, $attempt, 'schedule_failed');
        }
    }

    public static function dispatch(int $conversionId, int $attempt = 0): void
    {
        $container = ServiceContainer::instance();
        $logs = $container->logs();
        $conversions = $container->conversions();

        $conversion = $conversions->find($conversionId);

        if (!$conversion) {
            $logs->log('queue', 'warning', 'Conversione non trovata per dispatch', [
                'conversion_id' => $conversionId,
                'attempt' => $attempt,
            ]);
            return;
        }

        $raw = $conversion['raw_json'] ?? [];

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }

        if (!is_array($raw)) {
            $raw = [];
        }

        try {
            $payload = BookingPayload::fromArray($raw);
        } catch (\InvalidArgumentException $exception) {
            $logs->log('queue', 'warning', 'Payload raw non decodificabile, utilizzo campi tabella come fallback', [
                'conversion_id' => $conversionId,
                'attempt' => $attempt,
                'error' => $exception->getMessage(),
            ]);

            $fallback = self::buildFallbackPayload($conversion);

            if ($fallback === null) {
                self::logDefinitiveFailure($logs, $conversionId, $attempt, 'queue', 'invalid_payload', null);
                $conversions->updateFlags($conversionId, ['bucket' => 'invalid_payload']);

                return;
            }

            try {
                $payload = BookingPayload::fromArray($fallback);
            } catch (\InvalidArgumentException $innerException) {
                $logs->log('queue', 'error', 'Fallback payload non valido', [
                    'conversion_id' => $conversionId,
                    'attempt' => $attempt,
                    'error' => $innerException->getMessage(),
                ]);

                self::logDefinitiveFailure($logs, $conversionId, $attempt, 'queue', 'invalid_payload', null);
                $conversions->updateFlags($conversionId, ['bucket' => 'invalid_payload']);

                return;
            }
        }

        $sendUserData = UserDataConsent::shouldSend($payload);

        $ga4Sent = (bool) ($conversion['ga4_sent'] ?? false);
        $metaSent = (bool) ($conversion['meta_sent'] ?? false);

        $ga4Result = null;
        if (!$ga4Sent) {
            try {
                $ga4Result = $container->ga4Service()->sendPurchase($payload, $sendUserData);
            } catch (\Throwable $throwable) {
                $logs->log('queue', 'error', 'Eccezione durante l\'invio GA4', [
                    'conversion_id' => $conversionId,
                    'attempt' => $attempt,
                    'exception' => $throwable->getMessage(),
                ]);

                $ga4Result = [
                    'sent' => false,
                    'code' => null,
                    'body' => null,
                    'attempts' => 0,
                    'reason' => 'exception',
                    'error_message' => $throwable->getMessage(),
                ];
            }

            if (!empty($ga4Result['sent'])) {
                $updated = $conversions->markGa4Status($conversionId, true);

                if ($updated) {
                    $ga4Sent = true;
                } else {
                    $logs->log('queue', 'error', 'Impossibile aggiornare il flag GA4 dopo invio riuscito', [
                        'conversion_id' => $conversionId,
                        'attempt' => $attempt,
                    ]);

                    $ga4Result = array_merge($ga4Result, [
                        'sent' => false,
                        'reason' => 'persistence_failed',
                        'error_message' => $ga4Result['error_message'] ?? 'Failed to persist GA4 flag',
                    ]);
                }
            }
        }

        $metaResult = null;
        if (!$metaSent) {
            try {
                $metaResult = $container->metaService()->sendPurchase($payload, $sendUserData);
            } catch (\Throwable $throwable) {
                $logs->log('queue', 'error', 'Eccezione durante l\'invio Meta', [
                    'conversion_id' => $conversionId,
                    'attempt' => $attempt,
                    'exception' => $throwable->getMessage(),
                ]);

                $metaResult = [
                    'sent' => false,
                    'code' => null,
                    'body' => null,
                    'attempts' => 0,
                    'reason' => 'exception',
                    'error_message' => $throwable->getMessage(),
                ];
            }

            if (!empty($metaResult['sent'])) {
                $updated = $conversions->markMetaStatus($conversionId, true);

                if ($updated) {
                    $metaSent = true;
                } else {
                    $logs->log('queue', 'error', 'Impossibile aggiornare il flag Meta dopo invio riuscito', [
                        'conversion_id' => $conversionId,
                        'attempt' => $attempt,
                    ]);

                    $metaResult = array_merge($metaResult, [
                        'sent' => false,
                        'reason' => 'persistence_failed',
                        'error_message' => $metaResult['error_message'] ?? 'Failed to persist Meta flag',
                    ]);
                }
            }
        }

        $logs->log('queue', 'info', 'Conversione processata dalla coda', [
            'conversion_id' => $conversionId,
            'attempt' => $attempt,
            'ga4_sent' => $ga4Sent,
            'meta_sent' => $metaSent,
            'send_user_data' => $sendUserData,
            'ga4_response_code' => $ga4Result['code'] ?? null,
            'ga4_reason' => $ga4Result['reason'] ?? null,
            'ga4_retry_after' => $ga4Result['retry_after'] ?? null,
            'ga4_error_code' => $ga4Result['error_code'] ?? null,
            'ga4_error_message' => $ga4Result['error_message'] ?? null,
            'meta_response_code' => $metaResult['code'] ?? null,
            'meta_reason' => $metaResult['reason'] ?? null,
            'meta_retry_after' => $metaResult['retry_after'] ?? null,
            'meta_error_code' => $metaResult['error_code'] ?? null,
            'meta_error_message' => $metaResult['error_message'] ?? null,
        ]);

        $pending = [
            'ga4' => [
                'needs_retry' => !$ga4Sent,
                'retryable' => !$ga4Sent && self::isRetryableResult($ga4Result),
                'result' => $ga4Result,
            ],
            'meta' => [
                'needs_retry' => !$metaSent,
                'retryable' => !$metaSent && self::isRetryableResult($metaResult),
                'result' => $metaResult,
            ],
        ];

        foreach ($pending as $service => $info) {
            if (!$info['needs_retry']) {
                continue;
            }

            if (!$info['retryable']) {
                self::logDefinitiveFailure($logs, $conversionId, $attempt, $service, 'non_retryable', $info['result']);
            } elseif ($attempt >= self::MAX_ATTEMPTS - 1) {
                self::logDefinitiveFailure($logs, $conversionId, $attempt, $service, 'attempts_exhausted', $info['result']);
            }
        }

        $shouldRetry = array_filter($pending, static function (array $info) use ($attempt): bool {
            return $info['needs_retry'] && $info['retryable'] && $attempt < self::MAX_ATTEMPTS - 1;
        });

        if ($shouldRetry !== []) {
            self::scheduleRetry($conversionId, $attempt + 1, $ga4Result, $metaResult);
        }
    }

    private static function hasScheduled(int $conversionId): bool
    {
        if (!\function_exists('wp_get_scheduled_event') && !\function_exists('wp_next_scheduled')) {
            return false;
        }

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            if (\function_exists('wp_get_scheduled_event')) {
                $event = \wp_get_scheduled_event(self::HOOK, [$conversionId, $attempt]);
                if ($event !== false && $event !== null) {
                    return true;
                }
            } elseif (\function_exists('wp_next_scheduled')) {
                $timestamp = \wp_next_scheduled(self::HOOK, [$conversionId, $attempt]);
                if ($timestamp !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function scheduleRetry(int $conversionId, int $attempt, ?array $ga4Result, ?array $metaResult): void
    {
        if (!\function_exists('wp_schedule_single_event')) {
            ServiceContainer::instance()->logs()->log('queue', 'error', 'Impossibile programmare retry: funzione wp_schedule_single_event assente', [
                'conversion_id' => $conversionId,
                'attempt' => $attempt,
            ]);
            self::dispatchImmediately($conversionId, $attempt, 'missing_wp_schedule_single_event');
            return;
        }

        $delayStrategy = 'exponential_backoff';
        $retryAfters = [];

        foreach ([$ga4Result, $metaResult] as $result) {
            if (!is_array($result)) {
                continue;
            }

            $hint = isset($result['retry_after']) ? (int) $result['retry_after'] : null;

            if ($hint !== null && $hint > 0) {
                $retryAfters[] = $hint;
            }
        }

        if ($retryAfters !== []) {
            $delay = max($retryAfters);
            $delayStrategy = 'retry_after_header';
        } else {
            $delay = (int) max(60, pow(2, max(0, $attempt - 1)) * 60);
        }

        $scheduled = \wp_schedule_single_event(time() + $delay, self::HOOK, [$conversionId, $attempt]);

        if ($scheduled === false) {
            if (self::isEventAlreadyScheduled($conversionId, $attempt)) {
                ServiceContainer::instance()->logs()->log('queue', 'warning', 'Retry già presente in cron, nessun dispatch immediato', [
                    'conversion_id' => $conversionId,
                    'attempt' => $attempt,
                    'delay' => $delay,
                    'delay_strategy' => $delayStrategy,
                ]);

                return;
            }

            ServiceContainer::instance()->logs()->log('queue', 'error', 'Impossibile programmare retry: wp_schedule_single_event ha restituito false', [
                'conversion_id' => $conversionId,
                'attempt' => $attempt,
                'delay' => $delay,
                'delay_strategy' => $delayStrategy,
            ]);
            self::dispatchImmediately($conversionId, $attempt, 'schedule_failed');
            return;
        }

        ServiceContainer::instance()->logs()->log('queue', 'warning', 'Conversione reinserita in coda', [
            'conversion_id' => $conversionId,
            'attempt' => $attempt,
            'delay' => $delay,
            'delay_strategy' => $delayStrategy,
            'ga4_sent' => $ga4Result['sent'] ?? false,
            'ga4_reason' => $ga4Result['reason'] ?? null,
            'meta_sent' => $metaResult['sent'] ?? false,
            'meta_reason' => $metaResult['reason'] ?? null,
        ]);
    }

    private static function isEventAlreadyScheduled(int $conversionId, int $attempt): bool
    {
        if (\function_exists('wp_get_scheduled_event')) {
            $event = \wp_get_scheduled_event(self::HOOK, [$conversionId, $attempt]);

            if ($event !== false && $event !== null) {
                return true;
            }
        }

        if (\function_exists('wp_next_scheduled')) {
            $timestamp = \wp_next_scheduled(self::HOOK, [$conversionId, $attempt]);

            if ($timestamp !== false) {
                return true;
            }
        }

        return false;
    }

    private static function isRetryableResult(?array $result): bool
    {
        if ($result === null || !empty($result['sent'])) {
            return false;
        }

        $code = $result['code'] ?? null;
        $reason = is_string($result['reason'] ?? null) ? (string) $result['reason'] : '';

        if ($reason === 'missing_credentials') {
            return false;
        }

        if (is_int($code)) {
            if ($code === 429) {
                return true;
            }

            if ($code >= 400 && $code < 500) {
                return false;
            }

            if ($code >= 500) {
                return true;
            }
        }

        if (in_array($reason, ['http_4xx', 'missing_credentials', 'json_encode_failed', 'persistence_failed'], true)) {
            return false;
        }

        return true;
    }

    private static function logDefinitiveFailure(Logs $logs, int $conversionId, int $attempt, string $service, string $cause, ?array $result): void
    {
        $logs->log('queue', 'warning', 'Conversione marcata come fallita definitivamente', [
            'conversion_id' => $conversionId,
            'attempt' => $attempt,
            'service' => $service,
            'cause' => $cause,
            'code' => $result['code'] ?? null,
            'reason' => $result['reason'] ?? null,
        ]);
    }

    private static function dispatchImmediately(int $conversionId, int $attempt, string $cause): void
    {
        $logs = ServiceContainer::instance()->logs();

        $logs->log('queue', 'warning', 'Dispatch immediato conversione per fallback', [
            'conversion_id' => $conversionId,
            'attempt' => $attempt,
            'cause' => $cause,
        ]);

        try {
            self::dispatch($conversionId, $attempt);
        } catch (\Throwable $throwable) {
            $logs->log('queue', 'error', 'Errore durante dispatch immediato', [
                'conversion_id' => $conversionId,
                'attempt' => $attempt,
                'cause' => $cause,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $conversion
     * @return array<string,mixed>|null
     */
    private static function buildFallbackPayload(array $conversion): ?array
    {
        $bookingCode = isset($conversion['booking_code']) ? \sanitize_text_field((string) $conversion['booking_code']) : '';

        if ($bookingCode === '') {
            return null;
        }

        $status = isset($conversion['status']) ? \sanitize_text_field((string) $conversion['status']) : 'confirmed';
        $checkin = isset($conversion['checkin']) ? self::cleanDateValue($conversion['checkin']) : null;
        $checkout = isset($conversion['checkout']) ? self::cleanDateValue($conversion['checkout']) : null;
        $currency = isset($conversion['currency']) ? strtoupper(\sanitize_text_field((string) $conversion['currency'])) : '';
        $amount = isset($conversion['amount']) ? (float) $conversion['amount'] : 0.0;

        $raw = [
            'booking_code' => $bookingCode,
            'status' => $status,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'currency' => $currency,
            'amount' => $amount,
            'guest_email_hash' => isset($conversion['guest_email_hash']) ? (string) $conversion['guest_email_hash'] : '',
            'guest_phone_hash' => isset($conversion['guest_phone_hash']) ? (string) $conversion['guest_phone_hash'] : '',
            'bucket' => isset($conversion['bucket']) ? \sanitize_text_field((string) $conversion['bucket']) : 'unknown',
        ];

        if (!empty($conversion['booking_intent_id'])) {
            $raw['booking_intent_id'] = (string) $conversion['booking_intent_id'];
        }

        if (!empty($conversion['sid'])) {
            $raw['sid'] = (string) $conversion['sid'];
        }

        if (!empty($conversion['client_ip'])) {
            $raw['client_ip'] = (string) $conversion['client_ip'];
        }

        if (!empty($conversion['client_user_agent'])) {
            $raw['client_user_agent'] = (string) $conversion['client_user_agent'];
        }

        if (!empty($conversion['event_timestamp'])) {
            $raw['event_timestamp'] = (int) $conversion['event_timestamp'];
        }

        if (!empty($conversion['event_id'])) {
            $raw['event_id'] = (string) $conversion['event_id'];
        }

        return $raw;
    }

    /**
     * @param mixed $value
     */
    private static function cleanDateValue($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $candidate = trim($value);

        $date = \DateTime::createFromFormat('Y-m-d', $candidate);

        if (!$date) {
            return null;
        }

        $errors = \DateTime::getLastErrors();

        if ($errors['warning_count'] ?? 0) {
            return null;
        }

        if ($errors['error_count'] ?? 0) {
            return null;
        }

        return $date->format('Y-m-d');
    }
}
