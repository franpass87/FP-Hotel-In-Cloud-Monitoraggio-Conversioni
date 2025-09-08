<?php declare(strict_types=1);
/**
 * Performance Monitoring System for HIC Plugin
 * 
 * Tracks performance metrics, API response times, and system efficiency.
 */

if (!defined('ABSPATH')) exit;

class HIC_Performance_Monitor {
    
    private $timers = [];
    private $metrics = [];
    
    public function __construct() {
        // Ensure WordPress functions are available
        if (!function_exists('add_action')) {
            return;
        }
        
        // Hook into WordPress for tracking
        add_action('init', [$this, 'init_daily_metrics']);
        add_action('shutdown', [$this, 'save_metrics']);
        
        // Register AJAX endpoints
        add_action('wp_ajax_hic_performance_metrics', [$this, 'ajax_get_metrics']);
        
        // Schedule daily cleanup
        add_action('hic_performance_cleanup', [$this, 'cleanup_old_metrics']);
        $this->schedule_cleanup();
    }
    
    /**
     * Start timing an operation
     */
    public function start_timer($operation) {
        $this->timers[$operation] = [
            'start' => microtime(true),
            'memory_start' => memory_get_usage(true)
        ];
    }
    
    /**
     * End timing and record metric
     */
    public function end_timer($operation, $additional_data = []) {
        if (!isset($this->timers[$operation])) {
            return false;
        }
        
        $timer = $this->timers[$operation];
        $end_time = microtime(true);
        $end_memory = memory_get_usage(true);
        
        $duration = $end_time - $timer['start'];
        $memory_used = $end_memory - $timer['memory_start'];
        
        $metric = [
            'operation' => $operation,
            'duration' => $duration,
            'memory_used' => $memory_used,
            'timestamp' => current_time('timestamp'),
            'date' => wp_date('Y-m-d'),
            'additional_data' => $additional_data
        ];
        
        $this->record_metric($metric);
        unset($this->timers[$operation]);
        
        return $metric;
    }
    
    /**
     * Record a performance metric
     */
    public function record_metric($metric) {
        $today = wp_date('Y-m-d');
        $metrics_key = 'hic_performance_metrics_' . $today;
        
        $today_metrics = get_option($metrics_key, []);
        $today_metrics[] = $metric;
        
        // Limit daily metrics to prevent excessive storage
        if (count($today_metrics) > 1000) {
            $today_metrics = array_slice($today_metrics, -1000);
        }
        
        update_option($metrics_key, $today_metrics, false);
        
        // Update running averages
        $this->update_running_averages($metric);
    }
    
    /**
     * Update running averages
     */
    private function update_running_averages($metric) {
        $operation = $metric['operation'];
        $averages = get_option('hic_performance_averages', []);
        
        if (!isset($averages[$operation])) {
            $averages[$operation] = [
                'count' => 0,
                'total_duration' => 0,
                'total_memory' => 0,
                'avg_duration' => 0,
                'avg_memory' => 0,
                'min_duration' => $metric['duration'],
                'max_duration' => $metric['duration'],
                'last_updated' => current_time('timestamp')
            ];
        }
        
        $avg = &$averages[$operation];
        $avg['count']++;
        $avg['total_duration'] += $metric['duration'];
        $avg['total_memory'] += $metric['memory_used'];
        $avg['avg_duration'] = $avg['total_duration'] / $avg['count'];
        $avg['avg_memory'] = $avg['total_memory'] / $avg['count'];
        $avg['min_duration'] = min($avg['min_duration'], $metric['duration']);
        $avg['max_duration'] = max($avg['max_duration'], $metric['duration']);
        $avg['last_updated'] = current_time('timestamp');
        
        update_option('hic_performance_averages', $averages, false);
        \FpHic\Helpers\hic_clear_option_cache('hic_performance_averages');
    }
    
    /**
     * Get performance metrics for a date range
     */
    public function get_metrics($start_date = null, $end_date = null, $operation = null) {
        if (!$start_date) {
            $start_date = wp_date('Y-m-d', strtotime('-7 days', current_time('timestamp')));
        }
        if (!$end_date) {
            $end_date = wp_date('Y-m-d');
        }
        
        $metrics = [];
        $current_date = $start_date;
        
        while ($current_date <= $end_date) {
            $metrics_key = 'hic_performance_metrics_' . $current_date;
            $day_metrics = get_option($metrics_key, []);
            
            if ($operation) {
                $day_metrics = array_filter($day_metrics, function($metric) use ($operation) {
                    return $metric['operation'] === $operation;
                });
            }
            
            $metrics = array_merge($metrics, $day_metrics);
            $current_date = wp_date('Y-m-d', strtotime($current_date . ' +1 day'));
        }
        
        return $metrics;
    }
    
