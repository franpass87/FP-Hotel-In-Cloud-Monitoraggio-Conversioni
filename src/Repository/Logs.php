<?php declare(strict_types=1);

namespace FpHic\HicS2S\Repository;

if (!defined('ABSPATH')) {
    exit;
}

use wpdb;

final class Logs
{
    private const TABLE = 'hic_logs';

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

        $result = $wpdb->insert(
            $this->getTableName(),
            [
                'channel' => sanitize_key($channel),
                'level' => sanitize_text_field($level),
                'message' => wp_kses_post($message),
                'context' => wp_json_encode($context, JSON_UNESCAPED_UNICODE),
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
}
