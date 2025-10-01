<?php declare(strict_types=1);

namespace FpHic\HicS2S\Repository;

if (!defined('ABSPATH')) {
    exit;
}

use wpdb;

final class BookingIntents
{
    private const TABLE = 'hic_booking_intents';

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

        $charset = $wpdb->get_charset_collate();
        $table = $this->getTableName();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            intent_id CHAR(36) NOT NULL,
            sid VARCHAR(191) NOT NULL,
            utms LONGTEXT NULL,
            ids LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY intent_id (intent_id),
            KEY sid (sid(100)),
            KEY created_at (created_at)
        ) {$charset};";

        $this->runDbDelta($sql);
    }

    public function record(string $intentId, string $sid, array $utmParams, array $ids): ?int
    {
        $wpdb = $this->getWpdb();

        if (!$wpdb) {
            return null;
        }

        $sanitizedIntentId = sanitize_text_field($intentId);
        $sanitizedSid = sanitize_text_field($sid);

        $filteredUtms = $this->filterEmpty($utmParams);
        $utmsJson = wp_json_encode($filteredUtms, JSON_UNESCAPED_UNICODE);

        if (!is_string($utmsJson)) {
            $this->logs->log('booking_intents', 'error', 'Impossibile serializzare i parametri UTM dell\'intent', [
                'intent_id' => $sanitizedIntentId,
                'sid' => $sanitizedSid,
                'field' => 'utms',
                'preview' => $this->summarizeForLog($filteredUtms),
            ]);

            return null;
        }

        $filteredIds = $this->filterEmpty($ids);
        $idsJson = wp_json_encode($filteredIds, JSON_UNESCAPED_UNICODE);

        if (!is_string($idsJson)) {
            $this->logs->log('booking_intents', 'error', 'Impossibile serializzare gli identificativi dell\'intent', [
                'intent_id' => $sanitizedIntentId,
                'sid' => $sanitizedSid,
                'field' => 'ids',
                'preview' => $this->summarizeForLog($filteredIds),
            ]);

            return null;
        }

        $data = [
            'intent_id' => $sanitizedIntentId,
            'sid' => $sanitizedSid,
            'utms' => $utmsJson,
            'ids' => $idsJson,
        ];

        $formats = ['%s', '%s', '%s', '%s'];

        $result = $wpdb->insert($this->getTableName(), $data, $formats);

        if ($result === false) {
            return null;
        }

        return (int) $wpdb->insert_id;
    }

    public function findByIntentId(string $intentId): ?array
    {
        $wpdb = $this->getWpdb();

        if (!$wpdb) {
            return null;
        }

        $table = $this->getTableName();
        $query = $wpdb->prepare("SELECT * FROM {$table} WHERE intent_id = %s LIMIT 1", $intentId);
        $row = $wpdb->get_row($query, ARRAY_A);

        if (!is_array($row)) {
            return null;
        }

        return $row;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function latest(int $limit = 20): array
    {
        $wpdb = $this->getWpdb();

        if (!$wpdb) {
            return [];
        }

        $limit = max(1, min(200, $limit));

        $table = $this->getTableName();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", $limit);
        $results = $wpdb->get_results($sql, ARRAY_A);

        return is_array($results) ? $results : [];
    }

    private function filterEmpty(array $data): array
    {
        return array_filter(
            $data,
            static function ($value): bool {
                if (is_string($value)) {
                    return trim($value) !== '';
                }

                return !empty($value);
            }
        );
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

        if (!function_exists('dbDelta') && is_readable($upgradeFile)) {
            require_once $upgradeFile;
        }

        if (function_exists('dbDelta')) {
            dbDelta($sql);
        }
    }

    private function getWpdb(): ?wpdb
    {
        global $wpdb;

        return $wpdb instanceof wpdb ? $wpdb : null;
    }

    /**
     * @param mixed $value
     */
    private function summarizeForLog($value): string
    {
        if (is_array($value)) {
            $pieces = [];
            $count = 0;
            foreach ($value as $key => $item) {
                $count++;
                if ($count > 5) {
                    $pieces[] = '…';
                    break;
                }

                $pieces[] = sprintf('%s=%s', (string) $key, $this->scalarPreview($item));
            }

            $summary = implode(', ', $pieces);
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
}
