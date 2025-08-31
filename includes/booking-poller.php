<?php
/**
 * Internal Booking Scheduler - Uses WordPress Heartbeat API
 */

if (!defined('ABSPATH')) exit;

class HIC_Booking_Poller {
    
    const POLLING_INTERVAL = 300; // 5 minutes in seconds
    const WATCHDOG_THRESHOLD = 900; // 15 minutes in seconds
    
    public function __construct() {
        // Use WordPress Heartbeat API for efficient periodic checks
        add_filter('heartbeat_received', array($this, 'heartbeat_scheduler_check'), 10, 2);
        add_filter('heartbeat_settings', array($this, 'heartbeat_settings'));
    }
    
    /**
     * Configure Heartbeat settings for optimal polling
     */
    public function heartbeat_settings($settings) {
        // Set heartbeat interval to 60 seconds for efficient polling checks
        $settings['interval'] = 60;
        return $settings;
    }
    
    /**
     * Heartbeat scheduler check - runs via WordPress Heartbeat API
     */
    public function heartbeat_scheduler_check($response, $data) {
        // Only run if polling should be active
        if (!$this->should_poll()) {
            return $response;
        }
        
        $last_poll = get_option('hic_last_api_poll', 0);
        $current_time = time();
        $time_since_poll = $current_time - $last_poll;
        
        // Get configured interval or use default
        $polling_interval = $this->get_polling_interval_seconds();
        
        // Check if it's time to poll
        if ($time_since_poll >= $polling_interval) {
            // Use the existing polling system
            if (function_exists('hic_api_poll_bookings')) {
                hic_log("Heartbeat Scheduler: Triggering polling (last poll: {$time_since_poll}s ago)");
                hic_api_poll_bookings();
            }
        }
        
        // Run watchdog check for debugging
        $this->watchdog_check($last_poll, $current_time);
        
        return $response;
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
     * Get polling interval in seconds
     */
    private function get_polling_interval_seconds() {
        $interval_name = hic_get_polling_interval();
        
        // Map interval names to seconds
        $intervals = array(
            'every_minute' => 60,
            'every_two_minutes' => 120,
            'hic_reliable_interval' => 300
        );
        
        return isset($intervals[$interval_name]) ? $intervals[$interval_name] : self::POLLING_INTERVAL;
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
     * Get polling statistics for diagnostics
     */
    public function get_stats() {
        $last_poll = get_option('hic_last_api_poll', 0);
        $last_successful = get_option('hic_last_successful_poll', 0);
        
        $stats = array(
            'last_poll' => $last_poll,
            'last_poll_human' => $last_poll > 0 ? human_time_diff($last_poll) . ' fa' : 'Mai',
            'lag_seconds' => $last_poll > 0 ? time() - $last_poll : 0,
            'last_successful_poll' => $last_successful,
            'polling_active' => $this->should_poll(),
            'polling_interval' => $this->get_polling_interval_seconds()
        );
        
        return $stats;
    }
}

// Initialize the poller
new HIC_Booking_Poller();