<?php
/**
 * WP-CLI commands for HIC Plugin
 */

if (!defined('ABSPATH')) exit;

// Check if WP-CLI is available
if (defined('WP_CLI') && WP_CLI) {
    
    /**
     * HIC Plugin CLI Commands
     */
    class HIC_CLI_Commands {
        
        /**
         * Force manual polling execution
         * 
         * ## EXAMPLES
         * 
         *     wp hic poll
         *     wp hic poll --force
         * 
         * @param array $args
         * @param array $assoc_args
         */
        public function poll($args, $assoc_args) {
            $force = isset($assoc_args['force']) && $assoc_args['force'];
            
            if (!class_exists('HIC_Booking_Poller')) {
                WP_CLI::error('HIC_Booking_Poller class not found');
                return;
            }
            
            $poller = new HIC_Booking_Poller();
            
            WP_CLI::log('Starting manual polling execution...');
            
            if ($force) {
                WP_CLI::log('Force mode: bypassing lock check');
                // Create a temporary method to bypass lock for manual execution
                $this->force_poll_execution($poller);
            } else {
                // Use normal execution method
                $poller->execute_poll();
            }
            
            WP_CLI::success('Polling execution completed');
            
            // Show stats
            $stats = $poller->get_stats();
            $this->display_stats($stats);
        }
        
        /**
         * Show polling statistics
         * 
         * ## EXAMPLES
         * 
         *     wp hic stats
         * 
         * @param array $args
         * @param array $assoc_args
         */
        public function stats($args, $assoc_args) {
            if (!class_exists('HIC_Booking_Poller')) {
                WP_CLI::error('HIC_Booking_Poller class not found');
                return;
            }
            
            $poller = new HIC_Booking_Poller();
            $stats = $poller->get_stats();
            
            $this->display_stats($stats);
        }
        
        /**
         * Reset polling state (clear locks, timestamps)
         * 
         * ## EXAMPLES
         * 
         *     wp hic reset
         *     wp hic reset --confirm
         * 
         * @param array $args
         * @param array $assoc_args
         */
        public function reset($args, $assoc_args) {
            $confirm = isset($assoc_args['confirm']) && $assoc_args['confirm'];
            
            if (!$confirm) {
                WP_CLI::warning('This will reset polling state (locks, timestamps). Use --confirm to proceed.');
                return;
            }
            
            // Clear lock
            delete_transient('hic_reliable_polling_lock');
            
            // Reset timestamp
            delete_option('hic_last_reliable_poll');
            
            // Clear scheduled events
            wp_clear_scheduled_hook('hic_reliable_poll_event');
            
            WP_CLI::success('Polling state reset successfully');
        }
        
        /**
         * Show queue table contents
         * 
         * ## EXAMPLES
         * 
         *     wp hic queue
         *     wp hic queue --limit=10
         *     wp hic queue --status=pending
         * 
         * @param array $args
         * @param array $assoc_args
         */
        public function queue($args, $assoc_args) {
            global $wpdb;
            
            $table = $wpdb->prefix . 'hic_booking_events';
            
            // Check if table exists
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
                WP_CLI::error('Queue table not found');
                return;
            }
            
            $limit = isset($assoc_args['limit']) ? intval($assoc_args['limit']) : 20;
            $status = isset($assoc_args['status']) ? $assoc_args['status'] : null;
            
            $where = '';
            if ($status === 'pending') {
                $where = 'WHERE processed = 0';
            } elseif ($status === 'processed') {
                $where = 'WHERE processed = 1';
            } elseif ($status === 'error') {
                $where = 'WHERE last_error IS NOT NULL';
            }
            
            $sql = "SELECT id, booking_id, processed, poll_timestamp, processed_at, process_attempts, last_error 
                    FROM {$table} {$where} ORDER BY poll_timestamp DESC LIMIT {$limit}";
            
            $results = $wpdb->get_results($sql, ARRAY_A);
            
            if (empty($results)) {
                WP_CLI::log('No events found in queue');
                return;
            }
            
            WP_CLI\Utils\format_items('table', $results, array(
                'id', 'booking_id', 'processed', 'poll_timestamp', 'processed_at', 'process_attempts', 'last_error'
            ));
        }
        
        /**
         * Force poll execution bypassing lock
         */
        private function force_poll_execution($poller) {
            // Use reflection to call private methods for force execution
            $reflection = new ReflectionClass($poller);
            
            // Get private methods
            $perform_polling = $reflection->getMethod('perform_polling');
            $perform_polling->setAccessible(true);
            
            $log_structured = $reflection->getMethod('log_structured');
            $log_structured->setAccessible(true);
            
            $start_time = microtime(true);
            
            try {
                WP_CLI::log('Performing polling (force mode)...');
                
                $stats = $perform_polling->invoke($poller);
                
                // Update last poll timestamp
                update_option('hic_last_reliable_poll', time());
                
                $execution_time = round(microtime(true) - $start_time, 2);
                
                $log_structured->invoke($poller, 'poll_completed_manual', array_merge($stats, array(
                    'execution_time' => $execution_time,
                    'manual_force' => true
                )));
                
                WP_CLI::success("Polling completed in {$execution_time}s");
                $this->display_stats($stats);
                
            } catch (Exception $e) {
                $log_structured->invoke($poller, 'poll_error_manual', array(
                    'error' => $e->getMessage(),
                    'manual_force' => true
                ));
                
                WP_CLI::error('Polling failed: ' . $e->getMessage());
            }
        }
        
        /**
         * Display statistics in a nice format
         */
        private function display_stats($stats) {
            if (isset($stats['error'])) {
                WP_CLI::warning('Stats error: ' . $stats['error']);
                return;
            }
            
            WP_CLI::log('');
            WP_CLI::log('=== Polling Statistics ===');
            
            if (isset($stats['total_events'])) {
                WP_CLI::log("Total events: {$stats['total_events']}");
                WP_CLI::log("Processed: {$stats['processed_events']}");
                WP_CLI::log("Pending: {$stats['pending_events']}");
                WP_CLI::log("Errors: {$stats['error_events']}");
                WP_CLI::log("24h activity: {$stats['events_24h']}");
            }
            
            if (isset($stats['last_poll']) && $stats['last_poll'] > 0) {
                WP_CLI::log("Last poll: " . date('Y-m-d H:i:s', $stats['last_poll']) . " ({$stats['last_poll_human']})");
                WP_CLI::log("Lag: {$stats['lag_seconds']} seconds");
            }
            
            if (isset($stats['lock_active'])) {
                $lock_status = $stats['lock_active'] ? 'Active' : 'Free';
                WP_CLI::log("Lock status: {$lock_status}");
                if ($stats['lock_active'] && isset($stats['lock_age'])) {
                    WP_CLI::log("Lock age: {$stats['lock_age']} seconds");
                }
            }
            
            // Show recent stats if available (from last poll)
            if (isset($stats['reservations_fetched'])) {
                WP_CLI::log('');
                WP_CLI::log('=== Last Poll Results ===');
                WP_CLI::log("Fetched: {$stats['reservations_fetched']}");
                WP_CLI::log("New: {$stats['reservations_new']}");
                WP_CLI::log("Duplicates: {$stats['reservations_duplicate']}");
                WP_CLI::log("Processed: {$stats['reservations_processed']}");
                WP_CLI::log("Errors: {$stats['reservations_errors']}");
                WP_CLI::log("API calls: {$stats['api_calls']}");
            }
        }
    }
    
    // Register CLI commands
    WP_CLI::add_command('hic', 'HIC_CLI_Commands');
}