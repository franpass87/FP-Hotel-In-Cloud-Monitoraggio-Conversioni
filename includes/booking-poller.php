<?php
/**
 * Internal Booking Scheduler - No WordPress Cron Dependency
 */

if (!defined('ABSPATH')) exit;

class HIC_Booking_Poller {
    
    const POLLING_INTERVAL = 300; // 5 minutes in seconds
    const WATCHDOG_THRESHOLD = 900; // 15 minutes in seconds
    
    public function __construct() {
        // Use internal scheduler that checks on every init
        add_action('init', array($this, 'internal_scheduler_check'));
    }
    
    /**
     * Internal scheduler check - runs on every WordPress init
     */
    public function internal_scheduler_check() {
        // Only run if we're not in admin and polling should be active
        if (is_admin() || !$this->should_poll()) {
            return;
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
                hic_log("Internal Scheduler: Triggering polling (last poll: {$time_since_poll}s ago)");
                hic_api_poll_bookings();
            }
        }
        
        // Run watchdog check for debugging
        $this->watchdog_check($last_poll, $current_time);
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
            hic_log("Internal Scheduler Watchdog: Polling lag detected - {$lag}s since last poll (threshold: " . self::WATCHDOG_THRESHOLD . "s)");
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