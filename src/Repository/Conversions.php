<?php declare(strict_types=1);

namespace FpHic\HicS2S\Repository;

if (!defined('ABSPATH')) {
    exit;
}

use FpHic\HicS2S\ValueObjects\BookingPayload;
use wpdb;

final class Conversions
{
    private const TABLE = 'hic_conversions';

    private Logs $logs;

    public function __construct(?Logs $logs = null)
    {
        $this->logs = $logs ?? new Logs();
    }

    public function maybeMigrate(): void
    {
        $wpdb = $this->getWpdb();

        if (!$wpdb) {
            return;
        }

        $tableName = $this->getTableName();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$tableName} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            booking_code VARCHAR(191) NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'confirmed',
            checkin DATE NULL,
            checkout DATE NULL,
            currency CHAR(3) NOT NULL DEFAULT '',
            amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            guest_email_hash CHAR(64) NOT NULL DEFAULT '',
            guest_phone_hash CHAR(64) NOT NULL DEFAULT '',
            booking_intent_id VARCHAR(191) NULL,
            sid VARCHAR(191) NOT NULL DEFAULT '',
            client_ip VARCHAR(45) NOT NULL DEFAULT '',
            client_user_agent VARCHAR(512) NOT NULL DEFAULT '',
            event_timestamp BIGINT UNSIGNED NULL DEFAULT NULL,
            event_id VARCHAR(191) NULL,
            bucket VARCHAR(50) NOT NULL DEFAULT 'unknown',
            ga4_sent TINYINT(1) NOT NULL DEFAULT 0,
            meta_sent TINYINT(1) NOT NULL DEFAULT 0,
            raw_json LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY booking_code (booking_code),
            KEY booking_intent_id (booking_intent_id),
            KEY sid (sid),
            KEY event_timestamp (event_timestamp),
            KEY event_id (event_id),
            KEY bucket (bucket),
            KEY created_at (created_at)
        ) {$charset};";

        $this->runDbDelta($sql);
        $this->backfillMetadata($wpdb, $tableName);
    }

    public function insert(array $data): ?int
    {
        $wpdb = $this->getWpdb();

        if (!$wpdb) {
            return null;
        }

        $defaults = [
            'booking_code' => '',
            'status' => 'confirmed',
            'checkin' => null,
            'checkout' => null,
            'currency' => '',
            'amount' => 0.0,
            'guest_email_hash' => '',
            'guest_phone_hash' => '',
            'booking_intent_id' => null,
            'sid' => '',
            'client_ip' => '',
            'client_user_agent' => '',
            'event_timestamp' => null,
            'event_id' => null,
            'bucket' => 'unknown',
            'ga4_sent' => 0,
            'meta_sent' => 0,
            'raw_json' => null,
        ];

        $payload = wp_parse_args($data, $defaults);

        $bookingCode = sanitize_text_field((string) $payload['booking_code']);
        $rawJson = null;

        if ($payload['raw_json'] !== null) {
            $encoded = wp_json_encode($payload['raw_json'], JSON_UNESCAPED_UNICODE);

            if (is_string($encoded)) {
                $rawJson = $encoded;
            } else {
                $this->logs->log('webhook', 'error', 'Impossibile serializzare il payload raw della conversione', [
                    'booking_code' => $bookingCode,
                    'raw_json_preview' => $this->summarizeForLog($payload['raw_json']),
                ]);

                $rawJson = null;
            }
        }

        $inserted = $wpdb->insert(
            $this->getTableName(),
            [
                'booking_code' => $bookingCode,
                'status' => sanitize_text_field((string) $payload['status']),
                'checkin' => $this->sanitizeDate($payload['checkin']),
                'checkout' => $this->sanitizeDate($payload['checkout']),
                'currency' => strtoupper(sanitize_text_field((string) $payload['currency'])),
                'amount' => (float) $payload['amount'],
                'guest_email_hash' => sanitize_text_field((string) $payload['guest_email_hash']),
                'guest_phone_hash' => sanitize_text_field((string) $payload['guest_phone_hash']),
                'booking_intent_id' => $this->sanitizeNullableString($payload['booking_intent_id']),
                'sid' => sanitize_text_field((string) ($payload['sid'] ?? '')),
                'client_ip' => $this->sanitizeIp($payload['client_ip'] ?? ''),
                'client_user_agent' => $this->sanitizeUserAgent($payload['client_user_agent'] ?? ''),
                'event_timestamp' => isset($payload['event_timestamp']) && $payload['event_timestamp'] !== null ? (int) $payload['event_timestamp'] : null,
                'event_id' => $this->sanitizeNullableString($payload['event_id']),
                'bucket' => sanitize_text_field((string) $payload['bucket']),
                'ga4_sent' => (int) $payload['ga4_sent'],
                'meta_sent' => (int) $payload['meta_sent'],
                'raw_json' => $rawJson,
            ],
            [
                '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s',
            ]
        );

        if ($inserted === false) {
            $error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
            $this->logs->log('webhook', 'error', 'Inserimento conversione fallito', [
                'booking_code' => $bookingCode,
                'db_error' => $error !== '' ? $error : null,
            ]);

            return null;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * @return array{pending_ga4:int,pending_meta:int,failures:int}
     */
    public function queueMetrics(): array
    {
        $defaults = [
            'pending_ga4' => 0,
            'pending_meta' => 0,
            'failures' => 0,
        ];

        $wpdb = $this->getWpdb();

        if (!$wpdb) {
            return $defaults;
        }

        $table = $this->getTableName();

        $pendingGa4 = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE ga4_sent = 0");
        $pendingMeta = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE meta_sent = 0");
        $failures = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE ga4_sent = 0 AND meta_sent = 0 AND raw_json IS NULL");

        return [
            'pending_ga4' => (int) $pendingGa4,
            'pending_meta' => (int) $pendingMeta,
            'failures' => (int) $failures,
        ];
    }

    public function pruneDeliveredOlderThan(int $days): int
    {
        $wpdb = $this->getWpdb();

        if (!$wpdb) {
            return 0;
        }

        $days = max(1, $days);
        $dayInSeconds = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * $dayInSeconds));
        $table = $this->getTableName();

        $query = $wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s AND ga4_sent = 1 AND meta_sent = 1",
            $cutoff
        );

        $deleted = $wpdb->query($query);

        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * @param mixed $value
     */
    private function summarizeForLog($value): string
    {
        if (is_array($value)) {
            $preview = [];
            $count = 0;
            foreach ($value as $key => $item) {
                $count++;
                if ($count > 5) {
                    $preview[] = '…';
                    break;
                }

                $preview[] = sprintf('%s=%s', (string) $key, $this->scalarPreview($item));
            }

            $summary = implode(', ', $preview);
        } elseif (is_object($value)) {
            $summary = 'object(' . get_class($value) . ')';
        } else {
            $summary = (string) $value;
        }

        $summary = $this->stripNonPrintable($summary);

        if (function_exists('mb_substr')) {
            $summary = mb_substr($summary, 0, 200);
        } else {
            $summary = substr($summary, 0, 200);
        }

        return $summary;
    }

    /**
     * @param mixed $value
     */
    private function scalarPreview($value): string
    {
        if (is_string($value)) {
            return $this->stripNonPrintable($value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        if (is_array($value)) {
            return 'array(' . count($value) . ')';
        }

        if (is_object($value)) {
            return 'object(' . get_class($value) . ')';
        }

        return gettype($value);
    }

    private function stripNonPrintable(string $value): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
    }

    public function markGa4Status(int $conversionId, bool $sent): bool
    {
        return $this->updateFlags($conversionId, ['ga4_sent' => $sent ? 1 : 0]);
    }

    public function markMetaStatus(int $conversionId, bool $sent): bool
    {
        return $this->updateFlags($conversionId, ['meta_sent' => $sent ? 1 : 0]);
    }

    public function updateFlags(int $conversionId, array $flags): bool
    {
        $wpdb = $this->getWpdb();

        if (!$wpdb || $conversionId <= 0) {
            return false;
        }

        $allowed = ['ga4_sent', 'meta_sent', 'bucket'];
        $data = [];

        foreach ($flags as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }

            if ($key === 'bucket') {
                $data['bucket'] = sanitize_text_field((string) $value);
                continue;
            }

            $data[$key] = $value ? 1 : 0;
        }

        if ($data === []) {
            return false;
        }

        $updated = $wpdb->update(
            $this->getTableName(),
            $data,
            ['id' => $conversionId],
            null,
            ['%d']
        );

        return $updated !== false;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function latest(int $limit = 10): array
    {
        $wpdb = $this->getWpdb();

        if (!$wpdb) {
            return [];
        }

        $limit = max(1, min(100, $limit));

        $table = $this->getTableName();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", $limit);

        $results = $wpdb->get_results($sql, ARRAY_A);

        return is_array($results) ? $results : [];
    }

    public function findByBookingCode(string $code): ?array
    {
        $wpdb = $this->getWpdb();

        if (!$wpdb) {
            return null;
        }

        $table = $this->getTableName();
        $query = $wpdb->prepare("SELECT * FROM {$table} WHERE booking_code = %s ORDER BY id DESC LIMIT 1", $code);
        $row = $wpdb->get_row($query, ARRAY_A);

        if (!is_array($row)) {
            return null;
        }

        return $row;
    }

    public function find(int $id): ?array
    {
        $wpdb = $this->getWpdb();

        if (!$wpdb || $id <= 0) {
            return null;
        }

        $table = $this->getTableName();
        $query = $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id);
        $row = $wpdb->get_row($query, ARRAY_A);

        if (!is_array($row)) {
            return null;
        }

        if (isset($row['raw_json']) && is_string($row['raw_json']) && $row['raw_json'] !== '') {
            $decoded = json_decode($row['raw_json'], true);
            if (is_array($decoded)) {
                $row['raw_json'] = $decoded;
            }
        }

        return $row;
    }

    private function getTableName(): string
    {
        $wpdb = $this->getWpdb();

        if (!$wpdb) {
            return self::TABLE;
        }

        return $wpdb->prefix . self::TABLE;
    }

    private function runDbDelta(string $sql): void
    {
        if (!defined('ABSPATH')) {
            return;
        }

        $upgradeFile = ABSPATH . 'wp-admin/includes/upgrade.php';

        if (!function_exists('dbDelta')) {
            if (is_readable($upgradeFile)) {
                require_once $upgradeFile;
            }
        }

        if (function_exists('dbDelta')) {
            dbDelta($sql);
        }
    }

    private function backfillMetadata(wpdb $wpdb, string $tableName): void
    {
        $limit = 50;

        do {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $query = $wpdb->prepare(
                "SELECT id, raw_json, booking_intent_id, sid, client_ip, client_user_agent, event_timestamp, event_id FROM {$tableName}"
                . " WHERE ( (sid = '' OR sid IS NULL)"
                . " OR (client_ip = '' OR client_ip IS NULL)"
                . " OR (client_user_agent = '' OR client_user_agent IS NULL)"
                . " OR (event_timestamp IS NULL OR event_timestamp = 0)"
                . " OR (booking_intent_id IS NULL OR booking_intent_id = '')"
                . " OR (event_id IS NULL OR event_id = '') )"
                . " AND raw_json IS NOT NULL AND raw_json != ''"
                . " LIMIT %d",
                $limit
            );

            $rows = $wpdb->get_results($query, ARRAY_A);

            if (!is_array($rows) || $rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $rawJson = $row['raw_json'] ?? '';
                if (!is_string($rawJson) || $rawJson === '') {
                    continue;
                }

                $decoded = json_decode($rawJson, true);

                if (!is_array($decoded)) {
                    continue;
                }

                try {
                    $payload = BookingPayload::fromArray($decoded);
                } catch (\Throwable $throwable) {
                    continue;
                }

                $updates = [];
                $formats = [];

                $sid = $payload->getSid();
                if (($row['sid'] ?? '') === '' && $sid !== null && $sid !== '') {
                    $updates['sid'] = sanitize_text_field($sid);
                    $formats[] = '%s';
                }

                $clientIp = $payload->getClientIp();
                if (($row['client_ip'] ?? '') === '' && $clientIp !== null && $clientIp !== '') {
                    $sanitizedIp = $this->sanitizeIp($clientIp);
                    if ($sanitizedIp !== '') {
                        $updates['client_ip'] = $sanitizedIp;
                        $formats[] = '%s';
                    }
                }

                $clientUa = $payload->getClientUserAgent();
                if (($row['client_user_agent'] ?? '') === '' && $clientUa !== null && $clientUa !== '') {
                    $updates['client_user_agent'] = $this->sanitizeUserAgent($clientUa);
                    $formats[] = '%s';
                }

                $eventTimestamp = $payload->getEventTimestampMicros();
                if ((int) ($row['event_timestamp'] ?? 0) === 0 && $eventTimestamp !== null) {
                    $updates['event_timestamp'] = (int) $eventTimestamp;
                    $formats[] = '%d';
                }

                $intentId = $payload->getBookingIntentId();
                if (($row['booking_intent_id'] ?? '') === '' && $intentId !== null && $intentId !== '') {
                    $updates['booking_intent_id'] = $this->sanitizeNullableString($intentId);
                    $formats[] = '%s';
                }

                $eventId = $payload->getEventId();
                if (($row['event_id'] ?? '') === '' && $eventId !== null && $eventId !== '') {
                    $updates['event_id'] = $this->sanitizeNullableString($eventId);
                    $formats[] = '%s';
                }

                if ($updates === []) {
                    continue;
                }

                $wpdb->update(
                    $tableName,
                    $updates,
                    ['id' => (int) ($row['id'] ?? 0)],
                    $formats,
                    ['%d']
                );
            }
        } while (count($rows) === $limit);
    }

    private function sanitizeNullableString($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            return null;
        }

        $sanitized = sanitize_text_field($value);

        return $sanitized === '' ? null : $sanitized;
    }

    private function sanitizeUserAgent($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (function_exists('wp_check_invalid_utf8')) {
            $value = wp_check_invalid_utf8($value, true);
        }

        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, 512);
        } else {
            $value = substr($value, 0, 512);
        }

        return sanitize_text_field($value);
    }

    private function sanitizeIp($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $valid = filter_var($value, FILTER_VALIDATE_IP);

        return $valid !== false ? $valid : '';
    }

    private function sanitizeDate($value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $candidate = trim($value);

        $date = \DateTime::createFromFormat('Y-m-d', $candidate);

        if (!$date) {
            return null;
        }

        $errors = \DateTime::getLastErrors();

        if (($errors['warning_count'] ?? 0) > 0) {
            return null;
        }

        if (($errors['error_count'] ?? 0) > 0) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private function getWpdb(): ?wpdb
    {
        global $wpdb;

        return $wpdb instanceof wpdb ? $wpdb : null;
    }
}
