<?php
/**
 * Internal Booking Scheduler - WP-Cron System
 */

if (!defined('ABSPATH')) exit;

class HIC_Booking_Poller {
    
    public function __construct() {
        // Only initialize if WordPress functions are available
        if (!function_exists('add_filter') || !function_exists('add_action')) {
            return;
        }
        
        // Register custom cron intervals first - always available for cron managers
        add_filter('cron_schedules', array($this, 'add_custom_cron_intervals'));
        
        // WP-Cron system for reliable 24/7 operation
        add_action('hic_continuous_poll_event', array($this, 'execute_continuous_polling'));
        add_action('hic_deep_check_event', array($this, 'execute_deep_check'));
        add_action('hic_fallback_poll_event', array($this, 'execute_fallback_polling'));
        add_action('hic_cleanup_event', 'hic_cleanup_old_gclids');
        
        // Initialize scheduler on activation
        add_action('init', array($this, 'ensure_scheduler_is_active'), 20);
        
        // Add immediate check when admin page is loaded
        add_action('admin_init', array($this, 'admin_watchdog_check'));
        
        // Add watchdog check using WordPress heartbeat
        add_action('heartbeat_received', array($this, 'heartbeat_watchdog'), 10, 2);
        add_filter('heartbeat_settings', array($this, 'heartbeat_settings'));
        
        // Add fallback mechanism for when WP-Cron completely fails
        add_action('wp_loaded', array($this, 'fallback_polling_check'));
        add_action('shutdown', array($this, 'shutdown_polling_check'));
    }
    
    /**
     * Ensure the scheduler is active - uses WP-Cron system
     */
    public function ensure_scheduler_is_active() {
        if (!$this->should_poll()) {
            Helpers\hic_log('Scheduler conditions not met, clearing all scheduled events');
            // Clear any existing events if conditions aren't met
            $this->clear_all_scheduled_events();
            return;
        }
        
        // Check and schedule continuous polling event
        $continuous_next = Helpers\hic_safe_wp_next_scheduled('hic_continuous_poll_event');
        if (!$continuous_next) {
            $scheduled = Helpers\hic_safe_wp_schedule_event(time(), 'hic_every_minute', 'hic_continuous_poll_event');
            if ($scheduled) {
                Helpers\hic_log('WP-Cron Scheduler: Scheduled continuous polling every minute');
            } else {
                Helpers\hic_log('WP-Cron Scheduler: FAILED to schedule continuous polling event');
            }
        } else {
            // Check if event is overdue (more than 2 minutes in the past)
            if ($continuous_next < (time() - 120)) {
                Helpers\hic_log('WP-Cron Scheduler: Continuous polling event is overdue, rescheduling');
                Helpers\hic_safe_wp_clear_scheduled_hook('hic_continuous_poll_event');
                Helpers\hic_safe_wp_schedule_event(time(), 'hic_every_minute', 'hic_continuous_poll_event');
            }
        }
        
        // Check and schedule deep check event
        $deep_next = Helpers\hic_safe_wp_next_scheduled('hic_deep_check_event');
        if (!$deep_next) {
            $scheduled = Helpers\hic_safe_wp_schedule_event(time(), 'hic_every_ten_minutes', 'hic_deep_check_event');
            if ($scheduled) {
                Helpers\hic_log('WP-Cron Scheduler: Scheduled deep check every 10 minutes');
            } else {
                Helpers\hic_log('WP-Cron Scheduler: FAILED to schedule deep check event');
            }
        } else {
            // Check if event is overdue (more than 12 minutes in the past)
            if ($deep_next < (time() - 720)) {
                Helpers\hic_log('WP-Cron Scheduler: Deep check event is overdue, rescheduling');
                Helpers\hic_safe_wp_clear_scheduled_hook('hic_deep_check_event');
                Helpers\hic_safe_wp_schedule_event(time(), 'hic_every_ten_minutes', 'hic_deep_check_event');
            }
        }

        // Schedule daily cleanup event
        $cleanup_next = Helpers\hic_safe_wp_next_scheduled('hic_cleanup_event');
        if (!$cleanup_next) {
            $scheduled = Helpers\hic_safe_wp_schedule_event(time(), 'daily', 'hic_cleanup_event');
            if ($scheduled) {
                Helpers\hic_log('WP-Cron Scheduler: Scheduled daily cleanup event');
            } else {
                Helpers\hic_log('WP-Cron Scheduler: FAILED to schedule cleanup event');
            }
        }
        
        // Log current scheduling status
        $this->log_scheduler_status();
    }
    
