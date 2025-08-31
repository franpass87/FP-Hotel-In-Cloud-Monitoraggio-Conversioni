<?php
/**
 * Internal Booking Scheduler - WP-Cron System
 */

if (!defined('ABSPATH')) exit;

class HIC_Booking_Poller {
    
    const CONTINUOUS_POLLING_INTERVAL = 60; // 1 minute for continuous polling
    const DEEP_CHECK_INTERVAL = 600; // 10 minutes for deep check
    const DEEP_CHECK_LOOKBACK_DAYS = 5; // Look back 5 days in deep check
    const WATCHDOG_THRESHOLD = 300; // 5 minutes threshold
    
    public function __construct() {
        // WP-Cron system for reliable 24/7 operation
        add_action('hic_continuous_poll_event', array($this, 'execute_continuous_polling'));
        add_action('hic_deep_check_event', array($this, 'execute_deep_check'));
        
        // Initialize scheduler on activation
        add_action('init', array($this, 'ensure_scheduler_is_active'), 20);
    }
    
    /**
     * Ensure the scheduler is active - uses WP-Cron system
     */
    public function ensure_scheduler_is_active() {
        if (!$this->should_poll()) {
            // Clear any existing events if conditions aren't met
            $this->clear_all_scheduled_events();
            return;
        }
        
        // Schedule WP-Cron events if they don't exist
        if (!wp_next_scheduled('hic_continuous_poll_event')) {
            wp_schedule_event(time(), 'hic_every_minute', 'hic_continuous_poll_event');
            hic_log('WP-Cron Scheduler: Scheduled continuous polling every minute');
        }
        
        if (!wp_next_scheduled('hic_deep_check_event')) {
            wp_schedule_event(time(), 'hic_every_ten_minutes', 'hic_deep_check_event');
            hic_log('WP-Cron Scheduler: Scheduled deep check every 10 minutes');
        }
        
        // Register custom intervals if they don't exist
        add_filter('cron_schedules', array($this, 'add_custom_cron_intervals'));
    }
    
    /**
     * Add custom WP-Cron intervals
     */
    public function add_custom_cron_intervals($schedules) {
        $schedules['hic_every_minute'] = array(
            'interval' => 60,
            'display' => 'Every Minute (HIC Continuous Polling)'
        );
        $schedules['hic_every_ten_minutes'] = array(
            'interval' => 600,
            'display' => 'Every 10 Minutes (HIC Deep Check)'
        );
        return $schedules;
    }
    
    /**
     * Clear all scheduled WP-Cron events
     */
    public function clear_all_scheduled_events() {
        wp_clear_scheduled_hook('hic_continuous_poll_event');
        wp_clear_scheduled_hook('hic_deep_check_event');
        hic_log('WP-Cron Scheduler: Cleared all scheduled events');
    }
    
    /**
     * Check if WP-Cron is working
     */
    public function is_wp_cron_working() {
        // Check if WP-Cron is disabled
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            return false;
        }
        
        // Check if events are scheduled
        $continuous_next = wp_next_scheduled('hic_continuous_poll_event');
        $deep_next = wp_next_scheduled('hic_deep_check_event');
        
