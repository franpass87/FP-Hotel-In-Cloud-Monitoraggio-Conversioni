<?php
/**
 * Internal Booking Scheduler - Uses WordPress Heartbeat API
 */

if (!defined('ABSPATH')) exit;

class HIC_Booking_Poller {
    
    const CONTINUOUS_POLLING_INTERVAL = 60; // 1 minute for continuous polling
    const DEEP_CHECK_INTERVAL = 600; // 10 minutes for deep check
    const DEEP_CHECK_LOOKBACK_DAYS = 5; // Look back 5 days in deep check
    const WATCHDOG_THRESHOLD = 300; // 5 minutes threshold
    
    public function __construct() {
        // Use WordPress Heartbeat API for efficient periodic checks
        add_filter('heartbeat_received', array($this, 'heartbeat_scheduler_check'), 10, 2);
        add_filter('heartbeat_settings', array($this, 'heartbeat_settings'));
    }
    
    /**
     * Configure Heartbeat settings for optimal polling
     */
    public function heartbeat_settings($settings) {
        // Set heartbeat interval to 60 seconds for continuous polling
        $settings['interval'] = 60;
        return $settings;
    }
    
    /**
     * Heartbeat scheduler check - runs via WordPress Heartbeat API
     * Implements simplified dual-mode polling:
     * - Continuous polling every minute
     * - Deep check every 10 minutes looking back 5 days
     */
    public function heartbeat_scheduler_check($response, $data) {
        // Only run if polling should be active
        if (!$this->should_poll()) {
            return $response;
        }
        
        $current_time = time();
        $last_continuous_poll = get_option('hic_last_continuous_poll', 0);
        $last_deep_check = get_option('hic_last_deep_check', 0);
        
        // Check if it's time for continuous polling (every minute)
        $time_since_continuous = $current_time - $last_continuous_poll;
        if ($time_since_continuous >= self::CONTINUOUS_POLLING_INTERVAL) {
            $this->execute_continuous_polling();
            update_option('hic_last_continuous_poll', $current_time);
        }
        
        // Check if it's time for deep check (every 10 minutes)
        $time_since_deep = $current_time - $last_deep_check;
        if ($time_since_deep >= self::DEEP_CHECK_INTERVAL) {
            $this->execute_deep_check();
            update_option('hic_last_deep_check', $current_time);
        }
        
        // Update general timestamp for compatibility
        update_option('hic_last_api_poll', $current_time);
        
        // Run watchdog check for debugging
        $this->watchdog_check($last_continuous_poll, $current_time);
        
        return $response;
    }
    
    /**
     * Execute continuous polling (every minute)
     * Checks for recent reservations and manual bookings
     */
    private function execute_continuous_polling() {
        hic_log("Heartbeat Scheduler: Executing continuous polling (1-minute interval)");
        
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
        hic_log("Heartbeat Scheduler: Executing deep check (10-minute interval, 5-day lookback)");
        
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
        
        // Check by creation date to catch manual bookings and any missed ones
        if (function_exists('hic_fetch_reservations')) {
            $reservations = hic_fetch_reservations($prop_id, 'created', $from_date, $to_date, 200);
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
               (hic_has_basic_auth_credentials() || hic_get_api_key());
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
                'api_key_configured' => !empty(hic_get_api_key())
            ),
            'configuration' => array(
                'connection_type' => hic_get_connection_type(),
                'api_url' => hic_get_api_url() ? 'configured' : 'missing',
                'property_id' => hic_get_property_id() ? 'configured' : 'missing',
                'api_email' => hic_get_api_email() ? 'configured' : 'missing',
                'api_password' => hic_get_api_password() ? 'configured' : 'missing',
                'api_key' => hic_get_api_key() ? 'configured' : 'missing'
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
        
        $stats = array(
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
        
        return $stats;
    }
}

// Initialize the poller
new HIC_Booking_Poller();