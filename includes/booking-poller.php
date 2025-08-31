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
     * Execute polling manually - for CLI and admin interface
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
            // Call the main polling function directly
            if (function_exists('hic_api_poll_bookings')) {
                hic_api_poll_bookings();
                $execution_time = round(microtime(true) - $start_time, 2);
                
                $message = "Manual polling completed in {$execution_time}s";
                hic_log($message);
                
                return array(
                    'success' => true, 
                    'message' => $message,
                    'execution_time' => $execution_time
                );
            } else {
                $error = 'hic_api_poll_bookings function not found';
                hic_log('Manual polling failed: ' . $error);
                return array('success' => false, 'message' => $error);
            }
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
            
            // Call the main polling function directly
            if (function_exists('hic_api_poll_bookings')) {
                hic_api_poll_bookings();
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
            } else {
                $error = 'hic_api_poll_bookings function not found';
                hic_log('Force manual polling failed: ' . $error);
                return array('success' => false, 'message' => $error);
            }
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