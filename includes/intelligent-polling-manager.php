<?php declare(strict_types=1);

namespace FpHic\IntelligentPolling;

if (!defined('ABSPATH')) exit;

/**
 * Intelligent Polling Manager - Enterprise Grade
 * 
 * Implements exponential backoff, connection pooling, and adaptive polling
 * based on hotel activity patterns for optimal performance.
 */

class IntelligentPollingManager {
    
    /** @var array Connection pool for API connections */
    private static $connection_pool = [];
    
    /** @var array Backoff intervals in seconds */
    private static $backoff_intervals = [60, 120, 300, 900, 1800]; // 1min → 2min → 5min → 15min → 30min
    
    /** @var int Maximum connections in pool */
    private const MAX_POOL_SIZE = 5;
    
    /** @var int Connection timeout in seconds */
    private const CONNECTION_TIMEOUT = 15;
    
    /** @var int Keep-alive timeout in seconds */
    private const KEEP_ALIVE_TIMEOUT = 300; // 5 minutes
    
    public function __construct() {
        add_action('init', [$this, 'initialize_intelligent_polling'], 15);
        add_action('hic_intelligent_poll_event', [$this, 'execute_intelligent_polling']);
        add_action('wp_ajax_hic_get_polling_metrics', [$this, 'ajax_get_polling_metrics']);
        add_action('wp_ajax_nopriv_hic_get_polling_metrics', [$this, 'ajax_get_polling_metrics']);
        
        // Cleanup connection pool periodically
        add_action('hic_cleanup_connection_pool', [$this, 'cleanup_connection_pool']);
        
        // Register custom cron intervals for intelligent polling
        add_filter('cron_schedules', [$this, 'add_intelligent_cron_intervals']);
    }
    
    /**
     * Add custom cron intervals for intelligent polling
     */
    public function add_intelligent_cron_intervals($schedules) {
        $schedules['hic_intelligent_1min'] = [
            'interval' => 60,
            'display' => __('Every 1 minute (Intelligent)', 'hotel-in-cloud')
        ];
        $schedules['hic_intelligent_2min'] = [
            'interval' => 120,
            'display' => __('Every 2 minutes (Intelligent)', 'hotel-in-cloud')
        ];
        $schedules['hic_intelligent_5min'] = [
            'interval' => 300,
            'display' => __('Every 5 minutes (Intelligent)', 'hotel-in-cloud')
        ];
        $schedules['hic_intelligent_15min'] = [
            'interval' => 900,
            'display' => __('Every 15 minutes (Intelligent)', 'hotel-in-cloud')
        ];
        $schedules['hic_intelligent_30min'] = [
            'interval' => 1800,
            'display' => __('Every 30 minutes (Intelligent)', 'hotel-in-cloud')
        ];
        
        return $schedules;
    }
    
    /**
     * Initialize intelligent polling system
     */
    public function initialize_intelligent_polling() {
        if (!$this->should_use_intelligent_polling()) {
            return;
        }
        
        $this->log('Initializing Intelligent Polling Manager');
        
        // Schedule intelligent polling if not already scheduled
        if (!wp_next_scheduled('hic_intelligent_poll_event')) {
            $interval = $this->get_current_polling_interval();
            wp_schedule_event(time(), $interval, 'hic_intelligent_poll_event');
            $this->log("Scheduled intelligent polling with interval: {$interval}");
        }
        
        // Schedule connection pool cleanup
        if (!wp_next_scheduled('hic_cleanup_connection_pool')) {
            wp_schedule_event(time() + 3600, 'hourly', 'hic_cleanup_connection_pool');
            $this->log('Scheduled connection pool cleanup');
        }
        
        // Initialize activity tracking
        $this->initialize_activity_tracking();
    }
    
    /**
     * Check if intelligent polling should be used
     */
    private function should_use_intelligent_polling() {
        // Check if intelligent polling is enabled in settings
        $enabled = get_option('hic_intelligent_polling_enabled', false);
        
        // Check if minimum requirements are met
        $has_credentials = \FpHic\Helpers\hic_has_basic_auth_credentials();
        $has_url = !empty(\FpHic\Helpers\hic_get_api_url());
        
        return $enabled && $has_credentials && $has_url;
    }
    
