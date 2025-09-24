<?php declare(strict_types=1);

use FpHic\HIC_Booking_Poller;
use function FpHic\hic_process_booking_data;
/**
 * WP-CLI commands for HIC Plugin
 */

if (!defined('ABSPATH')) exit;

// Check if WP-CLI is available
if ((defined('WP_CLI') && WP_CLI) || (defined('HIC_FORCE_CLI_LOADER') && HIC_FORCE_CLI_LOADER)) {
    
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
            
            if (!class_exists(HIC_Booking_Poller::class)) {
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
            if (!class_exists(HIC_Booking_Poller::class)) {
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
            \FpHic\Helpers\hic_safe_wp_clear_scheduled_hook('hic_reliable_poll_event');
            
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

            if (!is_array($result)) {
                $result = [
                    'status' => $result ? 'success' : 'failed',
                    'messages' => [],
                ];
            }

            $status = isset($result['status']) ? (string) $result['status'] : 'failed';
            $failed_integrations = $result['failed_integrations'] ?? [];
            $messages = $result['messages'] ?? [];

            if ($status === 'success') {
                WP_CLI::success('Reservation resent successfully');
            } elseif ($status === 'partial') {
                WP_CLI::success('Reservation resent with partial success');
                if (!empty($failed_integrations)) {
                    WP_CLI::warning('Failed integrations: ' . implode(', ', $failed_integrations));
                }
                if (!empty($messages)) {
                    WP_CLI::log('Messages: ' . implode(', ', $messages));
                }
            } else {
                $error_message = 'Failed to resend reservation';
                if (!empty($messages)) {
                    $error_message .= ' (' . implode(', ', $messages) . ')';
                }
                if (!empty($failed_integrations)) {
                    $error_message .= ' - Failed integrations: ' . implode(', ', $failed_integrations);
                }
                WP_CLI::error($error_message);
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
         * Execute the plugin health check and display diagnostics.
         *
         * ## OPTIONS
         *
         * [--level=<level>]
         * : Livello di diagnostica (basic, detailed, full). Default: basic.
         *
         * [--format=<format>]
         * : Formato di output (table, json). Default: table.
         *
         * [--details]
         * : Mostra i dettagli completi dei controlli.
         *
         * [--metrics]
         * : Includi le metriche disponibili nel report.
         *
         * ## EXAMPLES
         *
         *     wp hic health
         *     wp hic health --level=full --details --metrics
         *     wp hic health --format=json
         *
         * @param array $args       Positional arguments (unused).
         * @param array $assoc_args Opzioni associative fornite dal CLI.
         */
        public function health($args, $assoc_args) {
            if (function_exists('hic_init_health_monitor')) {
                hic_init_health_monitor();
            }

            $monitor = function_exists('hic_get_health_monitor') ? hic_get_health_monitor() : null;

            if ((!is_object($monitor) || !method_exists($monitor, 'check_health')) && class_exists('HIC_Health_Monitor')) {
                $monitor = new HIC_Health_Monitor();
            }

            if (!is_object($monitor) || !method_exists($monitor, 'check_health')) {
                WP_CLI::error('Health monitor not available');
                return;
            }

            $allowed_levels = [HIC_DIAGNOSTIC_BASIC, HIC_DIAGNOSTIC_DETAILED, HIC_DIAGNOSTIC_FULL];
            $level = isset($assoc_args['level']) ? sanitize_text_field((string) $assoc_args['level']) : HIC_DIAGNOSTIC_BASIC;

            if (!in_array($level, $allowed_levels, true)) {
                WP_CLI::warning('Livello non valido. Valori consentiti: basic, detailed, full.');
                $level = HIC_DIAGNOSTIC_BASIC;
            }

            $format = isset($assoc_args['format']) ? strtolower(sanitize_text_field((string) $assoc_args['format'])) : 'table';
            if (!in_array($format, ['table', 'json'], true)) {
                WP_CLI::warning('Formato non valido. Utilizzo formato "table".');
                $format = 'table';
            }

            $show_details = isset($assoc_args['details']) ? (bool) $assoc_args['details'] : false;
            $show_metrics = isset($assoc_args['metrics']) ? (bool) $assoc_args['metrics'] : false;

            $health_data = $monitor->check_health($level);

            if ($format === 'json') {
                WP_CLI::line(wp_json_encode($health_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return;
            }

            $status = strtoupper((string) ($health_data['status'] ?? 'unknown'));
            $timestamp = $health_data['timestamp'] ?? 'unknown';
            $version = $health_data['version'] ?? (defined('HIC_PLUGIN_VERSION') ? HIC_PLUGIN_VERSION : 'unknown');

            WP_CLI::log('=== HIC Health Check ===');
            WP_CLI::log('Status: ' . $status);
            WP_CLI::log('Timestamp: ' . $timestamp);
            WP_CLI::log('Plugin version: ' . $version);

            if (!empty($health_data['checks']) && is_array($health_data['checks'])) {
                WP_CLI::log('');
                WP_CLI::log('Checks:');

                foreach ($health_data['checks'] as $name => $check) {
                    $label = ucwords(str_replace(['_', '-'], ' ', (string) $name));
                    $check_status = strtoupper((string) ($check['status'] ?? 'unknown'));
                    $message = isset($check['message']) && is_string($check['message']) ? $check['message'] : '';

                    $line = sprintf('- %s: %s', $label, $check_status);
                    if ($message !== '') {
                        $line .= ' - ' . $message;
                    }

                    WP_CLI::log($line);

                    if ($show_details && !empty($check['details']) && is_array($check['details'])) {
                        WP_CLI::log('  Details: ' . wp_json_encode($check['details']));
                    }
                }
            }

            if ($show_metrics && !empty($health_data['metrics']) && is_array($health_data['metrics'])) {
                WP_CLI::log('');
                WP_CLI::log('Metrics:');

                foreach ($health_data['metrics'] as $metric => $value) {
                    $metric_label = ucwords(str_replace(['_', '-'], ' ', (string) $metric));
                    if (is_scalar($value)) {
                        WP_CLI::log(sprintf('- %s: %s', $metric_label, (string) $value));
                    } else {
                        WP_CLI::log(sprintf('- %s: %s', $metric_label, wp_json_encode($value)));
                    }
                }
            }

            if (!empty($health_data['alerts']) && is_array($health_data['alerts'])) {
                WP_CLI::log('');
                WP_CLI::log('Alerts:');

                foreach ($health_data['alerts'] as $alert) {
                    if (is_string($alert)) {
                        WP_CLI::warning('- ' . $alert);
                        continue;
                    }

                    if (is_array($alert) && isset($alert['message']) && is_string($alert['message'])) {
                        WP_CLI::warning('- ' . $alert['message']);
                    }
                }
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