    /**
     * Add custom WP-Cron intervals
     */
    public function add_custom_cron_intervals($schedules) {
        $schedules['hic_every_minute'] = array(
            'interval' => HIC_CONTINUOUS_POLLING_INTERVAL,
            'display' => 'Every Minute (HIC Continuous Polling)'
        );
        $schedules['hic_every_ten_minutes'] = array(
            'interval' => HIC_DEEP_CHECK_INTERVAL,
            'display' => 'Every 10 Minutes (HIC Deep Check)'
        );
        return $schedules;
    }
    
    /**
     * Clear all scheduled WP-Cron events
     */
    public function clear_all_scheduled_events() {
        Helpers\hic_safe_wp_clear_scheduled_hook('hic_continuous_poll_event');
        Helpers\hic_safe_wp_clear_scheduled_hook('hic_deep_check_event');
        Helpers\hic_safe_wp_clear_scheduled_hook('hic_cleanup_event');
        Helpers\hic_log('WP-Cron Scheduler: Cleared all scheduled events');
    }
    
    /**
     * Check if WP-Cron is working
     */
    public function is_wp_cron_working() {
        // Check if WP-Cron is disabled
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            Helpers\hic_log('WP-Cron is disabled via DISABLE_WP_CRON constant');
            return false;
        }
        
        // Check if events are scheduled
        $continuous_next = Helpers\hic_safe_wp_next_scheduled('hic_continuous_poll_event');
        $deep_next = Helpers\hic_safe_wp_next_scheduled('hic_deep_check_event');
        $cleanup_next = Helpers\hic_safe_wp_next_scheduled('hic_cleanup_event');

        $is_working = ($continuous_next !== false && $deep_next !== false && $cleanup_next !== false);

        if (!$is_working) {
            $debug_info = sprintf(
                'WP-Cron events check: continuous=%s, deep=%s, cleanup=%s',
                $continuous_next ? date('Y-m-d H:i:s', $continuous_next) : 'NOT_SCHEDULED',
                $deep_next ? date('Y-m-d H:i:s', $deep_next) : 'NOT_SCHEDULED',
                $cleanup_next ? date('Y-m-d H:i:s', $cleanup_next) : 'NOT_SCHEDULED'
            );
            Helpers\hic_log('WP-Cron not working: ' . $debug_info);
        }

