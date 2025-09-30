<?php declare(strict_types=1);

namespace FpHic\DatabaseOptimizer;

use function __;
use function FpHic\Helpers\hic_require_cap;
use function FpHic\Helpers\hic_sanitize_identifier;

if (!defined('ABSPATH')) exit;

/**
 * Database Optimizer - Enterprise Grade
 * 
 * Implements intelligent indexing, automatic data archiving, 
 * query optimization, and performance monitoring for high-volume hotels.
 */

class DatabaseOptimizer {
    
    /** @var int Archive data older than this many months */
    private const ARCHIVE_MONTHS = 6;
    
    /** @var int Maximum records to process in a single batch */
    public const BATCH_SIZE = 1000;

    /** @var string Transient key used to persist archive job state */
    private const ARCHIVE_STATE_KEY = 'hic_archive_state';

    /** @var int Lifetime (in seconds) for persisted archive job state */
    private const ARCHIVE_STATE_TTL = DAY_IN_SECONDS;

    /** @var int Query cache duration in seconds */
    private const QUERY_CACHE_DURATION = 300; // 5 minutes
    
    public function __construct() {
        add_action('init', [$this, 'maybe_initialize_optimizer'], 20);
        add_action('hic_daily_database_maintenance', [$this, 'execute_daily_maintenance']);
        add_action('hic_weekly_database_optimization', [$this, 'execute_weekly_optimization']);
        add_filter('hic_optimize_query', [$this, 'optimize_query'], 10, 2);
        
        // AJAX handlers for admin dashboard
        add_action('wp_ajax_hic_get_database_stats', [$this, 'ajax_get_database_stats']);
        add_action('wp_ajax_hic_optimize_database', [$this, 'ajax_optimize_database']);
        add_action('wp_ajax_hic_archive_old_data', [$this, 'ajax_archive_old_data_start']);
        add_action('wp_ajax_hic_archive_old_data_start', [$this, 'ajax_archive_old_data_start']);
        add_action('wp_ajax_hic_archive_old_data_step', [$this, 'ajax_archive_old_data_step']);
        add_action('wp_ajax_hic_archive_old_data_status', [$this, 'ajax_archive_old_data_status']);
        
        // Schedule optimization tasks
        add_action('wp', [$this, 'schedule_optimization_tasks']);
    }

    /**
     * Initialize optimizer once.
     */
    public function maybe_initialize_optimizer() {
        if (!get_option('hic_db_optimizer_initialized')) {
            $this->initialize_optimizer();
            update_option('hic_db_optimizer_initialized', 1);
        }
    }

    /**
     * Initialize database optimizer
     */
    public function initialize_optimizer() {
        $this->log('Initializing Database Optimizer');
        
        // Create optimized indexes if they don't exist
        $this->create_optimized_indexes();
        
        // Create archive tables
        $this->create_archive_tables();
        
        // Create query cache table
        $this->create_query_cache_table();
        
        // Initialize performance monitoring
        $this->initialize_performance_monitoring();
    }
    
    /**
     * Create optimized database indexes for frequent queries
     */
    private function create_optimized_indexes() {
        global $wpdb;
        
        $main_table = hic_sanitize_identifier($wpdb->prefix . 'hic_gclids', 'table');
        $sync_table = hic_sanitize_identifier($wpdb->prefix . 'hic_realtime_sync', 'table');
        
        // Indexes for main table (frequent query patterns)
        $indexes = [
            [
                'table'   => $main_table,
                'name'    => 'idx_created_at',
                'columns' => ['created_at'],
            ],
            [
                'table'   => $main_table,
                'name'    => 'idx_sid_created_at',
                'columns' => ['sid', 'created_at'],
            ],
        ];

        // Indexes for sync table
        $sync_indexes = [
            [
                'table'   => $sync_table,
                'name'    => 'idx_reservation_status',
                'columns' => ['reservation_id', 'sync_status'],
            ],
            [
                'table'   => $sync_table,
                'name'    => 'idx_status_attempt',
                'columns' => ['sync_status', 'last_attempt'],
            ],
            [
                'table'   => $sync_table,
                'name'    => 'idx_first_seen',
                'columns' => ['first_seen'],
            ],
        ];

        $indexes = array_merge( $indexes, $sync_indexes );
        $had_errors = false;

        foreach ( $indexes as $index ) {
            $table   = hic_sanitize_identifier($index['table'], 'table');
            $index_name = hic_sanitize_identifier($index['name'], 'index');
            $columns = array_map(
                static fn($column) => hic_sanitize_identifier((string) $column, 'column'),
                isset($index['columns']) ? (array) $index['columns'] : []
            );

            if (empty($columns)) {
                $had_errors = true;
                $this->log('Skipping index creation due to missing column definitions for ' . $index_name);
                continue;
            }

            $column_list = implode(', ', array_map(static fn($column) => "`{$column}`", $columns));

            $index_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SHOW INDEX FROM `{$table}` WHERE Key_name = %s",
                    $index_name
                )
            );

