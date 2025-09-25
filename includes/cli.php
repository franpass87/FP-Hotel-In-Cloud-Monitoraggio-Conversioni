<?php declare(strict_types=1);

use FpHic\Api\RateLimitController;
use FpHic\HIC_Booking_Poller;
use FpHic\HIC_Rate_Limiter;
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
                WP_CLI::error(__('HIC_Booking_Poller class not found', 'hotel-in-cloud'));
                return;
            }
            
            $poller = new HIC_Booking_Poller();
            
            WP_CLI::log(__('Starting manual polling execution...', 'hotel-in-cloud'));
            
            if ($force) {
                WP_CLI::log(__('Force mode: bypassing lock check', 'hotel-in-cloud'));
                // Create a temporary method to bypass lock for manual execution
                $this->force_poll_execution($poller);
            } else {
                // Use normal execution method
                $poller->execute_poll();
            }
            
            WP_CLI::success(__('Polling execution completed', 'hotel-in-cloud'));
            
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
                WP_CLI::error(__('HIC_Booking_Poller class not found', 'hotel-in-cloud'));
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
                WP_CLI::warning(__('This will reset polling state (locks, timestamps). Use --confirm to proceed.', 'hotel-in-cloud'));
                return;
            }
            
            // Clear lock
            delete_transient('hic_reliable_polling_lock');
            
            // Reset timestamp
            delete_option('hic_last_reliable_poll');
            
            // Clear scheduled events
            \FpHic\Helpers\hic_safe_wp_clear_scheduled_hook('hic_reliable_poll_event');
            
            WP_CLI::success(__('Polling state reset successfully', 'hotel-in-cloud'));
        }

        /**
         * Run cleanup routines.
         *
         * ## OPTIONS
         *
         * [--logs]            Clean up old log files
         * [--gclids]          Remove expired tracking identifiers
         * [--booking-events]  Remove processed booking events
         * [--realtime-sync]   Remove aged real-time sync records
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
                    WP_CLI::success(__('Logs cleanup completed', 'hotel-in-cloud'));
                } else {
                    WP_CLI::warning(__('Log manager not available', 'hotel-in-cloud'));
                }
            }

            if (isset($assoc_args['gclids'])) {
                $performed = true;
                if (function_exists('hic_cleanup_old_gclids')) {
                    $deleted = hic_cleanup_old_gclids();
                    WP_CLI::success(sprintf(__('GCLIDs cleanup completed (%d removed)', 'hotel-in-cloud'), $deleted));
                } else {
                    WP_CLI::warning(__('hic_cleanup_old_gclids function not found', 'hotel-in-cloud'));
                }
            }

            if (isset($assoc_args['booking-events'])) {
                $performed = true;
                if (function_exists('hic_cleanup_booking_events')) {
                    $deleted = hic_cleanup_booking_events();
                    WP_CLI::success(sprintf(__('Booking events cleanup completed (%d removed)', 'hotel-in-cloud'), $deleted));
                } else {
                    WP_CLI::warning(__('hic_cleanup_booking_events function not found', 'hotel-in-cloud'));
                }
            }

            if (isset($assoc_args['realtime-sync'])) {
                $performed = true;
                if (function_exists('hic_cleanup_realtime_sync')) {
                    $deleted = hic_cleanup_realtime_sync();
                    WP_CLI::success(sprintf(__('Realtime sync cleanup completed (%d removed)', 'hotel-in-cloud'), $deleted));
                } else {
                    WP_CLI::warning(__('hic_cleanup_realtime_sync function not found', 'hotel-in-cloud'));
                }
            }

            if (!$performed) {
                WP_CLI::warning(__('Specify at least one cleanup target: --logs, --gclids, --booking-events, or --realtime-sync', 'hotel-in-cloud'));
            }
        }

        /**
         * Inspect or reset outbound HTTP rate limits.
         *
         * ## OPTIONS
         *
         * <action>
         * : Action to execute. Accepted values: list, reset.
         *
         * [--host=<host>]
         * : Hostname configured in the rate limit map to reset.
         *
         * [--key=<key>]
         * : Explicit rate limit storage key to reset when using custom integrations.
         *
         * ## EXAMPLES
         *
         *     wp hic rate-limit list
         *     wp hic rate-limit reset --host=api.hotelcincloud.com
         *     wp hic rate-limit reset --key=hic:custom-endpoint
         *
         * @param array<int,string> $args Positional CLI arguments.
         * @param array<string,mixed> $assoc_args Associative CLI arguments.
         */
        public function rate_limit($args, $assoc_args) {
            $action = $args[0] ?? 'list';

            if ($action === 'list') {
                $limits = RateLimitController::getRegisteredLimits();

                if (empty($limits)) {
                    WP_CLI::success(__('No rate limits configured.', 'hotel-in-cloud'));
                    return;
                }

                $rows = [];
                $now = time();

                foreach ($limits as $host => $config) {
                    $status = HIC_Rate_Limiter::inspect($config['key'], $config['max_attempts'], $config['window']);
                    $resetsIn = $status['retry_after'];

                    if ($resetsIn > 0 && function_exists('human_time_diff')) {
                        $human = human_time_diff($now, $now + $resetsIn);
                    } elseif ($resetsIn > 0) {
                        $human = $resetsIn . 's';
                    } else {
                        $human = __('ready', 'hotel-in-cloud');
                    }

                    $rows[] = [
                        'host' => $host,
                        'key' => $config['key'],
                        'max_attempts' => $config['max_attempts'],
                        'window' => $config['window'],
                        'used' => $status['count'],
                        'remaining' => $status['remaining'],
                        'retry_after' => $status['retry_after'],
                        'resets_in' => $human,
                    ];
                }

                \WP_CLI\Utils\format_items('table', $rows, [
                    'host' => __('Host', 'hotel-in-cloud'),
                    'key' => __('Storage key', 'hotel-in-cloud'),
                    'max_attempts' => __('Max attempts', 'hotel-in-cloud'),
                    'window' => __('Window (s)', 'hotel-in-cloud'),
                    'used' => __('Used', 'hotel-in-cloud'),
                    'remaining' => __('Remaining', 'hotel-in-cloud'),
                    'retry_after' => __('Retry after (s)', 'hotel-in-cloud'),
                    'resets_in' => __('Resets in', 'hotel-in-cloud'),
                ]);
                return;
            }

            if ($action === 'reset') {
                $targetHost = isset($assoc_args['host']) ? (string) $assoc_args['host'] : '';
                $targetKey = isset($assoc_args['key']) ? (string) $assoc_args['key'] : '';

                if ($targetHost === '' && $targetKey === '') {
                    WP_CLI::error(__('Specify either --host or --key to reset a rate limit bucket.', 'hotel-in-cloud'));
                    return;
                }

                if ($targetHost !== '') {
                    $limits = RateLimitController::getRegisteredLimits();

                    if (!isset($limits[$targetHost])) {
                        WP_CLI::error(sprintf(__('Unknown rate limit host "%s".', 'hotel-in-cloud'), $targetHost));
                        return;
                    }

                    $targetKey = $limits[$targetHost]['key'];
                }

                HIC_Rate_Limiter::reset($targetKey);
                WP_CLI::success(sprintf(__('Rate limit bucket "%s" reset successfully.', 'hotel-in-cloud'), $targetKey));
                return;
            }

            WP_CLI::error(__('Invalid action. Use "list" or "reset".', 'hotel-in-cloud'));
        }

        /**
         * Inspect aggregated performance metrics captured by the monitoring system.
         *
         * ## OPTIONS
         *
         * [--days=<days>]
         * : Number of days to include in the aggregation window (1-90). Defaults to 7.
         *
         * [--operation=<operation>]
         * : Restrict the report to a specific operation key.
         *
         * [--format=<format>]
         * : Output format. Options: table, json. Defaults to table.
         * ---
         * default: table
         * options:
         *   - table
         *   - json
         * ---
         *
         * ## EXAMPLES
         *
         *     wp hic performance --days=14
         *     wp hic performance --operation=booking_processing --format=json
         *
         * @param array<int,string>   $args       Positional CLI arguments.
         * @param array<string,mixed> $assoc_args Associative CLI arguments.
         */
        public function performance($args, $assoc_args) {
            if (!function_exists('hic_get_performance_monitor')) {
                WP_CLI::error(__('Performance monitor not available. Enable performance metrics to use this command.', 'hotel-in-cloud'));
                return;
            }

            $monitor = hic_get_performance_monitor();

            if (!is_object($monitor) || !method_exists($monitor, 'get_aggregated_metrics')) {
                WP_CLI::error(__('Performance monitor is disabled. Enable performance metrics to use this command.', 'hotel-in-cloud'));
                return;
            }

            $defaults = [
                'days' => 7,
                'operation' => '',
                'format' => 'table',
            ];

            $assoc_args = $this->merge_cli_args($assoc_args, $defaults);

            $days = (int) ($assoc_args['days'] ?? 7);

            if ($days < 1) {
                WP_CLI::warning(__('Days must be greater than zero. Defaulting to the minimum window of 1 day.', 'hotel-in-cloud'));
                $days = 1;
            }

            $operation = is_string($assoc_args['operation'] ?? '')
                ? trim((string) $assoc_args['operation'])
                : '';

            $format = strtolower((string) ($assoc_args['format'] ?? 'table'));

            if (!in_array($format, ['table', 'json'], true)) {
                WP_CLI::error(__('Invalid format. Use "table" or "json".', 'hotel-in-cloud'));
                return;
            }

            /** @var array<string,mixed> $aggregated */
            $aggregated = $monitor->get_aggregated_metrics($days, $operation !== '' ? $operation : null);

            if (empty($aggregated['operations'])) {
                if ($operation !== '') {
                    WP_CLI::warning(sprintf(__('No metrics recorded for operation "%s" in the selected window.', 'hotel-in-cloud'), $operation));
                } else {
                    WP_CLI::log(__('No performance metrics available for the selected window.', 'hotel-in-cloud'));
                }
                return;
            }

            if ($format === 'json') {
                $payload = $this->prepare_performance_payload($aggregated);
                $encoded = function_exists('wp_json_encode')
                    ? wp_json_encode($payload, JSON_PRETTY_PRINT)
                    : json_encode($payload, JSON_PRETTY_PRINT);

                WP_CLI::line((string) $encoded);
                return;
            }

            WP_CLI::log(sprintf(
                __('Aggregated metrics from %1$s to %2$s', 'hotel-in-cloud'),
                $aggregated['start_date'] ?? '',
                $aggregated['end_date'] ?? ''
            ));

            $rows = [];

            foreach ($aggregated['operations'] as $name => $data) {
                $trend = (array) ($data['trend'] ?? []);

                $rows[] = [
                    'operation' => (string) $name,
                    'count' => (string) ($data['total'] ?? 0),
                    'avg_ms' => $this->format_ms($data['avg_duration'] ?? 0.0),
                    'p95_ms' => $this->format_ms($data['p95_duration'] ?? 0.0),
                    'success_rate' => $this->format_percent($data['success_rate'] ?? 0.0),
                    'count_change' => $this->format_percent($trend['count_change'] ?? 0.0),
                    'duration_change' => $this->format_percent($trend['duration_change'] ?? 0.0),
                    'success_change' => $this->format_percent($trend['success_change'] ?? 0.0),
                ];
            }

            \WP_CLI\Utils\format_items('table', $rows, [
                'operation' => __('Operation', 'hotel-in-cloud'),
                'count' => __('Events', 'hotel-in-cloud'),
                'avg_ms' => __('Avg (ms)', 'hotel-in-cloud'),
                'p95_ms' => __('P95 (ms)', 'hotel-in-cloud'),
                'success_rate' => __('Success', 'hotel-in-cloud'),
                'count_change' => __('Events Δ', 'hotel-in-cloud'),
                'duration_change' => __('Duration Δ', 'hotel-in-cloud'),
                'success_change' => __('Success Δ', 'hotel-in-cloud'),
            ]);

            if ($operation !== '') {
                $operationKey = $operation;

                if (!isset($aggregated['operations'][$operationKey])) {
                    return;
                }

                $daily = $aggregated['operations'][$operationKey]['days'] ?? [];

                if (empty($daily)) {
                    return;
                }

                WP_CLI::log('');
                WP_CLI::log(sprintf(__('Daily breakdown for %s', 'hotel-in-cloud'), $operationKey));

                $dailyRows = [];

                foreach ($daily as $date => $day) {
                    $dailyRows[] = [
                        'date' => (string) $date,
                        'count' => (string) ($day['count'] ?? 0),
                        'avg_ms' => $this->format_ms($day['avg_duration'] ?? 0.0),
                        'p95_ms' => $this->format_ms($day['p95_duration'] ?? 0.0),
                        'success_rate' => $this->format_percent($day['success_rate'] ?? 0.0),
                    ];
                }

                \WP_CLI\Utils\format_items('table', $dailyRows, [
                    'date' => __('Date', 'hotel-in-cloud'),
                    'count' => __('Events', 'hotel-in-cloud'),
                    'avg_ms' => __('Avg (ms)', 'hotel-in-cloud'),
                    'p95_ms' => __('P95 (ms)', 'hotel-in-cloud'),
                    'success_rate' => __('Success', 'hotel-in-cloud'),
                ]);
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
                WP_CLI::error(__('Queue table not found', 'hotel-in-cloud'));
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
                WP_CLI::log(__('No events found in queue', 'hotel-in-cloud'));
                return;
            }

            WP_CLI\Utils\format_items('table', $results, array(
                'id' => __('ID', 'hotel-in-cloud'),
                'booking_id' => __('Booking ID', 'hotel-in-cloud'),
                'processed' => __('Processed', 'hotel-in-cloud'),
                'poll_timestamp' => __('Polled at', 'hotel-in-cloud'),
                'processed_at' => __('Processed at', 'hotel-in-cloud'),
                'process_attempts' => __('Attempts', 'hotel-in-cloud'),
                'last_error' => __('Last error', 'hotel-in-cloud'),
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
                WP_CLI::error(__('Reservation ID required', 'hotel-in-cloud'));
                return;
            }

            if (!function_exists('\\FpHic\\hic_process_booking_data')) {
                WP_CLI::error(__('\\FpHic\\hic_process_booking_data function not found', 'hotel-in-cloud'));
                return;
            }

            $reservation_id = sanitize_text_field($args[0]);
            $sid = isset($assoc_args['sid']) ? sanitize_text_field($assoc_args['sid']) : null;

            $email = function_exists('hic_get_reservation_email') ? hic_get_reservation_email($reservation_id) : null;
            if (empty($email)) {
                WP_CLI::error(sprintf(__('Email not found for reservation %s', 'hotel-in-cloud'), $reservation_id));
                return;
            }

            $data = array(
                'reservation_id' => $reservation_id,
                'email' => $email,
            );

            if ($sid) {
                $data['sid'] = $sid;
            }

            WP_CLI::log(sprintf(__('Resending reservation %s...', 'hotel-in-cloud'), $reservation_id));
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
                WP_CLI::success(__('Reservation resent successfully', 'hotel-in-cloud'));
            } elseif ($status === 'partial') {
                WP_CLI::success(__('Reservation resent with partial success', 'hotel-in-cloud'));
                if (!empty($failed_integrations)) {
                    WP_CLI::warning(sprintf(__('Failed integrations: %s', 'hotel-in-cloud'), implode(', ', $failed_integrations)));
                }
                if (!empty($messages)) {
                    WP_CLI::log(sprintf(__('Messages: %s', 'hotel-in-cloud'), implode(', ', $messages)));
                }
            } else {
                $error_message = __('Failed to resend reservation', 'hotel-in-cloud');
                if (!empty($messages)) {
                    $error_message .= ' (' . implode(', ', $messages) . ')';
                }
                if (!empty($failed_integrations)) {
                    $error_message .= ' - ' . sprintf(__('Failed integrations: %s', 'hotel-in-cloud'), implode(', ', $failed_integrations));
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
                WP_CLI::error(__('Config validator not available', 'hotel-in-cloud'));
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
                WP_CLI::success(__('Configuration valid with warnings', 'hotel-in-cloud'));
            } else {
                WP_CLI::success(__('Configuration valid', 'hotel-in-cloud'));
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
                WP_CLI::error(__('Health monitor not available', 'hotel-in-cloud'));
                return;
            }

            $allowed_levels = [HIC_DIAGNOSTIC_BASIC, HIC_DIAGNOSTIC_DETAILED, HIC_DIAGNOSTIC_FULL];
            $level = isset($assoc_args['level']) ? sanitize_text_field((string) $assoc_args['level']) : HIC_DIAGNOSTIC_BASIC;

            if (!in_array($level, $allowed_levels, true)) {
                WP_CLI::warning(__('Livello non valido. Valori consentiti: basic, detailed, full.', 'hotel-in-cloud'));
                $level = HIC_DIAGNOSTIC_BASIC;
            }

            $format = isset($assoc_args['format']) ? strtolower(sanitize_text_field((string) $assoc_args['format'])) : 'table';
            if (!in_array($format, ['table', 'json'], true)) {
                WP_CLI::warning(__('Formato non valido. Utilizzo formato "table".', 'hotel-in-cloud'));
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

            WP_CLI::log(__('=== HIC Health Check ===', 'hotel-in-cloud'));
            WP_CLI::log(sprintf(__('Status: %s', 'hotel-in-cloud'), $status));
            WP_CLI::log(sprintf(__('Timestamp: %s', 'hotel-in-cloud'), $timestamp));
            WP_CLI::log(sprintf(__('Plugin version: %s', 'hotel-in-cloud'), $version));

            if (!empty($health_data['checks']) && is_array($health_data['checks'])) {
                WP_CLI::log('');
                WP_CLI::log(__('Checks:', 'hotel-in-cloud'));

                foreach ($health_data['checks'] as $name => $check) {
                    $label = ucwords(str_replace(['_', '-'], ' ', (string) $name));
                    $check_status = strtoupper((string) ($check['status'] ?? 'unknown'));
                    $message = isset($check['message']) && is_string($check['message']) ? $check['message'] : '';

                    $line = sprintf(__('- %1$s: %2$s', 'hotel-in-cloud'), $label, $check_status);
                    if ($message !== '') {
                        $line .= ' - ' . $message;
                    }

                    WP_CLI::log($line);

                    if ($show_details && !empty($check['details']) && is_array($check['details'])) {
                        WP_CLI::log(sprintf(__('  Details: %s', 'hotel-in-cloud'), wp_json_encode($check['details'])));
                    }
                }
            }

            if ($show_metrics && !empty($health_data['metrics']) && is_array($health_data['metrics'])) {
                WP_CLI::log('');
                WP_CLI::log(__('Metrics:', 'hotel-in-cloud'));

                foreach ($health_data['metrics'] as $metric => $value) {
                    $metric_label = ucwords(str_replace(['_', '-'], ' ', (string) $metric));
                    if (is_scalar($value)) {
                        WP_CLI::log(sprintf(__('- %1$s: %2$s', 'hotel-in-cloud'), $metric_label, (string) $value));
                    } else {
                        WP_CLI::log(sprintf(__('- %1$s: %2$s', 'hotel-in-cloud'), $metric_label, wp_json_encode($value)));
                    }
                }
            }

            if (!empty($health_data['alerts']) && is_array($health_data['alerts'])) {
                WP_CLI::log('');
                WP_CLI::log(__('Alerts:', 'hotel-in-cloud'));

                foreach ($health_data['alerts'] as $alert) {
                    if (is_string($alert)) {
                        WP_CLI::warning(sprintf(__('- %s', 'hotel-in-cloud'), $alert));
                        continue;
                    }

                    if (is_array($alert) && isset($alert['message']) && is_string($alert['message'])) {
                        WP_CLI::warning(sprintf(__('- %s', 'hotel-in-cloud'), $alert['message']));
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
                WP_CLI::log(__('Performing polling (force mode)...', 'hotel-in-cloud'));

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

                /* translators: %s: execution time in seconds. */
                WP_CLI::success(sprintf(__('Polling completed in %ss', 'hotel-in-cloud'), $execution_time));
                $this->display_stats($stats);

            } catch (Exception $e) {
                $poller->log_structured('poll_error_manual', array(
                    'error' => $e->getMessage(),
                    'manual_force' => true
                ));

                WP_CLI::error(sprintf(__('Polling failed: %s', 'hotel-in-cloud'), $e->getMessage()));
            }
        }

        /**
         * Display statistics in a nice format
         */
        private function display_stats($stats) {
            if (isset($stats['error'])) {
                WP_CLI::warning(sprintf(__('Stats error: %s', 'hotel-in-cloud'), $stats['error']));
                return;
            }

            WP_CLI::log('');
            WP_CLI::log(__('=== Polling Statistics ===', 'hotel-in-cloud'));

            if (isset($stats['total_events'])) {
                WP_CLI::log(sprintf(__('Total events: %s', 'hotel-in-cloud'), $stats['total_events']));
                WP_CLI::log(sprintf(__('Processed: %s', 'hotel-in-cloud'), $stats['processed_events']));
                WP_CLI::log(sprintf(__('Pending: %s', 'hotel-in-cloud'), $stats['pending_events']));
                WP_CLI::log(sprintf(__('Errors: %s', 'hotel-in-cloud'), $stats['error_events']));
                WP_CLI::log(sprintf(__('24h activity: %s', 'hotel-in-cloud'), $stats['events_24h']));
            }

            if (isset($stats['last_poll']) && $stats['last_poll'] > 0) {
                WP_CLI::log(sprintf(__('Last poll: %1$s (%2$s)', 'hotel-in-cloud'), wp_date('Y-m-d H:i:s', $stats['last_poll']), $stats['last_poll_human']));
                WP_CLI::log(sprintf(__('Lag: %s seconds', 'hotel-in-cloud'), $stats['lag_seconds']));
            }

            if (isset($stats['lock_active'])) {
                $lock_status = $stats['lock_active'] ? __('Active', 'hotel-in-cloud') : __('Free', 'hotel-in-cloud');
                WP_CLI::log(sprintf(__('Lock status: %s', 'hotel-in-cloud'), $lock_status));
                if ($stats['lock_active'] && isset($stats['lock_age'])) {
                    WP_CLI::log(sprintf(__('Lock age: %s seconds', 'hotel-in-cloud'), $stats['lock_age']));
                }
            }

            // Show recent stats if available (from last poll)
            if (isset($stats['reservations_fetched'])) {
                WP_CLI::log('');
                WP_CLI::log(__('=== Last Poll Results ===', 'hotel-in-cloud'));
                WP_CLI::log(sprintf(__('Fetched: %s', 'hotel-in-cloud'), $stats['reservations_fetched']));
                WP_CLI::log(sprintf(__('New: %s', 'hotel-in-cloud'), $stats['reservations_new']));
                WP_CLI::log(sprintf(__('Duplicates: %s', 'hotel-in-cloud'), $stats['reservations_duplicate']));
                WP_CLI::log(sprintf(__('Processed: %s', 'hotel-in-cloud'), $stats['reservations_processed']));
                WP_CLI::log(sprintf(__('Errors: %s', 'hotel-in-cloud'), $stats['reservations_errors']));
                WP_CLI::log(sprintf(__('API calls: %s', 'hotel-in-cloud'), $stats['api_calls']));
            }
        }

        /**
         * Merge CLI arguments with defaults without relying on WordPress helpers.
         *
         * @param array<string,mixed> $args
         * @param array<string,mixed> $defaults
         *
         * @return array<string,mixed>
         */
        private function merge_cli_args($args, $defaults) {
            if (function_exists('wp_parse_args')) {
                /** @var array<string,mixed> $merged */
                $merged = wp_parse_args($args, $defaults);
                return $merged;
            }

            if (!is_array($args)) {
                return $defaults;
            }

            return array_merge($defaults, $args);
        }

        /**
         * Format seconds as milliseconds respecting localisation.
         *
         * @param float|int|string $seconds Duration in seconds.
         */
        private function format_ms($seconds): string {
            $milliseconds = (float) $seconds * 1000;

            return $this->format_number($milliseconds, 2);
        }

        /**
         * Format a percentage value with a trailing percent sign.
         *
         * @param float|int|string $value Percentage value.
         */
        private function format_percent($value): string {
            return $this->format_number((float) $value, 2) . '%';
        }

        /**
         * Localise a numeric value with the provided precision.
         */
        private function format_number(float $value, int $decimals = 2): string {
            $rounded = round($value, $decimals);

            if (function_exists('number_format_i18n')) {
                return number_format_i18n($rounded, $decimals);
            }

            return number_format($rounded, $decimals, '.', '');
        }

        /**
         * Normalise aggregated metrics for serialisation in JSON responses.
         *
         * @param array<string,mixed> $aggregated
         *
         * @return array<string,mixed>
         */
        private function prepare_performance_payload(array $aggregated): array {
            $prepared = [
                'start_date' => $aggregated['start_date'] ?? '',
                'end_date' => $aggregated['end_date'] ?? '',
                'operations' => [],
            ];

            foreach ($aggregated['operations'] as $name => $data) {
                $operation = [
                    'total' => (int) ($data['total'] ?? 0),
                    'avg_duration_ms' => round((float) ($data['avg_duration'] ?? 0.0) * 1000, 4),
                    'p95_duration_ms' => round((float) ($data['p95_duration'] ?? 0.0) * 1000, 4),
                    'success_rate' => round((float) ($data['success_rate'] ?? 0.0), 4),
                    'total_duration_ms' => round((float) ($data['total_duration'] ?? 0.0) * 1000, 4),
                    'success' => [
                        'total' => (int) ($data['success']['total'] ?? 0),
                        'failed' => (int) ($data['success']['failed'] ?? 0),
                    ],
                    'trend' => [
                        'count_change' => round((float) (($data['trend']['count_change'] ?? 0.0)), 4),
                        'duration_change' => round((float) (($data['trend']['duration_change'] ?? 0.0)), 4),
                        'success_change' => round((float) (($data['trend']['success_change'] ?? 0.0)), 4),
                    ],
                    'days' => [],
                ];

                foreach (($data['days'] ?? []) as $date => $day) {
                    $operation['days'][$date] = [
                        'count' => (int) ($day['count'] ?? 0),
                        'avg_duration_ms' => round((float) ($day['avg_duration'] ?? 0.0) * 1000, 4),
                        'p95_duration_ms' => round((float) ($day['p95_duration'] ?? 0.0) * 1000, 4),
                        'success_rate' => round((float) ($day['success_rate'] ?? 0.0), 4),
                        'success_total' => (int) ($day['success_total'] ?? 0),
                        'total_duration_ms' => round((float) ($day['total_duration'] ?? 0.0) * 1000, 4),
                    ];
                }

                $prepared['operations'][$name] = $operation;
            }

            return $prepared;
        }
    }

    // Register CLI commands
    WP_CLI::add_command('hic', 'HIC_CLI_Commands');
}