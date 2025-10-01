<?php declare(strict_types=1);

namespace FpHic\HicS2S\Repository;

if (!defined('ABSPATH')) {
    exit;
}

use wpdb;

final class Logs
{
    private const TABLE = 'hic_logs';

    private bool $handlingEncodingFailure = false;

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
            ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            channel VARCHAR(50) NOT NULL,
            level VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            context LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY channel (channel),
            KEY ts (ts)
        ) {$charset};";

        $this->runDbDelta($sql);
    }

    public function log(string $channel, string $level, string $message, array $context = []): ?int
    {
        $wpdb = $this->getWpdb();

        if (!$wpdb) {
            return null;
        }

        $contextJson = wp_json_encode($context, JSON_UNESCAPED_UNICODE);

        if (!is_string($contextJson)) {
            $contextJson = null;

            if (!$this->handlingEncodingFailure) {
                $this->handlingEncodingFailure = true;

                $preview = $this->createContextPreview($context);
                $jsonError = function_exists('json_last_error_msg') ? json_last_error_msg() : null;

                $this->log('logger', 'error', 'Impossibile serializzare il contesto di un log', [
                    'original_channel' => $channel,
                    'original_level' => $level,
                    'json_error' => $jsonError,
                    'preview' => $preview,
                ]);

                $this->handlingEncodingFailure = false;
            }
        }

        $result = $wpdb->insert(
            $this->getTableName(),
            [
                'channel' => sanitize_key($channel),
                'level' => sanitize_text_field($level),
                'message' => wp_kses_post($message),
                'context' => $contextJson,
            ],
            ['%s', '%s', '%s', '%s']
        );

        if ($result === false) {
            return null;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function latest(int $limit = 50, ?string $channel = null): array
    {
        $wpdb = $this->getWpdb();

        if (!$wpdb) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $table = $this->getTableName();

        if ($channel !== null && $channel !== '') {
            $channel = sanitize_key($channel);
            $sql = $wpdb->prepare(
                "SELECT * FROM {$table} WHERE channel = %s ORDER BY ts DESC LIMIT %d",
                $channel,
                $limit
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql = $wpdb->prepare("SELECT * FROM {$table} ORDER BY ts DESC LIMIT %d", $limit);
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function pruneOlderThan(int $days): int
    {
        $wpdb = $this->getWpdb();

        if (!$wpdb) {
            return 0;
        }

        $days = max(1, $days);
        $dayInSeconds = defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400;
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * $dayInSeconds));
        $table = $this->getTableName();

        $query = $wpdb->prepare("DELETE FROM {$table} WHERE ts < %s", $cutoff);

        $deleted = $wpdb->query($query);

        return is_int($deleted) ? $deleted : 0;
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

    private function createContextPreview(array $context): string
    {
        $preview = wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (!is_string($preview)) {
            $preview = print_r($context, true);
        }

        if (function_exists('wp_check_invalid_utf8')) {
            $preview = wp_check_invalid_utf8($preview, true);
        }

        return substr($preview, 0, 5000);
    }
}