        return ($continuous_next !== false && $deep_next !== false);
    }
    
    /**
     * Execute continuous polling (every minute)
     * Checks for recent reservations and manual bookings
     */
    private function execute_continuous_polling() {
        hic_log("Scheduler: Executing continuous polling (1-minute interval)");
        
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
     * Looks back 5 days to catch any missed reservations
     */
    private function execute_deep_check() {
        hic_log("Scheduler: Executing deep check (10-minute interval, 5-day lookback)");
        
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
        $prop_id = hic_get_property_id();
        if (empty($prop_id)) {
            hic_log("Deep check: No property ID configured");
            return;
        }
        
        $lookback_seconds = self::DEEP_CHECK_LOOKBACK_DAYS * DAY_IN_SECONDS;
        $from_date = date('Y-m-d', time() - $lookback_seconds);
        $to_date = date('Y-m-d', time());
        
        hic_log("Deep check: Searching for reservations from $from_date to $to_date (property: $prop_id)");
        
        // Check by check-in date to catch manual bookings and any missed ones
        if (function_exists('hic_fetch_reservations_raw')) {
            $reservations = hic_fetch_reservations_raw($prop_id, 'checkin', $from_date, $to_date, 200);
            if (!is_wp_error($reservations) && is_array($reservations)) {
                $count = count($reservations);
                hic_log("Deep check: Found $count reservations in 5-day lookback period");
            }
        }
    }
    /**
     * Check if polling should be active based on configuration
     */
    private function should_poll() {
        return hic_reliable_polling_enabled() && 
               hic_get_connection_type() === 'api' && 
               hic_get_api_url() && 
               (hic_has_basic_auth_credentials() || !empty(hic_get_api_key()));
    }
    
    /**
     * Get polling interval in seconds (simplified - always 1 minute for continuous)
     */
    private function get_polling_interval_seconds() {
        return self::CONTINUOUS_POLLING_INTERVAL;
    }
    
    /**
     * Watchdog check for debugging and monitoring
     */
    private function watchdog_check($last_poll, $current_time) {
        $lag = $current_time - $last_poll;
        
        if ($lag > self::WATCHDOG_THRESHOLD) {
            hic_log("Heartbeat Scheduler Watchdog: Polling lag detected - {$lag}s since last poll (threshold: " . self::WATCHDOG_THRESHOLD . "s)");
        }
    }
    
    /**
     * Execute polling manually - for CLI and admin interface
     * Uses the new continuous polling method
     */
    public function execute_poll() {
        $start_time = microtime(true);
        hic_log('Manual polling execution started');
        
        if (!$this->should_poll()) {
            $error = 'Polling conditions not met. Check credentials and connection type.';
            hic_log('Manual polling failed: ' . $error);
            return array('success' => false, 'message' => $error);
        }
        
        try {
            // Execute continuous polling
            $this->execute_continuous_polling();
            
            $execution_time = round(microtime(true) - $start_time, 2);
            $message = "Manual polling completed in {$execution_time}s";
            hic_log($message);
            
            return array(
                'success' => true, 
                'message' => $message,
                'execution_time' => $execution_time
            );
        } catch (Exception $e) {
            $error = 'Polling execution failed: ' . $e->getMessage();
            hic_log($error);
            return array('success' => false, 'message' => $error);
        }
    }
    
    /**
     * Force execute polling (bypassing locks for manual execution)
     */
    public function force_execute_poll() {
        $start_time = microtime(true);
        hic_log('Force manual polling execution started');
        
        if (!$this->should_poll()) {
            $error = 'Polling conditions not met. Check credentials and connection type.';
            hic_log('Force manual polling failed: ' . $error);
            return array('success' => false, 'message' => $error);
        }
        
        try {
            // Temporarily clear the lock to allow forced execution
            $lock_cleared = false;
            if (get_transient('hic_reliable_polling_lock')) {
                delete_transient('hic_reliable_polling_lock');
                $lock_cleared = true;
                hic_log('Cleared existing polling lock for force execution');
            }
            
            // Execute continuous polling
            $this->execute_continuous_polling();
            
            $execution_time = round(microtime(true) - $start_time, 2);
            $message = "Force manual polling completed in {$execution_time}s";
            if ($lock_cleared) {
                $message .= " (lock was cleared)";
            }
            hic_log($message);
            
            return array(
                'success' => true, 
                'message' => $message,
                'execution_time' => $execution_time,
                'lock_cleared' => $lock_cleared
            );
        } catch (Exception $e) {
            $error = 'Force polling execution failed: ' . $e->getMessage();
            hic_log($error);
            return array('success' => false, 'message' => $error);
        }
    }
    
    /**
     * Get detailed diagnostics including polling conditions
     */
    public function get_detailed_diagnostics() {
        $base_stats = $this->get_stats();
        
        // Add detailed condition checks
        $diagnostics = array_merge($base_stats, array(
            'conditions' => array(
                'reliable_polling_enabled' => hic_reliable_polling_enabled(),
                'connection_type_api' => hic_get_connection_type() === 'api',
                'api_url_configured' => !empty(hic_get_api_url()),
                'has_credentials' => hic_has_basic_auth_credentials() || !empty(hic_get_api_key()),
                'basic_auth_complete' => hic_has_basic_auth_credentials(),
                'api_key_configured' => !empty(hic_get_api_key()),
                'credentials_type' => hic_has_basic_auth_credentials() ? 'basic_auth' : (!empty(hic_get_api_key()) ? 'api_key' : 'none')
            ),
            'configuration' => array(
                'connection_type' => hic_get_connection_type(),
                'api_url' => !empty(hic_get_api_url()) ? 'configured' : 'missing',
                'property_id' => !empty(hic_get_property_id()) ? 'configured' : 'missing',
                'api_email' => !empty(hic_get_api_email()) ? 'configured' : 'missing',
                'api_password' => !empty(hic_get_api_password()) ? 'configured' : 'missing',
                'api_key' => !empty(hic_get_api_key()) ? 'configured' : 'missing'
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
        
        $stats = array(
            'scheduler_type' => $scheduler_type,
            'wp_cron_working' => $is_wp_cron_working,
            'last_poll' => $last_general,
            'last_poll_human' => $last_general > 0 ? human_time_diff($last_general) . ' fa' : 'Mai',
            'last_continuous_poll' => $last_continuous,
            'last_continuous_human' => $last_continuous > 0 ? human_time_diff($last_continuous) . ' fa' : 'Mai',
            'last_deep_check' => $last_deep,
            'last_deep_human' => $last_deep > 0 ? human_time_diff($last_deep) . ' fa' : 'Mai',
            'lag_seconds' => $last_general > 0 ? time() - $last_general : 0,
            'continuous_lag' => $last_continuous > 0 ? time() - $last_continuous : 0,
            'deep_lag' => $last_deep > 0 ? time() - $last_deep : 0,
            'polling_active' => $this->should_poll(),
            'polling_interval' => self::CONTINUOUS_POLLING_INTERVAL,
            'deep_check_interval' => self::DEEP_CHECK_INTERVAL
        );
        
        // Add WP-Cron specific info
        if ($is_wp_cron_working) {
            $stats['next_continuous_scheduled'] = wp_next_scheduled('hic_continuous_poll_event');
            $stats['next_deep_scheduled'] = wp_next_scheduled('hic_deep_check_event');
        }
        
        return $stats;
    }
}

// Initialize the poller
new HIC_Booking_Poller();