    /**
     * Get performance summary
     */
    public function get_performance_summary($days = 7) {
        $start_date = wp_date('Y-m-d', strtotime("-{$days} days", current_time('timestamp')));
        $metrics = $this->get_metrics($start_date);
        
        $summary = [
            'period' => "{$days} days",
            'total_operations' => count($metrics),
            'operations' => [],
            'daily_stats' => []
        ];
        
        // Group by operation
        $operations = [];
        $daily_ops = [];
        
        foreach ($metrics as $metric) {
            $op = $metric['operation'];
            $date = $metric['date'];
            
            if (!isset($operations[$op])) {
                $operations[$op] = [
                    'count' => 0,
                    'total_duration' => 0,
                    'total_memory' => 0,
                    'durations' => [],
                    'memories' => []
                ];
            }
            
            $operations[$op]['count']++;
            $operations[$op]['total_duration'] += $metric['duration'];
            $operations[$op]['total_memory'] += $metric['memory_used'];
            $operations[$op]['durations'][] = $metric['duration'];
            $operations[$op]['memories'][] = $metric['memory_used'];
            
            // Daily stats
            if (!isset($daily_ops[$date])) {
                $daily_ops[$date] = 0;
            }
            $daily_ops[$date]++;
        }
        
        // Calculate operation statistics
        foreach ($operations as $op => $data) {
            $durations = $data['durations'];
            $memories = $data['memories'];
            
            sort($durations);
            sort($memories);
            
            $count = $data['count'];
            $summary['operations'][$op] = [
                'count' => $count,
                'avg_duration' => $data['total_duration'] / $count,
                'avg_memory' => $data['total_memory'] / $count,
                'min_duration' => min($durations),
                'max_duration' => max($durations),
                'median_duration' => $this->get_median($durations),
                'p95_duration' => $this->get_percentile($durations, 95),
                'min_memory' => min($memories),
                'max_memory' => max($memories),
                'median_memory' => $this->get_median($memories)
            ];
        }
        
        $summary['daily_stats'] = $daily_ops;
        
        return $summary;
    }
    
    /**
     * Get median value from array
     */
    private function get_median($array) {
        $count = count($array);
        if ($count === 0) return 0;
        
        $middle = floor($count / 2);
        
        if ($count % 2 === 0) {
            return ($array[$middle - 1] + $array[$middle]) / 2;
        } else {
            return $array[$middle];
        }
    }
    
    /**
     * Get percentile value from array
     */
    private function get_percentile($array, $percentile) {
        $count = count($array);
        if ($count === 0) return 0;
        
        $index = ceil($count * $percentile / 100) - 1;
        return $array[max(0, min($index, $count - 1))];
    }
    
    /**
     * Track API call performance
     */
    public function track_api_call($endpoint, $duration, $success, $response_size = 0) {
        $this->record_metric([
            'operation' => 'api_call',
            'duration' => $duration,
            'memory_used' => 0,
            'timestamp' => current_time('timestamp'),
            'date' => wp_date('Y-m-d'),
            'additional_data' => [
                'endpoint' => $endpoint,
                'success' => $success,
                'response_size' => $response_size
            ]
        ]);
        
        // Update daily API stats
        $this->update_daily_api_stats($success);
    }
    
    /**
     * Update daily API statistics
     */
    private function update_daily_api_stats($success) {
        $today = wp_date('Y-m-d');
        $stats_key = 'hic_api_stats_' . $today;
        $stats = get_option($stats_key, ['total' => 0, 'success' => 0, 'failed' => 0]);
        
        $stats['total']++;
        if ($success) {
            $stats['success']++;
        } else {
            $stats['failed']++;
        }
        
        update_option($stats_key, $stats, false);
    }
    
    /**
     * Get API performance statistics
     */
    public function get_api_stats($days = 7) {
        $stats = [];
        
        for ($i = 0; $i < $days; $i++) {
            $date = wp_date('Y-m-d', strtotime("-{$i} days", current_time('timestamp')));
            $stats_key = 'hic_api_stats_' . $date;
            $day_stats = get_option($stats_key, ['total' => 0, 'success' => 0, 'failed' => 0]);
            $stats[$date] = $day_stats;
        }
        
        return $stats;
    }
    
    /**
     * Track booking processing performance
     */
    public function track_booking_processing($booking_id, $duration, $success, $integrations_sent = []) {
        $this->record_metric([
            'operation' => 'booking_processing',
            'duration' => $duration,
            'memory_used' => 0,
            'timestamp' => current_time('timestamp'),
            'date' => wp_date('Y-m-d'),
            'additional_data' => [
                'booking_id' => $booking_id,
                'success' => $success,
                'integrations_sent' => $integrations_sent
            ]
        ]);
    }
    
    /**
     * Get system resource usage
     */
    public function get_system_resources() {
        return [
            'memory_usage' => [
                'current' => memory_get_usage(true),
                'current_mb' => round(memory_get_usage(true) / 1048576, 2),
                'peak' => memory_get_peak_usage(true),
                'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                'limit' => ini_get('memory_limit')
            ],
            'execution_time' => [
                'limit' => ini_get('max_execution_time'),
                'used' => $this->get_execution_time()
            ],
            'disk_usage' => $this->get_disk_usage()
        ];
    }
    
