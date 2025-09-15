<?php declare(strict_types=1);

namespace FpHic\DatabaseOptimizer;

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
    private const BATCH_SIZE = 1000;
    
    /** @var int Query cache duration in seconds */
    private const QUERY_CACHE_DURATION = 300; // 5 minutes
    
    public function __construct() {
        add_action('init', [$this, 'initialize_optimizer'], 20);
        add_action('hic_daily_database_maintenance', [$this, 'execute_daily_maintenance']);
        add_action('hic_weekly_database_optimization', [$this, 'execute_weekly_optimization']);
        add_filter('hic_optimize_query', [$this, 'optimize_query'], 10, 2);
        
        // AJAX handlers for admin dashboard
        add_action('wp_ajax_hic_get_database_stats', [$this, 'ajax_get_database_stats']);
        add_action('wp_ajax_hic_optimize_database', [$this, 'ajax_optimize_database']);
        add_action('wp_ajax_hic_archive_old_data', [$this, 'ajax_archive_old_data']);
        
        // Schedule optimization tasks
        add_action('wp', [$this, 'schedule_optimization_tasks']);
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
        
        $main_table = $wpdb->prefix . 'hic_gclids';
        $sync_table = $wpdb->prefix . 'hic_realtime_sync';
        
        // Composite indexes for main table (frequent query patterns)
        $indexes = [
            [
                'table'   => $main_table,
                'name'    => 'idx_created_utm_source',
                'columns' => 'created_at, utm_source',
            ],
            [
                'table'   => $main_table,
                'name'    => 'idx_created_utm_medium',
                'columns' => 'created_at, utm_medium',
            ],
            [
                'table'   => $main_table,
                'name'    => 'idx_created_utm_campaign',
                'columns' => 'created_at, utm_campaign',
            ],

            // Index for conversion tracking queries
            [
                'table'   => $main_table,
                'name'    => 'idx_sid_created',
                'columns' => 'sid, created_at',
            ],
            [
                'table'   => $main_table,
                'name'    => 'idx_gclid_created',
                'columns' => 'gclid, created_at',
            ],
            [
                'table'   => $main_table,
                'name'    => 'idx_fbclid_created',
                'columns' => 'fbclid, created_at',
            ],

            // Composite index for dashboard queries (source + medium + date)
            [
                'table'   => $main_table,
                'name'    => 'idx_source_medium_date',
                'columns' => 'utm_source, utm_medium, created_at',
            ],

            // Index for cleanup and archiving operations
            [
                'table'   => $main_table,
                'name'    => 'idx_created_id',
                'columns' => 'created_at, id',
            ],
        ];

        // Indexes for sync table
        $sync_indexes = [
            [
                'table'   => $sync_table,
                'name'    => 'idx_reservation_status',
                'columns' => 'reservation_id, sync_status',
            ],
            [
                'table'   => $sync_table,
                'name'    => 'idx_status_attempt',
                'columns' => 'sync_status, last_attempt',
            ],
            [
                'table'   => $sync_table,
                'name'    => 'idx_first_seen',
                'columns' => 'first_seen',
            ],
        ];

        $indexes = array_merge( $indexes, $sync_indexes );

        foreach ( $indexes as $index ) {
            $table   = $index['table'];
            $name    = $index['name'];
            $columns = $index['columns'];

            $index_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SHOW INDEX FROM {$table} WHERE Key_name = %s",
                    $name
                )
            );

            if ( ! $index_exists ) {
                $result = $wpdb->query( "CREATE INDEX {$name} ON {$table} ({$columns})" );
                if ( $result === false ) {
                    $this->log( 'Failed to create index: ' . $wpdb->last_error );
                }
            }
        }
        
        $this->log('Optimized database indexes created/verified');
    }
    
    /**
     * Create archive tables for historical data
     */
    private function create_archive_tables() {
        global $wpdb;
        
        $archive_table = $wpdb->prefix . 'hic_gclids_archive';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$archive_table} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            original_id BIGINT NOT NULL,
            gclid VARCHAR(255),
            fbclid VARCHAR(255),
            msclkid VARCHAR(255),
            ttclid VARCHAR(255),
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
        
        $cache_table = $wpdb->prefix . 'hic_query_cache';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$cache_table} (
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
        
        $perf_table = $wpdb->prefix . 'hic_performance_metrics';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$perf_table} (
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
     * Archive old data to keep main tables lean
     */
    private function archive_old_data() {
        global $wpdb;
        
        $main_table = $wpdb->prefix . 'hic_gclids';
        $archive_table = $wpdb->prefix . 'hic_gclids_archive';
        
        // Calculate cutoff date (6 months ago)
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-' . self::ARCHIVE_MONTHS . ' months'));
        
        // Get count of records to archive
        $count_query = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$main_table} WHERE created_at < %s",
            $cutoff_date
        );
        $total_to_archive = $wpdb->get_var($count_query);
        
        if ($total_to_archive == 0) {
            $this->log('No old data to archive');
            return 0;
        }
        
        $this->log("Found {$total_to_archive} records to archive");
        
        $archived_count = 0;
        $batch_count = 0;
        
        // Process in batches to avoid memory issues
        while ($archived_count < $total_to_archive) {
            $batch_count++;
            
            // Insert batch into archive table
            $insert_query = $wpdb->prepare("
                INSERT INTO {$archive_table} 
                (original_id, gclid, fbclid, msclkid, ttclid, sid, utm_source, utm_medium, utm_campaign, utm_content, utm_term, created_at)
                SELECT id, gclid, fbclid, msclkid, ttclid, sid, utm_source, utm_medium, utm_campaign, utm_content, utm_term, created_at
                FROM {$main_table} 
                WHERE created_at < %s 
                LIMIT %d
            ", $cutoff_date, self::BATCH_SIZE);
            
            $inserted = $wpdb->query($insert_query);
            
            if ($inserted === false) {
                $this->log("Archive batch {$batch_count} failed: " . $wpdb->last_error);
                break;
            }
            
            // Delete archived records from main table
            $delete_query = $wpdb->prepare("
                DELETE FROM {$main_table} 
                WHERE created_at < %s 
                LIMIT %d
            ", $cutoff_date, $inserted);
            
            $deleted = $wpdb->query($delete_query);
            
            if ($deleted === false) {
                $this->log("Delete batch {$batch_count} failed: " . $wpdb->last_error);
                break;
            }
            
            $archived_count += $deleted;
            
            $this->log("Batch {$batch_count}: Archived {$deleted} records");
            
            // Prevent timeout on large datasets
            if ($batch_count % 10 === 0) {
                sleep(1); // Brief pause every 10 batches
            }
        }
        
        return $archived_count;
    }
    
    /**
     * Optimize database tables
     */
    private function optimize_tables() {
        global $wpdb;
        
        $tables = [
            $wpdb->prefix . 'hic_gclids',
            $wpdb->prefix . 'hic_realtime_sync',
            $wpdb->prefix . 'hic_gclids_archive',
            $wpdb->prefix . 'hic_query_cache',
            $wpdb->prefix . 'hic_performance_metrics'
        ];
        
        foreach ($tables as $table) {
            // Check if table exists
            $table_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
                DB_NAME,
                $table
            ));
            
            if ($table_exists) {
                $wpdb->query("OPTIMIZE TABLE {$table}");
                $this->log("Optimized table: {$table}");
            }
        }
    }
    
    /**
     * Cleanup expired query cache entries
     */
    private function cleanup_query_cache() {
        global $wpdb;
        
        $cache_table = $wpdb->prefix . 'hic_query_cache';
        
        $deleted = $wpdb->query("DELETE FROM {$cache_table} WHERE expires_at < NOW()");
        
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
            $wpdb->prefix . 'hic_gclids',
            $wpdb->prefix . 'hic_realtime_sync'
        ];
        
        foreach ($tables as $table) {
            $wpdb->query("ANALYZE TABLE {$table}");
        }
        
        $this->log('Updated table statistics');
    }
    
    /**
     * Cleanup old performance metrics
     */
    private function cleanup_old_performance_metrics() {
        global $wpdb;
        
        $perf_table = $wpdb->prefix . 'hic_performance_metrics';
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-30 days'));
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$perf_table} WHERE timestamp < %s",
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
        // Cache booking counts by source for last 30 days
        $this->get_cached_query('bookings_by_source_30d', "
            SELECT utm_source, COUNT(*) as count 
            FROM {$this->get_main_table()} 
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
            FROM {$this->get_main_table()} 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(created_at)
        ");
    }
    
    /**
     * Analyze table indexes for optimization
     */
    private function analyze_table_indexes() {
        global $wpdb;
        
        $main_table = $wpdb->prefix . 'hic_gclids';
        
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
        
        $cache_table = $wpdb->prefix . 'hic_query_cache';
        $query_hash = md5($cache_key . $query);
        
        // Try to get from cache
        $cached_result = $wpdb->get_var($wpdb->prepare(
            "SELECT query_result FROM {$cache_table} 
             WHERE query_hash = %s AND expires_at > NOW()",
            $query_hash
        ));
        
        if ($cached_result !== null) {
            // Update hit count
            $wpdb->query($wpdb->prepare(
                "UPDATE {$cache_table} SET hit_count = hit_count + 1 WHERE query_hash = %s",
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
        
        $perf_table = $wpdb->prefix . 'hic_performance_metrics';
        
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
        return $wpdb->prefix . 'hic_gclids';
    }
    
    /**
     * AJAX: Get database statistics
     */
    public function ajax_get_database_stats() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        
        $main_table = $wpdb->prefix . 'hic_gclids';
        $archive_table = $wpdb->prefix . 'hic_gclids_archive';
        $cache_table = $wpdb->prefix . 'hic_query_cache';
        
        // Get table sizes and row counts
        $stats = [
            'main_table' => [
                'rows' => $wpdb->get_var("SELECT COUNT(*) FROM {$main_table}"),
                'size' => $this->get_table_size($main_table)
            ],
            'archive_table' => [
                'rows' => $wpdb->get_var("SELECT COUNT(*) FROM {$archive_table}"),
                'size' => $this->get_table_size($archive_table)
            ],
            'cache_table' => [
                'rows' => $wpdb->get_var("SELECT COUNT(*) FROM {$cache_table}"),
                'size' => $this->get_table_size($cache_table)
            ]
        ];
        
        // Get recent performance metrics
        $perf_table = $wpdb->prefix . 'hic_performance_metrics';
        $recent_metrics = $wpdb->get_results($wpdb->prepare(
            "SELECT metric_type, AVG(metric_value) as avg_value, MAX(metric_value) as max_value
             FROM {$perf_table} 
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
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
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
     * AJAX: Manual data archiving
     */
    public function ajax_archive_old_data() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        // Verify nonce
        if (!check_ajax_referer('hic_archive_data', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        try {
            $archived_count = $this->archive_old_data();
            
            wp_send_json_success([
                'message' => "Archived {$archived_count} old records",
                'archived_count' => $archived_count
            ]);
            
        } catch (\Exception $e) {
            wp_send_json_error('Archiving failed: ' . $e->getMessage());
        }
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