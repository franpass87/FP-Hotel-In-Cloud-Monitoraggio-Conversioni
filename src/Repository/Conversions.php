<?php declare(strict_types=1);

namespace FpHic\HicS2S\Repository;

if (!defined('ABSPATH')) {
    exit;
}

use wpdb;

final class Conversions
{
    private const TABLE = 'hic_conversions';

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
            bucket VARCHAR(50) NOT NULL DEFAULT 'unknown',
            ga4_sent TINYINT(1) NOT NULL DEFAULT 0,
            meta_sent TINYINT(1) NOT NULL DEFAULT 0,
            raw_json LONGTEXT NULL,
            PRIMARY KEY  (id),
            KEY booking_code (booking_code),
            KEY bucket (bucket),
            KEY created_at (created_at)
        ) {$charset};";

        $this->runDbDelta($sql);
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
            'bucket' => 'unknown',
            'ga4_sent' => 0,
            'meta_sent' => 0,
            'raw_json' => null,
        ];

        $payload = wp_parse_args($data, $defaults);

        $inserted = $wpdb->insert(
            $this->getTableName(),
            [
                'booking_code' => sanitize_text_field((string) $payload['booking_code']),
                'status' => sanitize_text_field((string) $payload['status']),
                'checkin' => $this->sanitizeDate($payload['checkin']),
                'checkout' => $this->sanitizeDate($payload['checkout']),
                'currency' => strtoupper(sanitize_text_field((string) $payload['currency'])),
                'amount' => (float) $payload['amount'],
                'guest_email_hash' => sanitize_text_field((string) $payload['guest_email_hash']),
                'guest_phone_hash' => sanitize_text_field((string) $payload['guest_phone_hash']),
                'bucket' => sanitize_text_field((string) $payload['bucket']),
                'ga4_sent' => (int) $payload['ga4_sent'],
                'meta_sent' => (int) $payload['meta_sent'],
                'raw_json' => is_null($payload['raw_json']) ? null : wp_json_encode($payload['raw_json'], JSON_UNESCAPED_UNICODE),
            ],
            [
                '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%d', '%d', '%s',
            ]
        );

        if ($inserted === false) {
            return null;
        }

        return (int) $wpdb->insert_id;
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

    private function sanitizeDate($value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $date = date_create($value);

        if (!$date) {
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