    /**
     * Get execution time used
     */
    private function get_execution_time() {
        if (defined('REQUEST_TIME_FLOAT')) {
            return microtime(true) - REQUEST_TIME_FLOAT;
        }
        return microtime(true) - $_SERVER['REQUEST_TIME'];
    }
    
    /**
     * Get disk usage information
     */
    private function get_disk_usage() {
        $upload_dir = wp_upload_dir();
        $disk_usage = [];
        
        if (function_exists('disk_free_space') && function_exists('disk_total_space')) {
            $path = $upload_dir['basedir'];
            $free = disk_free_space($path);
            $total = disk_total_space($path);
            
            $disk_usage = [
                'free_bytes' => $free,
                'free_mb' => round($free / 1048576, 2),
                'total_bytes' => $total,
                'total_mb' => round($total / 1048576, 2),
                'used_percent' => round((($total - $free) / $total) * 100, 1)
            ];
        }
        
        return $disk_usage;
    }
    
    /**
     * Initialize daily metrics
     */
    public function init_daily_metrics() {
        $today = wp_date('Y-m-d');
        $last_init = get_option('hic_metrics_last_init');
        
        if ($last_init !== $today) {
            update_option('hic_metrics_last_init', $today, false);
            \FpHic\Helpers\hic_clear_option_cache('hic_metrics_last_init');

            // Reset daily counters
            update_option('hic_api_calls_today', 0, false);
            \FpHic\Helpers\hic_clear_option_cache('hic_api_calls_today');
            update_option('hic_successful_bookings_today', 0, false);
            \FpHic\Helpers\hic_clear_option_cache('hic_successful_bookings_today');
            update_option('hic_failed_bookings_today', 0, false);
            \FpHic\Helpers\hic_clear_option_cache('hic_failed_bookings_today');
        }
    }
    
    /**
     * Save metrics on shutdown
     */
    public function save_metrics() {
        // This method can be used to save any pending metrics
        // Currently handled in real-time, but kept for future use
    }
    
    /**
     * Clean up old metrics
     */
    public function cleanup_old_metrics() {
        $cutoff_date = wp_date('Y-m-d', strtotime('-30 days', current_time('timestamp')));
        
        global $wpdb;
        
        // Clean up daily metrics
        $options_to_check = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name FROM " . esc_sql($wpdb->options) . " 
             WHERE option_name LIKE %s 
             OR option_name LIKE %s",
            'hic_performance_metrics_%',
            'hic_api_stats_%'
        ));
        
        foreach ($options_to_check as $option) {
            if (preg_match('/_(\\d{4}-\\d{2}-\\d{2})$/', $option->option_name, $matches)) {
                if ($matches[1] < $cutoff_date) {
                    delete_option($option->option_name);
                }
            }
        }
    }
    
    /**
     * Schedule cleanup
     */
    private function schedule_cleanup() {
        // Use safe WordPress cron functions
        if (!\FpHic\Helpers\hic_safe_wp_next_scheduled('hic_performance_cleanup')) {
            \FpHic\Helpers\hic_safe_wp_schedule_event(time(), 'daily', 'hic_performance_cleanup');
        }
    }
    
    /**
     * AJAX handler for getting metrics
     */
    public function ajax_get_metrics() {
        if (!check_ajax_referer('hic_monitor_nonce', 'nonce', false)) {
            wp_send_json(['error' => 'Invalid nonce'], 403);
        }

        if (!current_user_can('hic_manage')) {
            wp_send_json(['error' => 'Insufficient permissions'], 403);
        }

        $type = sanitize_text_field( wp_unslash( $_GET['type'] ?? 'summary' ) );
        $days = absint( wp_unslash( $_GET['days'] ?? 7 ) );

        switch ($type) {
            case 'summary':
                $data = $this->get_performance_summary($days);
                break;
            case 'api':
                $data = $this->get_api_stats($days);
                break;
            case 'resources':
                $data = $this->get_system_resources();
                break;
            default:
                $data = ['error' => 'Invalid type'];
        }

        wp_send_json($data);
    }
    
    /**
     * Get current performance status
     */
    public function get_current_status() {
        $averages = get_option('hic_performance_averages', []);
        $resources = $this->get_system_resources();
        
        return [
            'averages' => $averages,
            'resources' => $resources,
            'timestamp' => current_time('mysql')
        ];
    }
}

/**
 * Get or create global HIC_Performance_Monitor instance
 */
function hic_get_performance_monitor() {
    if (!isset($GLOBALS['hic_performance_monitor']) && HIC_FEATURE_PERFORMANCE_METRICS) {
        // Only instantiate if WordPress is loaded and functions are available
        if (function_exists('get_option') && function_exists('add_action')) {
            $GLOBALS['hic_performance_monitor'] = new HIC_Performance_Monitor();
        }
    }
    return isset($GLOBALS['hic_performance_monitor']) ? $GLOBALS['hic_performance_monitor'] : null;
}