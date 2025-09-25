<?php declare(strict_types=1);

namespace FpHic\Analytics;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persist booking analytics for the real-time dashboard.
 */
class BookingMetrics
{
    private static ?self $instance = null;

    /** @var bool */
    private $tableEnsured = false;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        if (function_exists('add_action')) {
            add_action('hic_process_booking', [$this, 'capture_booking_metrics'], 50, 2);
        }
    }

    /**
     * Persist aggregated metrics for the processed booking.
     *
     * @param array<string,mixed> $bookingPayload
     * @param array<string,mixed> $customerPayload
     */
    public function capture_booking_metrics(array $bookingPayload, array $customerPayload): void
    {
        $reservationId = $this->normalizeReservationId($bookingPayload);

        if ($reservationId === '') {
            return;
        }

        if (!$this->ensureTable()) {
            return;
        }

        $wpdb = \FpHic\Helpers\hic_get_wpdb_instance(['get_var', 'prepare', 'get_row', 'replace']);

        if (!$wpdb) {
            $this->log('wpdb not available while storing booking metrics', \HIC_LOG_LEVEL_WARNING);
            return;
        }

        $table = $wpdb->prefix . 'hic_booking_metrics';

        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE reservation_id = %s LIMIT 1", $reservationId),
            ARRAY_A
        );

        if ($wpdb->last_error) {
            $this->log('Failed to fetch existing booking metrics: ' . $wpdb->last_error, \HIC_LOG_LEVEL_ERROR);
            return;
        }

        $existingRow = is_array($existing) ? $existing : [];

        $sid = $this->mergeString($bookingPayload['sid'] ?? null, $existingRow['sid'] ?? null, 255);
        $utm = $sid !== null ? \FpHic\Helpers\hic_get_utm_params_by_sid($sid) : ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_content' => null, 'utm_term' => null];

        $channel = $this->determineChannel($bookingPayload, $utm, $existingRow);

        $currencySource = $bookingPayload['currency'] ?? ($existingRow['currency'] ?? null);
        $currency = \FpHic\Helpers\hic_normalize_currency_code($currencySource);

        $amount = $this->resolveAmount($bookingPayload, $existingRow);
        $isRefund = !empty($bookingPayload['is_refund']);

        if ($isRefund) {
            $amount = -abs((float) $amount);
        }

        $status = $this->mergeString(
            $bookingPayload['status'] ?? ($bookingPayload['raw_status'] ?? null),
            $existingRow['status'] ?? null,
            50
        );

        $data = [
            'reservation_id' => $reservationId,
            'sid'           => $sid,
            'channel'       => $channel,
            'utm_source'    => $this->mergeString($utm['utm_source'] ?? null, $existingRow['utm_source'] ?? null, 255),
            'utm_medium'    => $this->mergeString($utm['utm_medium'] ?? null, $existingRow['utm_medium'] ?? null, 255),
            'utm_campaign'  => $this->mergeString($utm['utm_campaign'] ?? null, $existingRow['utm_campaign'] ?? null, 255),
            'utm_content'   => $this->mergeString($utm['utm_content'] ?? null, $existingRow['utm_content'] ?? null, 255),
            'utm_term'      => $this->mergeString($utm['utm_term'] ?? null, $existingRow['utm_term'] ?? null, 255),
            'amount'        => round((float) $amount, 2),
            'currency'      => $currency,
            'is_refund'     => $isRefund ? 1 : 0,
            'status'        => $status,
            'created_at'    => $existingRow['created_at'] ?? current_time('mysql'),
            'updated_at'    => current_time('mysql'),
        ];

        $formats = ['%s','%s','%s','%s','%s','%s','%s','%s','%f','%s','%d','%s','%s','%s'];

        $result = $wpdb->replace($table, $data, $formats);

        if ($result === false || $wpdb->last_error) {
            $this->log('Failed to persist booking metrics: ' . ($wpdb->last_error ?: 'unknown error'), \HIC_LOG_LEVEL_ERROR);
        }
    }

    /**
     * Ensure the analytics table is available.
     */
    private function ensureTable(): bool
    {
        if ($this->tableEnsured) {
            return true;
        }

        if (!function_exists('\\hic_create_booking_metrics_table')) {
            return false;
        }

        if (!\hic_create_booking_metrics_table()) {
            return false;
        }

        $wpdb = \FpHic\Helpers\hic_get_wpdb_instance(['get_var', 'prepare']);
        if (!$wpdb) {
            return false;
        }

        $table = $wpdb->prefix . 'hic_booking_metrics';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;

        if ($exists) {
            $this->tableEnsured = true;
        }

        return $exists;
    }

    /**
     * Resolve the reservation identifier from the payload.
     *
     * @param array<string,mixed> $payload
     */
    private function normalizeReservationId(array $payload): string
    {
        $candidates = [
            $payload['reservation_id'] ?? null,
            $payload['booking_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            if (!is_scalar($candidate)) {
                continue;
            }

            $normalized = \FpHic\Helpers\hic_normalize_reservation_id((string) $candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    /**
     * Determine the marketing channel for the booking.
     *
     * @param array<string,mixed>      $bookingPayload
     * @param array<string,string|null> $utm
     * @param array<string,mixed>|null $existing
     */
    private function determineChannel(array $bookingPayload, array $utm, ?array $existing): string
    {
        $map = [
            'google'    => 'Google Ads',
            'facebook'  => 'Facebook Ads',
            'meta'      => 'Facebook Ads',
            'instagram' => 'Facebook Ads',
            'bing'      => 'Microsoft Ads',
            'microsoft' => 'Microsoft Ads',
            'tiktok'    => 'TikTok Ads',
        ];

        if (!empty($bookingPayload['gclid']) || !empty($bookingPayload['gbraid']) || !empty($bookingPayload['wbraid'])) {
            return 'Google Ads';
        }

        if (!empty($bookingPayload['fbclid'])) {
            return 'Facebook Ads';
        }

        if (!empty($bookingPayload['msclkid'])) {
            return 'Microsoft Ads';
        }

        if (!empty($bookingPayload['ttclid'])) {
            return 'TikTok Ads';
        }

        $source = $utm['utm_source'] ?? null;
        if (is_string($source) && $source !== '') {
            $normalized = strtolower($source);
            foreach ($map as $needle => $label) {
                if (strpos($normalized, $needle) !== false) {
                    return $label;
                }
            }

            return $this->formatChannelLabel($normalized);
        }

        if ($existing && !empty($existing['channel'])) {
            return (string) $existing['channel'];
        }

        return 'Direct';
    }

    private function formatChannelLabel(string $source): string
    {
        $label = preg_replace('/[^a-z0-9]+/i', ' ', $source);
        $label = trim((string) $label);

        if ($label === '') {
            return 'Direct';
        }

        return ucwords(strtolower($label));
    }

    /**
     * Merge a potential new value with an existing one enforcing max length.
     */
    private function mergeString($value, $existing, int $maxLength): ?string
    {
        if (is_scalar($value)) {
            $sanitized = sanitize_text_field((string) $value);
            if ($sanitized !== '') {
                return $this->truncate($sanitized, $maxLength);
            }
        }

        if (is_scalar($existing)) {
            $sanitizedExisting = sanitize_text_field((string) $existing);
            if ($sanitizedExisting !== '') {
                return $this->truncate($sanitizedExisting, $maxLength);
            }
        }

        return null;
    }

    private function truncate(string $value, int $maxLength): string
    {
        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength);
    }

    /**
     * Resolve booking amount, preserving previous values when missing.
     *
     * @param array<string,mixed>      $bookingPayload
     * @param array<string,mixed>|null $existing
     */
    private function resolveAmount(array $bookingPayload, ?array $existing): float
    {
        $candidates = [
            $bookingPayload['revenue'] ?? null,
            $bookingPayload['amount'] ?? null,
            $bookingPayload['total_amount'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            if (!is_scalar($candidate)) {
                continue;
            }

            return (float) \FpHic\Helpers\hic_normalize_price($candidate);
        }

        if ($existing && isset($existing['amount'])) {
            return (float) $existing['amount'];
        }

        return 0.0;
    }

    private function log(string $message, string $level = \HIC_LOG_LEVEL_DEBUG): void
    {
        if (function_exists('\\FpHic\\Helpers\\hic_log')) {
            \FpHic\Helpers\hic_log('[Booking Metrics] ' . $message, $level);
        }
    }
}

BookingMetrics::instance();