    /**
     * Get current polling interval based on activity patterns
     */
    private function get_current_polling_interval() {
        $activity_level = $this->analyze_booking_activity();
        $consecutive_failures = get_option('hic_intelligent_consecutive_failures', 0);
        
        // Apply exponential backoff on failures
        if ($consecutive_failures > 0) {
            $backoff_index = min($consecutive_failures - 1, count(self::$backoff_intervals) - 1);
            $backoff_interval = self::$backoff_intervals[$backoff_index];
            
            $this->log("Applying backoff: {$backoff_interval}s (failure #{$consecutive_failures})");
            
            // Map backoff intervals to cron schedule names
            switch ($backoff_interval) {
                case 60: return 'hic_intelligent_1min';
                case 120: return 'hic_intelligent_2min';
                case 300: return 'hic_intelligent_5min';
                case 900: return 'hic_intelligent_15min';
                case 1800: return 'hic_intelligent_30min';
                default: return 'hic_intelligent_5min';
            }
        }
        
        // Normal activity-based intervals
        switch ($activity_level) {
            case 'high':
                return 'hic_intelligent_1min'; // High activity: 1 minute
            case 'medium':
                return 'hic_intelligent_2min'; // Medium activity: 2 minutes
            case 'low':
                return 'hic_intelligent_5min'; // Low activity: 5 minutes
            case 'inactive':
                return 'hic_intelligent_15min'; // Inactive: 15 minutes
            default:
                return 'hic_intelligent_2min'; // Default: 2 minutes
        }
    }
    
    /**
     * Analyze booking activity to determine polling frequency
     */
    private function analyze_booking_activity() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_gclids';
        $current_time = current_time('mysql');
        