            if ( ! $index_exists ) {
                $result = $wpdb->query( "CREATE INDEX `{$index_name}` ON `{$table}` ({$column_list})" );
                if ( $result === false ) {
                    $had_errors = true;
                    $this->log( 'Failed to create index: ' . $wpdb->last_error );
                }
            }
        }

        $this->log(
            $had_errors
                ? 'Failed to create some database indexes'
                : 'Optimized database indexes created/verified'
        );
    }
    
    /**
     * Create archive tables for historical data
     */
    private function create_archive_tables() {
        global $wpdb;
        
        $archive_table = hic_sanitize_identifier($wpdb->prefix . 'hic_gclids_archive', 'table');
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$archive_table}` (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            original_id BIGINT NOT NULL,
            gclid VARCHAR(255),
            fbclid VARCHAR(255),
            msclkid VARCHAR(255),
            ttclid VARCHAR(255),
            gbraid VARCHAR(255),
            wbraid VARCHAR(255),
            sid VARCHAR(255),
            utm_source VARCHAR(255),
            utm_medium VARCHAR(255),
            utm_campaign VARCHAR(255),
            utm_content VARCHAR(255),
            utm_term VARCHAR(255),
            created_at TIMESTAMP,
            archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_original_id (original_id),
            INDEX idx_archived_date (archived_at),
            INDEX idx_created_archived (created_at, archived_at),
            INDEX idx_sid_archived (sid, archived_at),
            INDEX idx_gclid_archived (gclid, archived_at),
            INDEX idx_source_archived (utm_source, archived_at)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        $this->log('Archive table created/verified');
    }
    
    /**
     * Create query cache table for performance optimization
     */
    private function create_query_cache_table() {
        global $wpdb;
        
        $cache_table = hic_sanitize_identifier($wpdb->prefix . 'hic_query_cache', 'table');
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$cache_table}` (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            query_hash VARCHAR(64) NOT NULL UNIQUE,
            query_result LONGTEXT,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            hit_count INT DEFAULT 1,
            
            INDEX idx_query_hash (query_hash),
            INDEX idx_expires (expires_at),
            INDEX idx_created (created_at)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        $this->log('Query cache table created/verified');
    }
    
    /**
     * Initialize performance monitoring table
     */
    private function initialize_performance_monitoring() {
        global $wpdb;
        
        $perf_table = hic_sanitize_identifier($wpdb->prefix . 'hic_performance_metrics', 'table');
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$perf_table}` (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            metric_type VARCHAR(50) NOT NULL,
            metric_value DECIMAL(10,4) NOT NULL,
            query_type VARCHAR(100),
            execution_time DECIMAL(8,4),
            memory_usage BIGINT,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            INDEX idx_type_timestamp (metric_type, timestamp),
            INDEX idx_query_type (query_type),
            INDEX idx_timestamp (timestamp)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        $this->log('Performance monitoring table created/verified');
    }
    
    /**
     * Schedule optimization tasks
     */
    public function schedule_optimization_tasks() {
        // Schedule daily maintenance
        if (!wp_next_scheduled('hic_daily_database_maintenance')) {
            wp_schedule_event(time(), 'daily', 'hic_daily_database_maintenance');
            $this->log('Scheduled daily database maintenance');
        }
        
        // Schedule weekly optimization
        if (!wp_next_scheduled('hic_weekly_database_optimization')) {
            wp_schedule_event(time(), 'weekly', 'hic_weekly_database_optimization');
            $this->log('Scheduled weekly database optimization');
        }
    }
    
    /**
     * Execute daily database maintenance
     */
    public function execute_daily_maintenance() {
        $start_time = microtime(true);
        $this->log('Starting daily database maintenance');
        
        // Clean up expired query cache
        $this->cleanup_query_cache();
        
        // Update table statistics
        $this->update_table_statistics();
        
        // Cleanup old performance metrics (keep 30 days)
        $this->cleanup_old_performance_metrics();
        
        // Log maintenance completion
        $duration = microtime(true) - $start_time;
        $this->log("Daily maintenance completed in {$duration}s");
        
        $this->record_performance_metric('maintenance_duration', $duration, 'daily_maintenance');
    }
    
    /**
     * Execute weekly database optimization
     */
    public function execute_weekly_optimization() {
        $start_time = microtime(true);
        $this->log('Starting weekly database optimization');
        
        // Archive old data
        $archived_count = $this->archive_old_data();
        
        // Optimize tables
        $this->optimize_tables();
        
        // Rebuild query cache for popular queries
        $this->rebuild_popular_query_cache();
        
        // Analyze and update index statistics
        $this->analyze_table_indexes();
        
        $duration = microtime(true) - $start_time;
        $this->log("Weekly optimization completed in {$duration}s. Archived {$archived_count} records.");
        
        $this->record_performance_metric('optimization_duration', $duration, 'weekly_optimization');
    }
    
    /**
     * Archive old data to keep main tables lean.
     *
     * This synchronous variant is used by automated jobs (cron) and will bail
     * out early if a manual resumable job is currently running.
     */
    private function archive_old_data(): int {
        $active_state = $this->get_archive_state();

        if (is_array($active_state)) {
            $this->log('Skipping automated archive because a manual job is in progress.');
            return (int) ($active_state['processed'] ?? 0);
        }

        $state = $this->prepare_archive_state();

        if ((int) $state['total'] === 0) {
            $this->log('No old data to archive');
            return 0;
        }

        $this->log(sprintf('Found %d records to archive (cron run)', (int) $state['total']));

        $archived = 0;

        while (true) {
            $result = $this->process_archive_step($state, false);
            $state  = $result['state'];
            $archived += (int) $result['moved'];

            if ($result['done']) {
                break;
            }
        }

        return $archived;
    }

    /**
     * Prepare a fresh archive job state.
     *
     * @return array<string, mixed>
     */
    private function prepare_archive_state(): array {
        global $wpdb;

        $main_table = hic_sanitize_identifier($wpdb->prefix . 'hic_gclids', 'table');
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-' . self::ARCHIVE_MONTHS . ' months'));

        $count_query = $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$main_table}` WHERE created_at < %s",
            $cutoff_date
        );
        $total_to_archive = (int) $wpdb->get_var($count_query);

        return [
            'cutoff' => $cutoff_date,
            'total' => $total_to_archive,
            'processed' => 0,
            'batch' => 0,
            'last_batch' => 0,
            'batch_size' => self::BATCH_SIZE,
            'started_at' => time(),
            'last_activity' => time(),
        ];
    }

    /**
     * Retrieve the persisted archive state if present.
     *
     * @return array<string, mixed>|null
     */
    private function get_archive_state(): ?array {
        $state = get_transient(self::ARCHIVE_STATE_KEY);
        $normalized = $this->normalize_archive_state($state);

        if (null === $normalized && !empty($state)) {
            delete_transient(self::ARCHIVE_STATE_KEY);
        }

        return $normalized;
    }

    /**
     * Persist the current archive state for resumable jobs.
     *
     * @param array<string, mixed> $state Archive state to persist.
     */
    private function persist_archive_state(array $state): void {
        $normalized = $this->normalize_archive_state($state);

        if (null === $normalized) {
            $this->clear_archive_state();
            return;
        }

        set_transient(self::ARCHIVE_STATE_KEY, $normalized, self::ARCHIVE_STATE_TTL);
    }

    /**
     * Remove any persisted archive state.
     */
    private function clear_archive_state(): void {
        delete_transient(self::ARCHIVE_STATE_KEY);
    }

    /**
     * Ensure an archive state structure contains the expected keys and sane values.
     *
     * @param mixed $state Raw archive state retrieved from storage.
     *
     * @return array<string, mixed>|null Normalized state or null if invalid.
     */
    private function normalize_archive_state($raw_state): ?array {
        if (!is_array($raw_state)) {
            return null;
        }

        $cutoff = isset($raw_state['cutoff']) && is_string($raw_state['cutoff']) ? trim($raw_state['cutoff']) : '';

        if ($cutoff === '') {
            return null;
        }

        $total = max(0, (int) ($raw_state['total'] ?? 0));
        $processed = max(0, (int) ($raw_state['processed'] ?? 0));
        $batch = max(0, (int) ($raw_state['batch'] ?? 0));
        $last_batch = max(0, (int) ($raw_state['last_batch'] ?? 0));
        $started_at = max(0, (int) ($raw_state['started_at'] ?? time()));
        $last_activity = max(0, (int) ($raw_state['last_activity'] ?? time()));
        $batch_size = max(1, (int) ($raw_state['batch_size'] ?? self::BATCH_SIZE));

        if ($processed > $total) {
            $total = $processed;
        }

        return [
            'cutoff' => $cutoff,
            'total' => $total,
            'processed' => $processed,
            'batch' => $batch,
            'last_batch' => $last_batch,
            'started_at' => $started_at,
            'last_activity' => $last_activity,
            'batch_size' => $batch_size,
        ];
    }

    /**
     * Process a single archive batch.
     *
     * @param array<string, mixed> $state         Current archive state reference.
     * @param bool                 $persist_state Whether to persist the updated state.
     *
     * @return array{state: array<string, mixed>, moved: int, done: bool}
     */
    private function process_archive_step(array $state, bool $persist_state = true): array {
        $state = $this->normalize_archive_state($state);

        if (null === $state) {
            throw new \RuntimeException('Invalid archive state.');
        }

        global $wpdb;

        $main_table = hic_sanitize_identifier($wpdb->prefix . 'hic_gclids', 'table');
        $archive_table = hic_sanitize_identifier($wpdb->prefix . 'hic_gclids_archive', 'table');

        $batch_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM `{$main_table}` WHERE created_at < %s ORDER BY id ASC LIMIT %d",
                $state['cutoff'],
                self::BATCH_SIZE
            )
        );

        if (empty($batch_ids)) {
            $state['last_activity'] = time();
            $state['processed'] = max((int) ($state['processed'] ?? 0), (int) ($state['total'] ?? 0));

            if ($persist_state) {
                $this->clear_archive_state();
            }

            return [
                'state' => $state,
                'moved' => 0,
                'done' => true,
            ];
        }

        $placeholders = implode(', ', array_fill(0, count($batch_ids), '%d'));

        $insert_query = $wpdb->prepare(
            "
                INSERT INTO `{$archive_table}`
                    (original_id, gclid, fbclid, msclkid, ttclid, gbraid, wbraid, sid, utm_source, utm_medium, utm_campaign, utm_content, utm_term, created_at)
                SELECT id, gclid, fbclid, msclkid, ttclid, gbraid, wbraid, sid, utm_source, utm_medium, utm_campaign, utm_content, utm_term, created_at
                FROM `{$main_table}`
                WHERE id IN ({$placeholders})
            ",
            ...$batch_ids
        );

        if ($insert_query === false) {
            throw new \RuntimeException('Unable to build archive insert query.');
        }

        $inserted = $wpdb->query($insert_query);

        if ($inserted === false) {
            throw new \RuntimeException('Archive insert failed: ' . $wpdb->last_error);
        }

        $delete_query = $wpdb->prepare(
            "DELETE FROM `{$main_table}` WHERE id IN ({$placeholders})",
            ...$batch_ids
        );

        if ($delete_query === false) {
            throw new \RuntimeException('Unable to build archive delete query.');
        }

        $deleted = $wpdb->query($delete_query);

        if ($deleted === false) {
            throw new \RuntimeException('Archive delete failed: ' . $wpdb->last_error);
        }

        $moved = (int) $deleted;

        $state['processed'] = (int) $state['processed'] + $moved;
        $state['batch'] = (int) $state['batch'] + 1;
        $state['last_batch'] = $moved;
        $state['total'] = max((int) $state['total'], (int) $state['processed']);
        $state['last_activity'] = time();

        if ($persist_state) {
            $this->persist_archive_state($state);
        }

        $this->log(sprintf('Batch %d: Archived %d records', (int) $state['batch'], $moved));

        return [
            'state' => $state,
            'moved' => $moved,
            'done' => false,
        ];
    }

    /**
     * Normalize archive state for JSON responses.
     *
     * @param array<string, mixed> $state Archive state to normalize.
     * @param bool                 $done  Whether the job is complete.
     *
     * @return array<string, mixed>
     */
    private function format_archive_state(array $state, bool $done = false): array {
        $state = $this->normalize_archive_state($state);

        if (null === $state) {
            $state = [
                'cutoff' => '',
                'total' => 0,
                'processed' => 0,
                'batch' => 0,
                'last_batch' => 0,
                'started_at' => time(),
                'last_activity' => time(),
                'batch_size' => self::BATCH_SIZE,
            ];
        }

        $total = (int) $state['total'];
        $processed = (int) $state['processed'];

        if ($done) {
            $processed = max($processed, $total);
        }

        if ($processed > $total) {
            $total = $processed;
        }

        $progress = $total > 0 ? min(1, $processed / $total) : 1;

        return [
            'cutoff' => (string) $state['cutoff'],
            'total' => $total,
            'processed' => $processed,
            'remaining' => max(0, $total - $processed),
            'progress' => (float) round($progress, 4),
            'percentage' => (float) round($progress * 100, 2),
            'batch' => (int) $state['batch'],
            'last_batch' => (int) $state['last_batch'],
            'batch_size' => (int) $state['batch_size'],
            'started_at' => (int) $state['started_at'],
            'last_activity' => (int) $state['last_activity'],
            'done' => $done,
        ];
    }

    /**
     * Attempt to enforce shared AJAX rate limiting helpers when available.
     */
    private function maybe_enforce_rate_limit(string $action, int $max_attempts, int $window): bool {
        if (function_exists('\\hic_enforce_ajax_rate_limit')) {
            return \hic_enforce_ajax_rate_limit($action, $max_attempts, $window);
        }

        return true;
    }

    /**
     * Validate archive AJAX nonces supporting legacy identifiers.
     *
     * @param array<int, string> $actions Accepted nonce action names.
     */
    private function verify_archive_nonce(array $actions): bool {
        foreach ($actions as $action) {
            if (check_ajax_referer($action, 'nonce', false)) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Optimize database tables
     */
    private function optimize_tables() {
        global $wpdb;
        
        $tables = [
            hic_sanitize_identifier($wpdb->prefix . 'hic_gclids', 'table'),
            hic_sanitize_identifier($wpdb->prefix . 'hic_realtime_sync', 'table'),
            hic_sanitize_identifier($wpdb->prefix . 'hic_gclids_archive', 'table'),
            hic_sanitize_identifier($wpdb->prefix . 'hic_query_cache', 'table'),
            hic_sanitize_identifier($wpdb->prefix . 'hic_performance_metrics', 'table')
        ];
        
        foreach ($tables as $table) {
            // Check if table exists
            $table_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
                DB_NAME,
                $table
            ));

            if ($table_exists) {
                $wpdb->query("OPTIMIZE TABLE `{$table}`");
                $this->log("Optimized table: {$table}");
            }
        }
    }
    
    /**
     * Cleanup expired query cache entries
     */
    private function cleanup_query_cache() {
        global $wpdb;
        
        $cache_table = hic_sanitize_identifier($wpdb->prefix . 'hic_query_cache', 'table');

        $deleted = $wpdb->query("DELETE FROM `{$cache_table}` WHERE expires_at < NOW()");
        
        if ($deleted > 0) {
            $this->log("Cleaned up {$deleted} expired cache entries");
        }
    }
    
    /**
     * Update table statistics for query optimizer
     */
    private function update_table_statistics() {
        global $wpdb;
        
        $tables = [
            hic_sanitize_identifier($wpdb->prefix . 'hic_gclids', 'table'),
            hic_sanitize_identifier($wpdb->prefix . 'hic_realtime_sync', 'table')
        ];

        foreach ($tables as $table) {
            $wpdb->query("ANALYZE TABLE `{$table}`");
        }
        
        $this->log('Updated table statistics');
    }
    
    /**
     * Cleanup old performance metrics
     */
    private function cleanup_old_performance_metrics() {
        global $wpdb;
        
        $perf_table = hic_sanitize_identifier($wpdb->prefix . 'hic_performance_metrics', 'table');
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-30 days'));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$perf_table}` WHERE timestamp < %s",
            $cutoff_date
        ));
        
        if ($deleted > 0) {
            $this->log("Cleaned up {$deleted} old performance metrics");
        }
    }
    
    /**
     * Rebuild query cache for popular queries
     */
    private function rebuild_popular_query_cache() {
        // Clear old cache
        $this->cleanup_query_cache();
        
        // Pre-populate cache with common dashboard queries
        $this->cache_dashboard_queries();
        
        $this->log('Rebuilt popular query cache');
    }
    
    /**
     * Cache common dashboard queries
     */
    private function cache_dashboard_queries() {
        $main_table = $this->get_main_table();

        // Cache booking counts by source for last 30 days
        $this->get_cached_query('bookings_by_source_30d', "
            SELECT utm_source, COUNT(*) as count
            FROM `{$main_table}`
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY utm_source
        ");

        // Cache conversion funnel data
        $this->get_cached_query('conversion_funnel_7d', "
            SELECT
                DATE(created_at) as date,
                COUNT(*) as total_bookings,
                COUNT(DISTINCT gclid) as google_conversions,
                COUNT(DISTINCT fbclid) as facebook_conversions
            FROM `{$main_table}`
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(created_at)
        ");
    }
    
    /**
     * Analyze table indexes for optimization
     */
    private function analyze_table_indexes() {
        global $wpdb;
        
        $main_table = hic_sanitize_identifier($wpdb->prefix . 'hic_gclids', 'table');

        // Get index usage statistics
        $index_stats = $wpdb->get_results($wpdb->prepare("
            SELECT
                DISTINCT s.table_name,
                s.index_name,
                s.cardinality,
                s.collation,
                s.column_name
            FROM information_schema.statistics s
            WHERE s.table_schema = %s 
            AND s.table_name = %s
            ORDER BY s.table_name, s.index_name, s.seq_in_index
        ", DB_NAME, $main_table));
        
        // Store index analysis results
        update_option('hic_index_analysis', [
            'timestamp' => time(),
            'indexes' => $index_stats,
            'recommendations' => $this->get_index_recommendations($index_stats)
        ]);
        
        $this->log('Analyzed table indexes');
    }
    
    /**
     * Get index optimization recommendations
     */
    private function get_index_recommendations($index_stats) {
        $recommendations = [];
        
        // Analyze index cardinality and usage patterns
        foreach ($index_stats as $index) {
            if ($index->cardinality < 10) {
                $recommendations[] = "Low cardinality index '{$index->index_name}' on column '{$index->column_name}' - consider removal";
            }
        }
        
        // Add more sophisticated analysis based on query patterns
        // This is a simplified version - in production, you'd analyze slow query logs
        
        return $recommendations;
    }
    
    /**
     * Get cached query result or execute and cache
     */
    public function get_cached_query($cache_key, $query, $cache_duration = null) {
        global $wpdb;
        
        if ($cache_duration === null) {
            $cache_duration = self::QUERY_CACHE_DURATION;
        }
        
        $cache_table = hic_sanitize_identifier($wpdb->prefix . 'hic_query_cache', 'table');
        $query_hash = md5($cache_key . $query);

        // Try to get from cache
        $cached_result = $wpdb->get_var($wpdb->prepare(
            "SELECT query_result FROM `{$cache_table}`
             WHERE query_hash = %s AND expires_at > NOW()",
            $query_hash
        ));

        if ($cached_result !== null) {
            // Update hit count
            $wpdb->query($wpdb->prepare(
                "UPDATE `{$cache_table}` SET hit_count = hit_count + 1 WHERE query_hash = %s",
                $query_hash
            ));

            return json_decode($cached_result, true);
        }
        
        // Execute query and cache result
        $start_time = microtime(true);
        $result = $wpdb->get_results($query, ARRAY_A);
        $execution_time = microtime(true) - $start_time;
        
        // Record performance metric
        $this->record_performance_metric('query_time', $execution_time, $cache_key);
        
        // Store in cache
        $expires_at = date('Y-m-d H:i:s', time() + $cache_duration);
        
        $wpdb->replace($cache_table, [
            'query_hash' => $query_hash,
            'query_result' => json_encode($result),
            'expires_at' => $expires_at,
            'hit_count' => 1
        ]);
        
        return $result;
    }
    
    /**
     * Record performance metric
     */
    private function record_performance_metric($metric_type, $value, $query_type = null) {
        global $wpdb;
        
        $perf_table = hic_sanitize_identifier($wpdb->prefix . 'hic_performance_metrics', 'table');

        $wpdb->insert($perf_table, [
            'metric_type' => $metric_type,
            'metric_value' => $value,
            'query_type' => $query_type,
            'memory_usage' => memory_get_usage(true)
        ]);
    }
    
    /**
     * Get main table name
     */
    private function get_main_table() {
        global $wpdb;
        return hic_sanitize_identifier($wpdb->prefix . 'hic_gclids', 'table');
    }
    
    /**
     * AJAX: Get database statistics
     */
    public function ajax_get_database_stats() {
        hic_require_cap('hic_manage');

        if (!check_ajax_referer('hic_optimize_db', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        global $wpdb;
        
        $main_table = hic_sanitize_identifier($wpdb->prefix . 'hic_gclids', 'table');
        $archive_table = hic_sanitize_identifier($wpdb->prefix . 'hic_gclids_archive', 'table');
        $cache_table = hic_sanitize_identifier($wpdb->prefix . 'hic_query_cache', 'table');
        
        // Get table sizes and row counts
        $stats = [
            'main_table' => [
                'rows' => $wpdb->get_var("SELECT COUNT(*) FROM `{$main_table}`"),
                'size' => $this->get_table_size($main_table)
            ],
            'archive_table' => [
                'rows' => $wpdb->get_var("SELECT COUNT(*) FROM `{$archive_table}`"),
                'size' => $this->get_table_size($archive_table)
            ],
            'cache_table' => [
                'rows' => $wpdb->get_var("SELECT COUNT(*) FROM `{$cache_table}`"),
                'size' => $this->get_table_size($cache_table)
            ]
        ];

        // Get recent performance metrics
        $perf_table = hic_sanitize_identifier($wpdb->prefix . 'hic_performance_metrics', 'table');
        $recent_metrics = $wpdb->get_results($wpdb->prepare(
            "SELECT metric_type, AVG(metric_value) as avg_value, MAX(metric_value) as max_value
             FROM `{$perf_table}`
             WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY metric_type",
        ));
        
        $stats['performance'] = $recent_metrics;
        $stats['index_analysis'] = get_option('hic_index_analysis', []);
        
        wp_send_json_success($stats);
    }
    
    /**
     * Get table size in MB
     */
    private function get_table_size($table_name) {
        global $wpdb;

        $table_name = hic_sanitize_identifier((string) $table_name, 'table');

        $size_query = $wpdb->prepare("
            SELECT
                ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
            FROM information_schema.TABLES
            WHERE table_schema = %s
            AND table_name = %s
        ", DB_NAME, $table_name);
        
        return $wpdb->get_var($size_query) ?: 0;
    }
    
    /**
     * AJAX: Manual database optimization
     */
    public function ajax_optimize_database() {
        hic_require_cap('hic_manage');
        
        // Verify nonce
        if (!check_ajax_referer('hic_optimize_db', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        $start_time = microtime(true);
        
        try {
            $this->optimize_tables();
            $this->cleanup_query_cache();
            $this->update_table_statistics();
            
            $duration = microtime(true) - $start_time;
            
            wp_send_json_success([
                'message' => "Database optimization completed in {$duration}s",
                'duration' => $duration
            ]);
            
        } catch (\Exception $e) {
            wp_send_json_error('Optimization failed: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX: Initialize or resume a resumable archive job.
     */
    public function ajax_archive_old_data_start() {
        hic_require_cap('hic_manage');

        if (!$this->maybe_enforce_rate_limit('archive_start', 3, MINUTE_IN_SECONDS)) {
            return;
        }

        if (!$this->verify_archive_nonce(['hic_archive_start', 'hic_archive_data'])) {
            wp_send_json_error(['message' => __('Nonce non valido per l\'archiviazione.', 'hotel-in-cloud')]);
        }

        try {
            $existing_state = $this->get_archive_state();

            if (is_array($existing_state)) {
                wp_send_json_success([
                    'message' => __('Job di archiviazione ripreso.', 'hotel-in-cloud'),
                    'state' => $this->format_archive_state($existing_state),
                    'resumed' => true,
                    'done' => false,
                ]);
            }

            $state = $this->prepare_archive_state();

            if ((int) $state['total'] === 0) {
                $this->clear_archive_state();

                wp_send_json_success([
                    'message' => __('Nessun dato da archiviare.', 'hotel-in-cloud'),
                    'state' => $this->format_archive_state($state, true),
                    'resumed' => false,
                    'done' => true,
                ]);
            }

            $this->persist_archive_state($state);

            wp_send_json_success([
                'message' => sprintf(
                    __('Archiviazione avviata: %d record da processare.', 'hotel-in-cloud'),
                    (int) $state['total']
                ),
                'state' => $this->format_archive_state($state),
                'resumed' => false,
                'done' => false,
            ]);
        } catch (\Throwable $e) {
            $this->clear_archive_state();

            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s is the error message returned by the archiver. */
                    __('Archiviazione non avviata: %s', 'hotel-in-cloud'),
                    $e->getMessage()
                ),
            ]);
        }
    }

    /**
     * AJAX: Process a single resumable archive step.
     */
    public function ajax_archive_old_data_step() {
        hic_require_cap('hic_manage');

        if (!$this->maybe_enforce_rate_limit('archive_step', 60, MINUTE_IN_SECONDS)) {
            return;
        }

        if (!$this->verify_archive_nonce(['hic_archive_step', 'hic_archive_data'])) {
            wp_send_json_error(['message' => __('Nonce non valido per il passo di archiviazione.', 'hotel-in-cloud')]);
        }

        $state = $this->get_archive_state();

        if (!is_array($state)) {
            wp_send_json_error(['message' => __('Nessun job di archiviazione attivo: avviare nuovamente l\'operazione.', 'hotel-in-cloud')]);
        }

        try {
            $result = $this->process_archive_step($state, true);

            $done = (bool) $result['done'];

            wp_send_json_success([
                'message' => $result['moved'] > 0
                    ? sprintf(
                        __('Batch %1$d completato: %2$d record archiviati.', 'hotel-in-cloud'),
                        (int) $result['state']['batch'],
                        (int) $result['moved']
                    )
                    : __('Archiviazione completata.', 'hotel-in-cloud'),
                'state' => $this->format_archive_state($result['state'], $done),
                'moved' => (int) $result['moved'],
                'done' => $done,
            ]);
        } catch (\Throwable $e) {
            $this->clear_archive_state();

            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s is the error message returned by the archiver. */
                    __('Errore durante l\'archiviazione: %s', 'hotel-in-cloud'),
                    $e->getMessage()
                ),
            ]);
        }
    }

    /**
     * AJAX: Return the current archive job status (if any).
     */
    public function ajax_archive_old_data_status() {
        hic_require_cap('hic_manage');

        if (!$this->maybe_enforce_rate_limit('archive_status', 30, MINUTE_IN_SECONDS)) {
            return;
        }

        if (!$this->verify_archive_nonce(['hic_archive_status', 'hic_archive_data'])) {
            wp_send_json_error(['message' => __('Nonce non valido per lo stato archiviazione.', 'hotel-in-cloud')]);
        }

        $state = $this->get_archive_state();

        if (!is_array($state)) {
            wp_send_json_success([
                'active' => false,
                'state' => null,
                'done' => true,
            ]);
        }

        wp_send_json_success([
            'active' => true,
            'state' => $this->format_archive_state($state),
            'done' => false,
        ]);
    }
    
    /**
     * Log messages with database optimizer prefix
     */
    private function log($message) {
        if (function_exists('\\FpHic\\Helpers\\hic_log')) {
            \FpHic\Helpers\hic_log("[Database Optimizer] {$message}");
        }
    }
}

// Initialize the database optimizer
new DatabaseOptimizer();