        return $is_working;
    }
    
    /**
     * Log current scheduler status for debugging
     */
    private function log_scheduler_status() {
        $continuous_next = Helpers\hic_safe_wp_next_scheduled('hic_continuous_poll_event');
        $deep_next = Helpers\hic_safe_wp_next_scheduled('hic_deep_check_event');
        $cleanup_next = Helpers\hic_safe_wp_next_scheduled('hic_cleanup_event');
        
        // Check polling conditions
        $should_poll = $this->should_poll();
        $reliable_polling = Helpers\hic_reliable_polling_enabled();
        $connection_type = Helpers\hic_get_connection_type();
        $api_url = Helpers\hic_get_api_url();
        $has_auth = Helpers\hic_has_basic_auth_credentials();
        
        $status_msg = sprintf(
            'WP-Cron Status: Continuous next=%s, Deep next=%s, Cleanup next=%s, WP-Cron disabled=%s, Should poll=%s (reliable=%s, type=%s, url=%s, auth=%s)',
            $continuous_next ? date('Y-m-d H:i:s', $continuous_next) : 'NOT_SCHEDULED',
            $deep_next ? date('Y-m-d H:i:s', $deep_next) : 'NOT_SCHEDULED',
            $cleanup_next ? date('Y-m-d H:i:s', $cleanup_next) : 'NOT_SCHEDULED',
            (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? 'YES' : 'NO',
            $should_poll ? 'YES' : 'NO',
            $reliable_polling ? 'YES' : 'NO',
            $connection_type ?: 'NONE',
            $api_url ? 'SET' : 'MISSING',
            $has_auth ? 'YES' : 'NO'
        );
        
        Helpers\hic_log($status_msg);
    }
    
    /**
     * Watchdog to detect and recover from polling failures
     */
    public function run_watchdog_check() {
        $current_time = time();
        $last_continuous = get_option('hic_last_continuous_poll', 0);
        $last_deep = get_option('hic_last_deep_check', 0);
        $last_successful = get_option('hic_last_successful_poll', 0);
        
        Helpers\hic_log("Watchdog: Running check - continuous lag: " . ($current_time - $last_continuous) . "s, deep lag: " . ($current_time - $last_deep) . "s");
        
        // Check for continuous polling lag (should run every minute)
        $continuous_lag = $current_time - $last_continuous;
        if ($continuous_lag > HIC_WATCHDOG_THRESHOLD) {
            Helpers\hic_log("Watchdog: Continuous polling lag detected ({$continuous_lag}s), attempting recovery");
            $this->recover_from_failure('continuous');
        }
        
        // Check for deep check lag (should run every 10 minutes)  
        $deep_lag = $current_time - $last_deep;
        if ($deep_lag > (HIC_DEEP_CHECK_INTERVAL * 2)) {
            Helpers\hic_log("Watchdog: Deep check lag detected ({$deep_lag}s), attempting recovery");
            $this->recover_from_failure('deep');
        }
        
        // Check for completely stuck polling - no successful polls for 1+ hours
        $success_lag = $current_time - $last_successful;
        if ($last_successful > 0 && $success_lag > 3600) { // 1 hour without success
            Helpers\hic_log("Watchdog: No successful polling for {$success_lag}s - likely timestamp error, triggering timestamp recovery");
            $this->recover_from_failure('timestamp_error');
        }
        
        // Check if WP-Cron events are properly scheduled
        if (!$this->is_wp_cron_working()) {
            Helpers\hic_log("Watchdog: WP-Cron events not properly scheduled, attempting recovery");
            $this->recover_from_failure('scheduling');
        }
        
        // Additional check: if polling should be active but no events are scheduled
        if ($this->should_poll()) {
            $continuous_next = Helpers\hic_safe_wp_next_scheduled('hic_continuous_poll_event');
            $deep_next = Helpers\hic_safe_wp_next_scheduled('hic_deep_check_event');
            
            if (!$continuous_next || !$deep_next) {
                Helpers\hic_log("Watchdog: Polling should be active but events not scheduled, forcing restart");
                $this->recover_from_failure('scheduling');
            }
        }
    }
    
    /**
     * Recover from various types of polling failures
     */
    private function recover_from_failure($failure_type) {
        Helpers\hic_log("Recovery: Attempting recovery for failure type: $failure_type");
        
        switch ($failure_type) {
            case 'continuous':
                // Force reschedule continuous polling
                Helpers\hic_safe_wp_clear_scheduled_hook('hic_continuous_poll_event');
                Helpers\hic_safe_wp_schedule_event(time(), 'hic_every_minute', 'hic_continuous_poll_event');
                // Trigger immediate execution
                $this->execute_continuous_polling();
                break;
                
            case 'deep':
                // Force reschedule deep check
                Helpers\hic_safe_wp_clear_scheduled_hook('hic_deep_check_event');
                Helpers\hic_safe_wp_schedule_event(time(), 'hic_every_ten_minutes', 'hic_deep_check_event');
                // Trigger immediate execution
                $this->execute_deep_check();
                break;
                
            case 'scheduling':
                // Full scheduler restart
                $this->clear_all_scheduled_events();
                sleep(1);
                $this->ensure_scheduler_is_active();
                break;
                
            case 'timestamp_error':
                // Handle stuck polling due to timestamp errors
                Helpers\hic_log("Recovery: Resetting all timestamps due to timestamp errors");
                $safe_timestamp = time() - (3 * DAY_IN_SECONDS); // Reset to 3 days ago
                $recent_timestamp = time() - HIC_WATCHDOG_THRESHOLD; // 5 minutes ago for polling timestamps
                
                // Validate timestamps before using them (if hic_validate_api_timestamp is available)
                if (function_exists('hic_validate_api_timestamp')) {
                    $safe_timestamp = hic_validate_api_timestamp($safe_timestamp, 'Recovery data timestamp reset');
                    $recent_timestamp = hic_validate_api_timestamp($recent_timestamp, 'Recovery polling timestamp reset');
                }
                
                update_option('hic_last_updates_since', $safe_timestamp);
                update_option('hic_last_update_check', $safe_timestamp);
                update_option('hic_last_continuous_check', $safe_timestamp);
                update_option('hic_last_continuous_poll', $recent_timestamp);
                update_option('hic_last_deep_check', $recent_timestamp);
                
                // Also restart the scheduler to ensure clean state
                $this->clear_all_scheduled_events();
                sleep(1);
                $this->ensure_scheduler_is_active();
                
                Helpers\hic_log("Recovery: All timestamps reset - data timestamps to " . date('Y-m-d H:i:s', $safe_timestamp) . ", polling timestamps to " . date('Y-m-d H:i:s', $recent_timestamp) . ", scheduler restarted");
                break;
        }
        
        Helpers\hic_log("Recovery: Completed recovery attempt for $failure_type");
    }
    
    /**
     * Public method to trigger timestamp recovery
     * Can be called from diagnostics interface or manual intervention
     */
    public function trigger_timestamp_recovery() {
        Helpers\hic_log("Manual timestamp recovery triggered");
        $this->recover_from_failure('timestamp_error');
        return array(
            'success' => true,
            'message' => 'Timestamp recovery completed - all timestamps reset and scheduler restarted'
        );
    }
    
    /**
     * Configure WordPress heartbeat for watchdog monitoring
     */
    public function heartbeat_settings($settings) {
        // Only modify heartbeat if we're responsible for polling
        if ($this->should_poll()) {
            $settings['interval'] = HIC_CONTINUOUS_POLLING_INTERVAL; // Run every minute for watchdog
        }
        return $settings;
    }
    
    /**
     * Use WordPress heartbeat for watchdog functionality
     */
    public function heartbeat_watchdog($response, $data) {
        // Only run watchdog if we should be polling
        if (!$this->should_poll()) {
            return $response;
        }
        
        // Run watchdog check every heartbeat
        $this->run_watchdog_check();
        
        // Add polling status to heartbeat response for debugging
        $response['hic_polling_status'] = array(
            'last_continuous' => get_option('hic_last_continuous_poll', 0),
            'last_deep' => get_option('hic_last_deep_check', 0),
            'wp_cron_working' => $this->is_wp_cron_working(),
            'time' => time()
        );
        
        return $response;
    }
    
    /**
     * Admin watchdog check - runs when admin pages are loaded
     */
    public function admin_watchdog_check() {
        // Only run on HIC admin pages to avoid unnecessary overhead
        if (!isset($_GET['page']) || strpos(sanitize_text_field($_GET['page']), 'hic') === false) {
            return;
        }
        
        // Run a lightweight watchdog check
        if ($this->should_poll()) {
            $current_time = time();
            $last_continuous = get_option('hic_last_continuous_poll', 0);
            
            // If polling hasn't run in more than 5 minutes, trigger recovery
            if ($current_time - $last_continuous > HIC_WATCHDOG_THRESHOLD) {
                Helpers\hic_log("Admin Watchdog: Detected polling failure during admin page load (lag: " . ($current_time - $last_continuous) . "s), triggering recovery");
                $this->run_watchdog_check();
            }
            
            // Also check if events are scheduled at all
            $continuous_next = Helpers\hic_safe_wp_next_scheduled('hic_continuous_poll_event');
            if (!$continuous_next) {
                Helpers\hic_log("Admin Watchdog: No continuous polling event scheduled, restarting scheduler");
                $this->ensure_scheduler_is_active();
            }
        }
    }
    
    /**
     * Fallback polling check - triggers on every page load as last resort
     */
    public function fallback_polling_check() {
        // Only run as fallback if polling should be active but WP-Cron isn't working
        if (!$this->should_poll()) {
            return;
        }
        
        // Check if WP-Cron is working - if so, don't interfere
        if ($this->is_wp_cron_working()) {
            return;
        }
        
        $current_time = time();
        $last_continuous = get_option('hic_last_continuous_poll', 0);
        
        // If WP-Cron is not working and polling is severely delayed (>10 minutes), run fallback
        if ($current_time - $last_continuous > HIC_DEEP_CHECK_INTERVAL) {
            Helpers\hic_log("Fallback: WP-Cron not working and polling severely delayed, running fallback polling");
            
            // Use a transient to prevent multiple simultaneous executions
            $fallback_lock = get_transient('hic_fallback_polling_lock');
            if (!$fallback_lock) {
                set_transient('hic_fallback_polling_lock', time(), 120); // 2-minute lock
                
                // Run polling in background (don't block page load)
                wp_schedule_single_event(time() + 5, 'hic_fallback_poll_event');
                add_action('hic_fallback_poll_event', array($this, 'execute_fallback_polling'));
                
                Helpers\hic_log("Fallback: Scheduled fallback polling event");
            }
        }
    }
    
    /**
     * Shutdown polling check - very lightweight check on page end
     */
    public function shutdown_polling_check() {
        // Only run if polling should be active and we're not in admin
        if (!$this->should_poll() || is_admin()) {
            return;
        }
        
        // Very quick check - just log if polling seems to be failing
        $current_time = time();
        $last_continuous = get_option('hic_last_continuous_poll', 0);
        
        if ($current_time - $last_continuous > 1800) { // 30 minutes lag
            Helpers\hic_log("Shutdown Check: Severe polling lag detected ({$current_time} - {$last_continuous} = " . ($current_time - $last_continuous) . "s)");
        }
    }
    
    /**
     * Execute fallback polling when WP-Cron completely fails
     */
    public function execute_fallback_polling() {
        Helpers\hic_log("Fallback: Executing fallback polling due to WP-Cron failure");
        
        try {
            // Try to restart the scheduler first
            $this->ensure_scheduler_is_active();
            
            // Execute continuous polling
            $this->execute_continuous_polling();
            
            // Also run deep check if it's been a while
            $current_time = time();
            $last_deep = get_option('hic_last_deep_check', 0);
            if ($current_time - $last_deep > 1800) { // 30 minutes
                $this->execute_deep_check();
            }
            
            Helpers\hic_log("Fallback: Fallback polling completed successfully");
        } catch (Exception $e) {
            Helpers\hic_log("Fallback: Error during fallback polling: " . $e->getMessage());
        }
    }
    
    /**
     * Execute continuous polling (every minute)
     * Checks for recent reservations and manual bookings
     */
    public function execute_continuous_polling() {
        Helpers\hic_log("Scheduler: Executing continuous polling (1-minute interval)");
        
        // Update timestamp first to prevent overlapping executions
        update_option('hic_last_continuous_poll', time());
        
        if (function_exists('hic_api_poll_bookings_continuous')) {
            hic_api_poll_bookings_continuous();
        } elseif (function_exists('hic_api_poll_bookings')) {
            // Fallback to existing function if new one doesn't exist yet
            hic_api_poll_bookings();
        }
    }
    
    /**
     * Execute deep check (every 10 minutes)
     * Looks back HIC_DEEP_CHECK_LOOKBACK_DAYS days to catch any missed reservations
     */
    public function execute_deep_check() {
        Helpers\hic_log("Scheduler: Executing deep check (10-minute interval, " . HIC_DEEP_CHECK_LOOKBACK_DAYS . "-day lookback)");
        
        // Update timestamp first to prevent overlapping executions
        update_option('hic_last_deep_check', time());
        
        if (function_exists('hic_api_poll_bookings_deep_check')) {
            hic_api_poll_bookings_deep_check();
        } else {
            // Fallback implementation
            $this->fallback_deep_check();
        }
    }
    
    /**
     * Fallback deep check implementation
     */
    private function fallback_deep_check() {
        $prop_id = Helpers\hic_get_property_id();
        if (empty($prop_id)) {
            Helpers\hic_log("Deep check: No property ID configured");
            return;
        }
        
        $lookback_seconds = HIC_DEEP_CHECK_LOOKBACK_DAYS * DAY_IN_SECONDS;
        $from_date = date('Y-m-d', time() - $lookback_seconds);
        $to_date = date('Y-m-d', time());
        
        Helpers\hic_log("Deep check: Searching for reservations from $from_date to $to_date (property: $prop_id)");
        
        // Check by check-in date to catch manual bookings and any missed ones
        if (function_exists('hic_fetch_reservations_raw')) {
            $reservations = hic_fetch_reservations_raw($prop_id, 'checkin', $from_date, $to_date, 200);
            if (!is_wp_error($reservations) && is_array($reservations)) {
                $count = count($reservations);
                Helpers\hic_log("Deep check: Found $count reservations in " . HIC_DEEP_CHECK_LOOKBACK_DAYS . "-day lookback period");
            }
        }
    }
    /**
     * Check if polling should be active based on configuration
     */
    private function should_poll() {
        return Helpers\hic_reliable_polling_enabled() && 
               Helpers\hic_get_connection_type() === 'api' && 
               Helpers\hic_get_api_url() && 
               Helpers\hic_has_basic_auth_credentials();
    }
    
    /**
     * Get polling interval in seconds (simplified - always 1 minute for continuous)
     */
    private function get_polling_interval_seconds() {
        return HIC_CONTINUOUS_POLLING_INTERVAL;
    }
    
    /**
     * Watchdog check for debugging and monitoring
     */
    private function watchdog_check($last_poll, $current_time) {
        $lag = $current_time - $last_poll;
        
        if ($lag > HIC_WATCHDOG_THRESHOLD) {
            Helpers\hic_log("Heartbeat Scheduler Watchdog: Polling lag detected - {$lag}s since last poll (threshold: " . HIC_WATCHDOG_THRESHOLD . "s)");
        }
    }
    
    /**
     * Execute polling manually - for CLI and admin interface
     * Uses the new continuous polling method
     */
    public function execute_poll() {
        $start_time = microtime(true);
        Helpers\hic_log('Manual polling execution started');
        
        if (!$this->should_poll()) {
            $error = 'Polling conditions not met. Check credentials and connection type.';
            Helpers\hic_log('Manual polling failed: ' . $error);
            return array('success' => false, 'message' => $error);
        }
        
        try {
            // Execute continuous polling
            $this->execute_continuous_polling();
            
            $execution_time = round(microtime(true) - $start_time, 2);
            $message = "Manual polling completed in {$execution_time}s";
            Helpers\hic_log($message);
            
            return array(
                'success' => true, 
                'message' => $message,
                'execution_time' => $execution_time
            );
        } catch (Exception $e) {
            $error = 'Polling execution failed: ' . $e->getMessage();
            Helpers\hic_log($error);
            return array('success' => false, 'message' => $error);
        }
    }
    
    /**
     * Force execute polling (bypassing locks for manual execution)
     */
    public function force_execute_poll() {
        $start_time = microtime(true);
        Helpers\hic_log('Force manual polling execution started');
        
        if (!$this->should_poll()) {
            $error = 'Polling conditions not met. Check credentials and connection type.';
            Helpers\hic_log('Force manual polling failed: ' . $error);
            return array('success' => false, 'message' => $error);
        }
        
        try {
            // Temporarily clear the lock to allow forced execution
            $lock_cleared = false;
            if (get_transient('hic_reliable_polling_lock')) {
                delete_transient('hic_reliable_polling_lock');
                $lock_cleared = true;
                Helpers\hic_log('Cleared existing polling lock for force execution');
            }
            
            // Execute continuous polling
            $this->execute_continuous_polling();
            
            $execution_time = round(microtime(true) - $start_time, 2);
            $message = "Force manual polling completed in {$execution_time}s";
            if ($lock_cleared) {
                $message .= " (lock was cleared)";
            }
            Helpers\hic_log($message);
            
            return array(
                'success' => true, 
                'message' => $message,
                'execution_time' => $execution_time,
                'lock_cleared' => $lock_cleared
            );
        } catch (Exception $e) {
            $error = 'Force polling execution failed: ' . $e->getMessage();
            Helpers\hic_log($error);
            return array('success' => false, 'message' => $error);
        }
    }

    /**
     * Perform polling and return statistics.
     *
     * Exposes the core polling routine so it can be used by CLI commands
     * without relying on reflection. Any existing locks are cleared before
     * execution to ensure the poll actually runs.
     *
     * @return array Polling statistics
     */
    public function perform_polling() {
        // Clear potential locks that would prevent manual execution
        if (function_exists('delete_transient')) {
            delete_transient('hic_polling_lock');
            delete_transient('hic_reliable_polling_lock');
        }

        // Execute the polling process
        $this->execute_continuous_polling();

        // Return current stats for convenience
        return $this->get_stats();
    }

    /**
     * Structured logging wrapper.
     *
     * Provides a public method for logging structured data primarily for
     * CLI interactions.
     *
     * @param string $event  Event name
     * @param array  $data   Additional context data
     * @return void
     */
    public function log_structured($event, $data = array()) {
        Helpers\hic_log($event, HIC_LOG_LEVEL_INFO, is_array($data) ? $data : array());
    }
    
    /**
     * Get detailed diagnostics including polling conditions
     */
    public function get_detailed_diagnostics() {
        $base_stats = $this->get_stats();
        
        // Add detailed condition checks
        $diagnostics = array_merge($base_stats, array(
            'conditions' => array(
                'reliable_polling_enabled' => Helpers\hic_reliable_polling_enabled(),
                'connection_type_api' => Helpers\hic_get_connection_type() === 'api',
                'api_url_configured' => !empty(Helpers\hic_get_api_url()),
                'has_credentials' => Helpers\hic_has_basic_auth_credentials(),
                'basic_auth_complete' => Helpers\hic_has_basic_auth_credentials(),
                'credentials_type' => Helpers\hic_has_basic_auth_credentials() ? 'basic_auth' : 'none'
            ),
            'configuration' => array(
                'connection_type' => Helpers\hic_get_connection_type(),
                'api_url' => !empty(Helpers\hic_get_api_url()) ? 'configured' : 'missing',
                'property_id' => !empty(Helpers\hic_get_property_id()) ? 'configured' : 'missing',
                'api_email' => !empty(Helpers\hic_get_api_email()) ? 'configured' : 'missing',
                'api_password' => !empty(Helpers\hic_get_api_password()) ? 'configured' : 'missing'
            ),
            'lock_status' => array(
                'active' => get_transient('hic_reliable_polling_lock') ? true : false,
                'timestamp' => get_transient('hic_reliable_polling_lock') ?: null
            )
        ));
        
        return $diagnostics;
    }
    
    /**
     * Get polling statistics for diagnostics
     */
    public function get_stats() {
        $last_continuous = get_option('hic_last_continuous_poll', 0);
        $last_deep = get_option('hic_last_deep_check', 0);
        $last_general = get_option('hic_last_api_poll', 0);
        
        // Check scheduler type - WP-Cron only now
        $is_wp_cron_working = $this->is_wp_cron_working();
        $scheduler_type = $is_wp_cron_working ? 'WP-Cron' : 'Non attivo';
        
        // Get detailed polling conditions
        $should_poll = $this->should_poll();
        $reliable_polling = Helpers\hic_reliable_polling_enabled();
        $connection_type = Helpers\hic_get_connection_type();
        $api_url = Helpers\hic_get_api_url();
        $has_auth = Helpers\hic_has_basic_auth_credentials();
        
        $stats = array(
            'scheduler_type' => $scheduler_type,
            'wp_cron_working' => $is_wp_cron_working,
            'should_poll' => $should_poll,
            'polling_conditions' => array(
                'reliable_polling_enabled' => $reliable_polling,
                'connection_type' => $connection_type,
                'api_url_set' => !empty($api_url),
                'has_auth_credentials' => $has_auth
            ),
            'last_poll' => $last_general,
            'last_poll_human' => $last_general > 0 ? human_time_diff($last_general) . ' fa' : 'Mai',
            'last_continuous_poll' => $last_continuous,
            'last_continuous_human' => $last_continuous > 0 ? human_time_diff($last_continuous) . ' fa' : 'Mai',
            'last_deep_check' => $last_deep,
            'last_deep_human' => $last_deep > 0 ? human_time_diff($last_deep) . ' fa' : 'Mai',
            'lag_seconds' => $last_general > 0 ? time() - $last_general : 0,
            'continuous_lag' => $last_continuous > 0 ? time() - $last_continuous : 0,
            'deep_lag' => $last_deep > 0 ? time() - $last_deep : 0,
            'polling_active' => $should_poll,
            'polling_interval' => HIC_CONTINUOUS_POLLING_INTERVAL,
            'deep_check_interval' => HIC_DEEP_CHECK_INTERVAL
        );
        
        // Add WP-Cron specific info (always show for debugging)
        $stats['next_continuous_scheduled'] = Helpers\hic_safe_wp_next_scheduled('hic_continuous_poll_event');
        $stats['next_deep_scheduled'] = Helpers\hic_safe_wp_next_scheduled('hic_deep_check_event');
        $stats['wp_cron_disabled'] = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        
        return $stats;
    }
}