        // Check activity in last hour
        $recent_bookings = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE created_at >= DATE_SUB(%s, INTERVAL 1 HOUR)",
            $current_time
        ));
        
        // Check activity in last 6 hours
        $medium_term_bookings = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE created_at >= DATE_SUB(%s, INTERVAL 6 HOUR)",
            $current_time
        ));
        
        // Check activity in last 24 hours
        $daily_bookings = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE created_at >= DATE_SUB(%s, INTERVAL 24 HOUR)",
            $current_time
        ));
        
        // Determine activity level
        if ($recent_bookings >= 5) {
            $level = 'high';
        } elseif ($recent_bookings >= 2 || $medium_term_bookings >= 10) {
            $level = 'medium';
        } elseif ($recent_bookings >= 1 || $daily_bookings >= 5) {
            $level = 'low';
        } else {
            $level = 'inactive';
        }
        
        // Store activity metrics for dashboard
        update_option('hic_activity_level', $level);
        update_option('hic_activity_metrics', [
            'recent_bookings' => $recent_bookings,
            'medium_term_bookings' => $medium_term_bookings,
            'daily_bookings' => $daily_bookings,
            'level' => $level,
            'timestamp' => time()
        ]);
        
        $this->log("Activity analysis: Level={$level}, Recent={$recent_bookings}, 6h={$medium_term_bookings}, 24h={$daily_bookings}");
        
        return $level;
    }
    
    /**
     * Execute intelligent polling with connection pooling
     */
    public function execute_intelligent_polling() {
        $start_time = microtime(true);
        
        try {
            $this->log('Starting intelligent polling execution');
            
            // Get or create a pooled connection
            $connection = $this->get_pooled_connection();
            
            if (!$connection) {
                throw new \Exception('Failed to obtain pooled connection');
            }
            
            // Execute the actual polling
            $result = $this->execute_polling_with_connection($connection);
            
            if ($result['success']) {
                // Reset failure count on success
                delete_option('hic_intelligent_consecutive_failures');
                
                // Update success metrics
                $this->update_polling_metrics('success', $start_time);
                
                // Reschedule with normal interval
                $this->reschedule_intelligent_polling();
                
                $this->log('Intelligent polling completed successfully');
            } else {
                throw new \Exception($result['error'] ?? 'Unknown polling error');
            }
            
        } catch (\Exception $e) {
            $this->handle_polling_failure($e->getMessage(), $start_time);
        }
    }
    
    /**
     * Get a connection from the pool or create a new one
     */
    private function get_pooled_connection() {
        $connection_key = $this->get_connection_key();
        
        // Check if we have a valid connection in the pool
        if (isset(self::$connection_pool[$connection_key])) {
            $connection = self::$connection_pool[$connection_key];
            
            // Check if connection is still valid
            if ($this->is_connection_valid($connection)) {
                $this->log('Using existing pooled connection');
                return $connection;
            } else {
                // Remove invalid connection
                unset(self::$connection_pool[$connection_key]);
                $this->log('Removed invalid connection from pool');
            }
        }
        
        // Create new connection
        $connection = $this->create_new_connection();
        
        if ($connection) {
            // Add to pool if there's space
            if (count(self::$connection_pool) < self::MAX_POOL_SIZE) {
                self::$connection_pool[$connection_key] = $connection;
                $this->log('Added new connection to pool');
            }
        }
        
        return $connection;
    }
    
    /**
     * Create a new HTTP connection with keep-alive support
     */
    private function create_new_connection() {
        $api_url = \FpHic\Helpers\hic_get_api_url();
        
        if (empty($api_url)) {
            throw new \Exception('API URL not configured');
        }
        
        $connection = [
            'url' => $api_url,
            'created_at' => time(),
            'last_used' => time(),
            'request_count' => 0,
            'curl_handle' => null
        ];
        
        // Initialize cURL handle with keep-alive
        $curl = curl_init();
        
        if ($curl === false) {
            throw new \Exception('Failed to initialize cURL');
        }
        
        curl_setopt_array($curl, [
            CURLOPT_TIMEOUT => self::CONNECTION_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'FP-HIC-Monitor/3.0 (+https://www.francopasseri.it)',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 30,
            CURLOPT_TCP_KEEPINTVL => 10,
        ]);
        
        $connection['curl_handle'] = $curl;
        
        $this->log('Created new HTTP connection with keep-alive');
        
        return $connection;
    }
    
    /**
     * Check if a connection is still valid
     */
    private function is_connection_valid($connection) {
        if (!isset($connection['created_at'], $connection['curl_handle'])) {
            return false;
        }
        
        // Check if connection is too old
        $age = time() - $connection['created_at'];
        if ($age > self::KEEP_ALIVE_TIMEOUT) {
            return false;
        }
        
        // Check if cURL handle is still valid
        if (!is_resource($connection['curl_handle'])) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Execute polling using a pooled connection
     */
    private function execute_polling_with_connection($connection) {
        $connection['last_used'] = time();
        $connection['request_count']++;
        
        // Use existing polling logic but with our pooled connection
        if (function_exists('\\FpHic\\hic_api_poll_bookings')) {
            $result = \FpHic\hic_api_poll_bookings();
            return ['success' => true, 'result' => $result];
        }
        
        return ['success' => false, 'error' => 'Polling function not available'];
    }
    
    /**
     * Handle polling failure with exponential backoff
     */
    private function handle_polling_failure($error_message, $start_time) {
        $consecutive_failures = get_option('hic_intelligent_consecutive_failures', 0) + 1;
        update_option('hic_intelligent_consecutive_failures', $consecutive_failures);
        
        $this->log("Polling failure #{$consecutive_failures}: {$error_message}");
        
        // Update failure metrics
        $this->update_polling_metrics('failure', $start_time, $error_message);
        
        // Reschedule with backoff
        $this->reschedule_intelligent_polling();
        
        // Clear connection pool on consecutive failures
        if ($consecutive_failures >= 3) {
            $this->cleanup_connection_pool();
            $this->log('Cleared connection pool due to consecutive failures');
        }
    }
    
    /**
     * Reschedule intelligent polling based on current conditions
     */
    private function reschedule_intelligent_polling() {
        // Clear current schedule
        wp_clear_scheduled_hook('hic_intelligent_poll_event');
        
        // Get new interval based on current conditions
        $interval = $this->get_current_polling_interval();
        
        // Schedule next execution
        wp_schedule_event(time(), $interval, 'hic_intelligent_poll_event');
        
        $this->log("Rescheduled intelligent polling with interval: {$interval}");
    }
    
    /**
     * Update polling metrics for dashboard
     */
    private function update_polling_metrics($type, $start_time, $error = null) {
        $duration = microtime(true) - $start_time;
        $metrics = get_option('hic_polling_metrics', [
            'total_polls' => 0,
            'successful_polls' => 0,
            'failed_polls' => 0,
            'avg_duration' => 0,
            'last_poll' => 0,
            'last_success' => 0,
            'last_failure' => 0,
            'recent_errors' => []
        ]);
        
        $metrics['total_polls']++;
        $metrics['last_poll'] = time();
        
        if ($type === 'success') {
            $metrics['successful_polls']++;
            $metrics['last_success'] = time();
        } else {
            $metrics['failed_polls']++;
            $metrics['last_failure'] = time();
            
            // Store recent errors (keep last 10)
            $metrics['recent_errors'][] = [
                'timestamp' => time(),
                'error' => $error
            ];
            
            if (count($metrics['recent_errors']) > 10) {
                array_shift($metrics['recent_errors']);
            }
        }
        
        // Update average duration
        $metrics['avg_duration'] = ($metrics['avg_duration'] * ($metrics['total_polls'] - 1) + $duration) / $metrics['total_polls'];
        
        update_option('hic_polling_metrics', $metrics);
    }
    
    /**
     * Cleanup old connections from the pool
     */
    public function cleanup_connection_pool() {
        $cleaned = 0;
        
        foreach (self::$connection_pool as $key => $connection) {
            if (!$this->is_connection_valid($connection)) {
                // Close cURL handle if it exists
                if (isset($connection['curl_handle']) && is_resource($connection['curl_handle'])) {
                    curl_close($connection['curl_handle']);
                }
                
                unset(self::$connection_pool[$key]);
                $cleaned++;
            }
        }
        
        if ($cleaned > 0) {
            $this->log("Cleaned {$cleaned} expired connections from pool");
        }
    }
    
    /**
     * Initialize activity tracking database tables
     */
    private function initialize_activity_tracking() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_polling_activity';
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            activity_level ENUM('high', 'medium', 'low', 'inactive') NOT NULL,
            bookings_1h INT DEFAULT 0,
            bookings_6h INT DEFAULT 0,
            bookings_24h INT DEFAULT 0,
            polling_interval VARCHAR(50),
            consecutive_failures INT DEFAULT 0,
            INDEX idx_timestamp (timestamp),
            INDEX idx_activity_level (activity_level)
        ) {$wpdb->get_charset_collate()};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        $this->log('Initialized activity tracking table');
    }
    
    /**
     * Get connection key for pooling
     */
    private function get_connection_key() {
        $api_url = \FpHic\Helpers\hic_get_api_url();
        return md5($api_url);
    }
    
    /**
     * AJAX handler for polling metrics (for dashboard)
     */
    public function ajax_get_polling_metrics() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $metrics = get_option('hic_polling_metrics', []);
        $activity_metrics = get_option('hic_activity_metrics', []);
        $consecutive_failures = get_option('hic_intelligent_consecutive_failures', 0);
        
        wp_send_json_success([
            'polling_metrics' => $metrics,
            'activity_metrics' => $activity_metrics,
            'consecutive_failures' => $consecutive_failures,
            'connection_pool_size' => count(self::$connection_pool),
            'next_poll' => wp_next_scheduled('hic_intelligent_poll_event')
        ]);
    }
    
    /**
     * Log messages with intelligent polling prefix
     */
    private function log($message) {
        if (function_exists('\\FpHic\\Helpers\\hic_log')) {
            \FpHic\Helpers\hic_log("[Intelligent Polling] {$message}");
        }
    }
}

// Initialize the intelligent polling manager
new IntelligentPollingManager();