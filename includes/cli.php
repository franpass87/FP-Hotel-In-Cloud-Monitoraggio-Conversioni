<?php declare(strict_types=1);
use function FpHic\hic_process_booking_data;
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
            Helpers\hic_safe_wp_clear_scheduled_hook('hic_reliable_poll_event');
            
            WP_CLI::success('Polling state reset successfully');
        }

        /**
         * Run cleanup routines.
         *
         * ## OPTIONS
         *
         * [--logs]            Clean up old log files
         * [--gclids]          Remove expired tracking identifiers
         * [--booking-events]  Remove processed booking events
         *
         * ## EXAMPLES
         *
         *     wp hic cleanup --logs
         *     wp hic cleanup --gclids --booking-events
         *
         * @param array $args
         * @param array $assoc_args
         */
        public function cleanup($args, $assoc_args) {
            $performed = false;

            if (isset($assoc_args['logs'])) {
                $performed = true;
                $log_manager = function_exists('hic_get_log_manager') ? hic_get_log_manager() : null;
                if ($log_manager) {
                    $log_manager->cleanup_old_logs();
                    WP_CLI::success('Logs cleanup completed');
                } else {
                    WP_CLI::warning('Log manager not available');
                }
            }

            if (isset($assoc_args['gclids'])) {
                $performed = true;
                if (function_exists('hic_cleanup_old_gclids')) {
                    $deleted = hic_cleanup_old_gclids();
                    WP_CLI::success("GCLIDs cleanup completed ({$deleted} removed)");
                } else {
                    WP_CLI::warning('hic_cleanup_old_gclids function not found');
                }
            }

            if (isset($assoc_args['booking-events'])) {
                $performed = true;
                if (function_exists('hic_cleanup_booking_events')) {
                    $deleted = hic_cleanup_booking_events();
                    WP_CLI::success("Booking events cleanup completed ({$deleted} removed)");
                } else {
                    WP_CLI::warning('hic_cleanup_booking_events function not found');
                }
            }

            if (!$performed) {
                WP_CLI::warning('Specify at least one cleanup target: --logs, --gclids, or --booking-events');
            }
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
            $status = isset($assoc_args['status']) ? sanitize_text_field($assoc_args['status']) : null;
            
            // Build secure query with prepared statements
            $base_query = "SELECT id, booking_id, processed, poll_timestamp, processed_at, process_attempts, last_error 
                          FROM " . esc_sql($table);
            
            $where_clause = '';
            $query_params = array();
            
            if ($status === 'pending') {
                $where_clause = ' WHERE processed = %d';
                $query_params[] = 0;
            } elseif ($status === 'processed') {
                $where_clause = ' WHERE processed = %d';
                $query_params[] = 1;
            } elseif ($status === 'error') {
                $where_clause = ' WHERE last_error IS NOT NULL';
            }
            
            $order_limit = ' ORDER BY poll_timestamp DESC LIMIT %d';
            $query_params[] = $limit;
            
            $sql = $base_query . $where_clause . $order_limit;
            
            if (!empty($query_params)) {
                $prepared_sql = $wpdb->prepare($sql, $query_params);
                $results = $wpdb->get_results($prepared_sql, ARRAY_A);
            } else {
                // For the case with no WHERE clause, we still need to prepare the LIMIT
                $prepared_sql = $wpdb->prepare($sql, $limit);
                $results = $wpdb->get_results($prepared_sql, ARRAY_A);
            }
            
            if (empty($results)) {
                WP_CLI::log('No events found in queue');
                return;
            }
            
            WP_CLI\Utils\format_items('table', $results, array(
                'id', 'booking_id', 'processed', 'poll_timestamp', 'processed_at', 'process_attempts', 'last_error'
            ));
        }

        /**
         * Resend tracking for a specific reservation
         *
         * ## OPTIONS
         *
         * <reservation_id>
         * : ID della prenotazione da reinviare
         *
         * [--sid=<sid>]
         * : SID opzionale salvato nei cookie per recuperare i tracciamenti
         *
         * ## EXAMPLES
         *
         *     wp hic resend 12345
         *     wp hic resend 12345 --sid=abc123
         *
         * @param array $args
         * @param array $assoc_args
         */
        public function resend($args, $assoc_args) {
            if (empty($args[0])) {
                WP_CLI::error('Reservation ID required');
                return;
            }

            if (!function_exists('\\FpHic\\hic_process_booking_data')) {
                WP_CLI::error('\\FpHic\\hic_process_booking_data function not found');
                return;
            }

            $reservation_id = sanitize_text_field($args[0]);
            $sid = isset($assoc_args['sid']) ? sanitize_text_field($assoc_args['sid']) : null;

            $email = function_exists('hic_get_reservation_email') ? hic_get_reservation_email($reservation_id) : null;
            if (empty($email)) {
                WP_CLI::error('Email not found for reservation ' . $reservation_id);
                return;
            }

            $data = array(
                'reservation_id' => $reservation_id,
                'email' => $email,
            );

            if ($sid) {
                $data['sid'] = $sid;
            }

            WP_CLI::log('Resending reservation ' . $reservation_id . '...');
            $result = \FpHic\hic_process_booking_data($data);

            if ($result !== false) {
                WP_CLI::success('Reservation resent successfully');
            } else {
                WP_CLI::error('Failed to resend reservation');
            }
        }

        /**
         * Validate plugin configuration
         *
         * ## EXAMPLES
         *
         *     wp hic validate-config
         *
         * @param array $args
         * @param array $assoc_args
         */
        public function validate_config($args, $assoc_args) {
            $validator = function_exists('hic_get_config_validator') ? hic_get_config_validator() : null;
            if (!$validator) {
                WP_CLI::error('Config validator not available');
                return;
            }

            $result = $validator->validate_all_config();

            foreach ($result['warnings'] as $warning) {
                WP_CLI::warning($warning);
            }

            foreach ($result['errors'] as $error) {
                WP_CLI::error($error, false);
            }

            if (!empty($result['errors'])) {
                WP_CLI::halt(1);
            }

            if (!empty($result['warnings'])) {
                WP_CLI::success('Configuration valid with warnings');
            } else {
                WP_CLI::success('Configuration valid');
            }
        }

        /**
         * Force poll execution bypassing lock using public poller methods
         */
        private function force_poll_execution($poller) {
            $start_time = microtime(true);

            try {
                WP_CLI::log('Performing polling (force mode)...');

                // Directly call the public polling method
                $stats = $poller->perform_polling();

                // Update last poll timestamp
                update_option('hic_last_reliable_poll', current_time('timestamp'));
                \FpHic\Helpers\hic_clear_option_cache('hic_last_reliable_poll');

                $execution_time = round(microtime(true) - $start_time, 2);

                // Log structured event using the public wrapper
                $poller->log_structured('poll_completed_manual', array_merge($stats, array(
                    'execution_time' => $execution_time,
                    'manual_force' => true
                )));

                WP_CLI::success("Polling completed in {$execution_time}s");
                $this->display_stats($stats);

            } catch (Exception $e) {
                $poller->log_structured('poll_error_manual', array(
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
                WP_CLI::log("Last poll: " . wp_date('Y-m-d H:i:s', $stats['last_poll']) . " ({$stats['last_poll_human']})");
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