/**
 * Initialize booking poller safely
 */
function hic_init_booking_poller() {
    if (function_exists('add_action') && function_exists('add_filter')) {
        new HIC_Booking_Poller();
    }
}

/**
 * Clean up scheduled events and temporary data on plugin deactivation
 */
function hic_deactivate() {
    $hooks = array(
        'hic_continuous_poll_event',
        'hic_deep_check_event',
        'hic_fallback_poll_event',
        'hic_health_monitor_event',
        'hic_performance_cleanup',
        'hic_reliable_poll_event',
        'hic_api_poll_event',
        'hic_api_updates_event',
        'hic_retry_failed_notifications_event',
    );

    foreach ($hooks as $hook) {
        wp_clear_scheduled_hook($hook);
    }

    $transients = array(
        'hic_polling_lock',
        'hic_reliable_polling_lock',
        'hic_fallback_polling_lock',
    );

    foreach ($transients as $transient) {
        delete_transient($transient);
    }

    global $wpdb;
    if (isset($wpdb)) {
        // Clear all hic_* transients including processing locks
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_hic_%' OR option_name LIKE '_transient_timeout_hic_%'");

        // Remove temporary options related to polling and locks
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'hic_last_%' OR option_name LIKE 'hic_%_lock'");
    }

    delete_option('hic_api_calls_today');
    delete_option('hic_successful_bookings_today');
    delete_option('hic_failed_bookings_today');
}

// Initialize booking poller when WordPress is ready
add_action('init', 'hic_init_booking_poller');
