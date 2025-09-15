<?php declare(strict_types=1);

namespace FpHic;

/**
 * Internal Booking Scheduler - WP-Cron System
 */

if (!defined('ABSPATH')) exit;

class HIC_Booking_Poller {

    const SCHEDULER_RESTART_LOCK_KEY = 'hic_scheduler_restart_lock';
    const SCHEDULER_RESTART_LOCK_TTL = 120; // 2 minutes
    
    public function __construct() {
        // Only initialize if WordPress functions are available
        if (!function_exists('add_filter') || !function_exists('add_action')) {
            return;
        }
        
        // Register custom cron intervals first - always available for cron managers
        add_filter('cron_schedules', array($this, 'add_custom_cron_intervals'));
        
        // WP-Cron system for reliable 24/7 operation
        add_action('hic_continuous_poll_event', array($this, 'execute_continuous_polling'));
        // Deep check re-enabled for 30-minute safety checks
        add_action('hic_deep_check_event', array($this, 'execute_deep_check'));
        add_action('hic_fallback_poll_event', array($this, 'execute_fallback_polling'));
        add_action('hic_cleanup_event', 'hic_cleanup_old_gclids');
        add_action('hic_booking_events_cleanup', 'hic_cleanup_booking_events');
        add_action('hic_scheduler_restart', array($this, 'ensure_scheduler_is_active'));
        
        // ENHANCED: Multiple scheduler activation points for better reliability
        add_action('init', array($this, 'ensure_scheduler_is_active'), 20);
        add_action('wp', array($this, 'proactive_scheduler_check'), 5); // Runs on frontend AND backend
        
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
     * Enhanced to detect and recover from dormant states
     */
    public function ensure_scheduler_is_active() {
        if (!$this->should_poll()) {
            hic_log('Scheduler conditions not met, clearing all scheduled events');
            // Clear any existing events if conditions aren't met
            $this->clear_all_scheduled_events();
            return;
        }
        
        $current_time = time();
        $last_continuous = get_option('hic_last_continuous_poll', 0);
        
        // ENHANCED: Check for dormancy indicators (with re-enabled 30-minute deep check)
        $polling_lag = $current_time - $last_continuous;
        $dormancy_threshold = 3600; // 1 hour indicates potential dormancy
        
        $is_dormant = ($polling_lag > $dormancy_threshold);
        
        if ($is_dormant) {
            hic_log("Scheduler: Detected dormant scheduler (polling lag: {$polling_lag}s) - forcing complete restart");
            // Clear all events and reschedule fresh
            $this->clear_all_scheduled_events();
            // Schedule asynchronous restart instead of blocking sleep
            $this->schedule_scheduler_restart();
            return;
        }
        
        // Check and schedule continuous polling event
        $continuous_next = \FpHic\Helpers\hic_safe_wp_next_scheduled('hic_continuous_poll_event');
        if (!$continuous_next) {
            $scheduled = \FpHic\Helpers\hic_safe_wp_schedule_event(time(), 'hic_every_thirty_seconds', 'hic_continuous_poll_event');
            if ($scheduled) {
                hic_log('WP-Cron Scheduler: Scheduled continuous polling every 30 seconds (near real-time) with 30-minute deep check');
            } else {
                hic_log('WP-Cron Scheduler: FAILED to schedule continuous polling event');
            }
        } else {
            // ENHANCED: Be more aggressive about rescheduling overdue events
            $overdue_threshold = $is_dormant ? 60 : 120; // 1 minute if dormant, 2 minutes normally
            if ($continuous_next < (time() - $overdue_threshold)) {
                hic_log('WP-Cron Scheduler: Continuous polling event is overdue, rescheduling');
                \FpHic\Helpers\hic_safe_wp_clear_scheduled_hook('hic_continuous_poll_event');
                \FpHic\Helpers\hic_safe_wp_schedule_event(time(), 'hic_every_thirty_seconds', 'hic_continuous_poll_event');
            }
        }
        
        // Deep check re-enabled with 30-minute interval for safety
        $deep_next = \FpHic\Helpers\hic_safe_wp_next_scheduled('hic_deep_check_event');
        if (!$deep_next) {
            $scheduled = \FpHic\Helpers\hic_safe_wp_schedule_event(time(), 'hic_every_thirty_minutes', 'hic_deep_check_event');
            if ($scheduled) {
                hic_log('WP-Cron Scheduler: Scheduled deep check every 30 minutes (re-enabled for safety)');
            } else {
                hic_log('WP-Cron Scheduler: FAILED to schedule deep check event');
            }
        } else {
            // Check if existing event is overdue and reschedule if needed
            $overdue_threshold = $is_dormant ? 1800 : 3600; // 30 minutes if dormant, 1 hour normally
            if ($deep_next < (time() - $overdue_threshold)) {
                hic_log('WP-Cron Scheduler: Deep check event is overdue, rescheduling');
                \FpHic\Helpers\hic_safe_wp_clear_scheduled_hook('hic_deep_check_event');
                \FpHic\Helpers\hic_safe_wp_schedule_event(time(), 'hic_every_thirty_minutes', 'hic_deep_check_event');
            }
        }

        // Schedule daily cleanup event
        $cleanup_next = \FpHic\Helpers\hic_safe_wp_next_scheduled('hic_cleanup_event');
        if (!$cleanup_next) {
            $scheduled = \FpHic\Helpers\hic_safe_wp_schedule_event(time(), 'daily', 'hic_cleanup_event');
            if ($scheduled) {
                hic_log('WP-Cron Scheduler: Scheduled daily cleanup event');
            } else {
                hic_log('WP-Cron Scheduler: FAILED to schedule cleanup event');
            }
        }

        // Schedule booking events cleanup
        $booking_cleanup_next = \FpHic\Helpers\hic_safe_wp_next_scheduled('hic_booking_events_cleanup');
        if (!$booking_cleanup_next) {
            $scheduled = \FpHic\Helpers\hic_safe_wp_schedule_event(time(), 'daily', 'hic_booking_events_cleanup');
            if ($scheduled) {
                hic_log('WP-Cron Scheduler: Scheduled booking events cleanup event');
            } else {
                hic_log('WP-Cron Scheduler: FAILED to schedule booking events cleanup event');
            }
        }
        
        // Log current scheduling status
        $this->log_scheduler_status();

        if ($this->are_scheduler_events_scheduled()) {
            $this->clear_scheduler_restart_lock();
        }
    }
    
    /**
     * Add custom WP-Cron intervals
     */
    public function add_custom_cron_intervals($schedules) {
        $schedules['hic_every_thirty_seconds'] = array(
            'interval' => HIC_CONTINUOUS_POLLING_INTERVAL,
            'display' => 'Every 30 Seconds (HIC Near Real-Time Polling)'
        );
        // Deep check interval re-enabled with 30-minute interval
        $schedules['hic_every_thirty_minutes'] = array(
            'interval' => HIC_DEEP_CHECK_INTERVAL,
            'display' => 'Every 30 Minutes (HIC Deep Check)'
        );
        return $schedules;
    }
    
    /**
     * Clear all scheduled WP-Cron events
     */
    public function clear_all_scheduled_events() {
        \FpHic\Helpers\hic_safe_wp_clear_scheduled_hook('hic_continuous_poll_event');
        \FpHic\Helpers\hic_safe_wp_clear_scheduled_hook('hic_deep_check_event');
        \FpHic\Helpers\hic_safe_wp_clear_scheduled_hook('hic_cleanup_event');
        \FpHic\Helpers\hic_safe_wp_clear_scheduled_hook('hic_booking_events_cleanup');
        hic_log('WP-Cron Scheduler: Cleared all scheduled events (including re-enabled deep check)');
    }

    /**
     * Schedule a non-blocking restart of the scheduler
     */
    private function schedule_scheduler_restart() {
        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event')) {
            if (!wp_next_scheduled('hic_scheduler_restart')) {
                wp_schedule_single_event(time() + 1, 'hic_scheduler_restart');
            }
        }
    }

    /**
     * Determine if all core scheduler events are scheduled
     */
    private function are_scheduler_events_scheduled() {
        $continuous_next = \FpHic\Helpers\hic_safe_wp_next_scheduled('hic_continuous_poll_event');
        $deep_next = \FpHic\Helpers\hic_safe_wp_next_scheduled('hic_deep_check_event');
        $cleanup_next = \FpHic\Helpers\hic_safe_wp_next_scheduled('hic_cleanup_event');
        $booking_cleanup_next = \FpHic\Helpers\hic_safe_wp_next_scheduled('hic_booking_events_cleanup');

        return ($continuous_next !== false && $deep_next !== false && $cleanup_next !== false && $booking_cleanup_next !== false);
    }

    /**
     * Set a lock to prevent repeated scheduler restarts
     */
    private function set_scheduler_restart_lock($timestamp) {
        if (function_exists('set_transient')) {
            set_transient(self::SCHEDULER_RESTART_LOCK_KEY, $timestamp, self::SCHEDULER_RESTART_LOCK_TTL);
        }

        if (function_exists('update_option')) {
            update_option(self::SCHEDULER_RESTART_LOCK_KEY, $timestamp, false);
            \FpHic\Helpers\hic_clear_option_cache(self::SCHEDULER_RESTART_LOCK_KEY);
        }
    }

    /**
     * Retrieve the current restart lock age in seconds
     */
    private function get_scheduler_restart_lock_age() {
        $lock_timestamp = 0;

        if (function_exists('get_transient')) {
            $transient_value = get_transient(self::SCHEDULER_RESTART_LOCK_KEY);
            if ($transient_value !== false) {
                $lock_timestamp = (int) $transient_value;
            }
        }

        if ($lock_timestamp <= 0 && function_exists('get_option')) {
            $option_value = (int) get_option(self::SCHEDULER_RESTART_LOCK_KEY, 0);
            if ($option_value > 0) {
                $lock_timestamp = $option_value;
            }
        }

        if ($lock_timestamp > 0) {
            $age = time() - $lock_timestamp;

            if ($age < self::SCHEDULER_RESTART_LOCK_TTL) {
                return $age;
            }

            $this->clear_scheduler_restart_lock();
        }

        return null;
    }

    /**
     * Clear the scheduler restart lock
     */
    private function clear_scheduler_restart_lock() {
        if (function_exists('delete_transient')) {
            delete_transient(self::SCHEDULER_RESTART_LOCK_KEY);
        }

        if (function_exists('delete_option')) {
            delete_option(self::SCHEDULER_RESTART_LOCK_KEY);
            \FpHic\Helpers\hic_clear_option_cache(self::SCHEDULER_RESTART_LOCK_KEY);
        }
    }
    
    /**
     * Check if WP-Cron is working
     */
    public function is_wp_cron_working() {
        // Check if WP-Cron is disabled
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            hic_log('WP-Cron is disabled via DISABLE_WP_CRON constant');
            return false;
        }
        
        // Check if events are scheduled (including re-enabled deep check)
        $continuous_next = \FpHic\Helpers\hic_safe_wp_next_scheduled('hic_continuous_poll_event');
        $deep_next = \FpHic\Helpers\hic_safe_wp_next_scheduled('hic_deep_check_event');
        $cleanup_next = \FpHic\Helpers\hic_safe_wp_next_scheduled('hic_cleanup_event');
        $booking_cleanup_next = \FpHic\Helpers\hic_safe_wp_next_scheduled('hic_booking_events_cleanup');

        $is_working = ($continuous_next !== false && $deep_next !== false && $cleanup_next !== false && $booking_cleanup_next !== false);

        if (!$is_working) {
            $debug_info = sprintf(
                'WP-Cron events check: continuous=%s, deep=%s, cleanup=%s, booking_cleanup=%s',
                $continuous_next ? wp_date('Y-m-d H:i:s', $continuous_next) : 'NOT_SCHEDULED',
                $deep_next ? wp_date('Y-m-d H:i:s', $deep_next) : 'NOT_SCHEDULED',
                $cleanup_next ? wp_date('Y-m-d H:i:s', $cleanup_next) : 'NOT_SCHEDULED',
                $booking_cleanup_next ? wp_date('Y-m-d H:i:s', $booking_cleanup_next) : 'NOT_SCHEDULED'
            );
            hic_log('WP-Cron not working: ' . $debug_info);
        }

        return $is_working;
    }
    
    /**
     * Log current scheduler status for debugging
     */
    private function log_scheduler_status() {
        $continuous_next = \FpHic\Helpers\hic_safe_wp_next_scheduled('hic_continuous_poll_event');
        $cleanup_next = \FpHic\Helpers\hic_safe_wp_next_scheduled('hic_cleanup_event');
        $booking_cleanup_next = \FpHic\Helpers\hic_safe_wp_next_scheduled('hic_booking_events_cleanup');

        // Check polling conditions
        $should_poll = $this->should_poll();
        $reliable_polling = \FpHic\Helpers\hic_reliable_polling_enabled();
        $connection_type = \FpHic\Helpers\hic_get_connection_type();
        $api_url = \FpHic\Helpers\hic_get_api_url();
        $has_auth = \FpHic\Helpers\hic_has_basic_auth_credentials();

        $status_msg = sprintf(
            'WP-Cron Status: Continuous next=%s, Cleanup next=%s, Booking cleanup next=%s, WP-Cron disabled=%s, Should poll=%s (reliable=%s, type=%s, url=%s, auth=%s) - Deep check ACTIVE (30-minute interval)',
            $continuous_next ? wp_date('Y-m-d H:i:s', $continuous_next) : 'NOT_SCHEDULED',
            $cleanup_next ? wp_date('Y-m-d H:i:s', $cleanup_next) : 'NOT_SCHEDULED',
            $booking_cleanup_next ? wp_date('Y-m-d H:i:s', $booking_cleanup_next) : 'NOT_SCHEDULED',
            (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? 'YES' : 'NO',
            $should_poll ? 'YES' : 'NO',
            $reliable_polling ? 'YES' : 'NO',
            $connection_type ?: 'NONE',
            $api_url ? 'SET' : 'MISSING',
            $has_auth ? 'YES' : 'NO'
        );

        $default_interval = (defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60) * 5;
        $log_interval = $default_interval;

        if (function_exists('apply_filters')) {
            $log_interval = apply_filters('hic_scheduler_status_log_interval', $default_interval);
        }

        $log_interval = max(0, (int) $log_interval);
        $current_time = time();
        $last_log_time = (int) get_option('hic_last_scheduler_status_log_time', 0);
        $last_status_message = (string) get_option('hic_last_scheduler_status_message', '');

        $should_log_status = false;

        if ($status_msg !== $last_status_message) {
            $should_log_status = true;
        } elseif (0 === $log_interval) {
            $should_log_status = true;
        } elseif (($current_time - $last_log_time) >= $log_interval) {
            $should_log_status = true;
        }

        if ($should_log_status) {
            hic_log($status_msg);
            update_option('hic_last_scheduler_status_log_time', $current_time, false);
            \FpHic\Helpers\hic_clear_option_cache('hic_last_scheduler_status_log_time');
            update_option('hic_last_scheduler_status_message', $status_msg, false);
            \FpHic\Helpers\hic_clear_option_cache('hic_last_scheduler_status_message');
        }
    }
    
    /**
     * Watchdog to detect and recover from polling failures
     */
    public function run_watchdog_check() {
        $current_time = time();
        $last_continuous = get_option('hic_last_continuous_poll', 0);
        $last_deep = get_option('hic_last_deep_check', 0);
        $last_successful = get_option('hic_last_successful_poll', 0);
        $success_lag = $current_time - $last_successful;
        $seven_days = (defined('DAY_IN_SECONDS') ? DAY_IN_SECONDS : 86400) * 7;

        // If we've never had a successful poll or it's older than 7 days, force a deep check
        if ($last_successful <= 0 || $success_lag > $seven_days) {
            hic_log('Watchdog: Last successful poll older than 7 days or missing - forcing timestamp recovery');
            if (function_exists('\\FpHic\\hic_api_poll_bookings_deep_check')) {
                \FpHic\hic_api_poll_bookings_deep_check();
            }
        }

        hic_log("Watchdog: Running check - continuous lag: " . ($current_time - $last_continuous) . "s (with 30-minute deep check)");
        
        // Check for continuous polling lag (should run every 30 seconds)
        $continuous_lag = $current_time - $last_continuous;
        if ($continuous_lag > HIC_WATCHDOG_THRESHOLD) {
            hic_log("Watchdog: Continuous polling lag detected ({$continuous_lag}s), attempting recovery");
            $this->recover_from_failure('continuous');
        }
        
        // Check for completely stuck polling - no successful polls for 1+ hours
        if ($last_successful > 0 && $success_lag > 3600) { // 1 hour without success
            $cooldown_default = (defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60) * 10;
            $cooldown = (int) apply_filters('hic_timestamp_recovery_cooldown', $cooldown_default);
            $last_timestamp_recovery = (int) get_option('hic_last_timestamp_recovery', 0);
            $time_since_last_recovery = $current_time - $last_timestamp_recovery;

            if ($last_timestamp_recovery <= 0 || $time_since_last_recovery >= $cooldown) {
                hic_log("Watchdog: No successful polling for {$success_lag}s - likely timestamp error, triggering timestamp recovery");
                update_option('hic_last_timestamp_recovery', $current_time, false);
                \FpHic\Helpers\hic_clear_option_cache('hic_last_timestamp_recovery');
                $this->recover_from_failure('timestamp_error');
            } else {
                hic_log("Watchdog: Timestamp recovery skipped due to cooldown ({$time_since_last_recovery}s since last attempt)");
            }
        }
        
        // Check if WP-Cron events are properly scheduled
        if (!$this->is_wp_cron_working()) {
            hic_log("Watchdog: WP-Cron events not properly scheduled, attempting recovery");
            $this->recover_from_failure('scheduling');
        }
        
        // Additional check: if polling should be active but no events are scheduled
        if ($this->should_poll()) {
            $continuous_next = \FpHic\Helpers\hic_safe_wp_next_scheduled('hic_continuous_poll_event');
            
            if (!$continuous_next) {
                hic_log("Watchdog: Polling should be active but continuous event not scheduled, forcing restart");
                $this->recover_from_failure('scheduling');
            }
        }
    }
    
    /**
     * Recover from various types of polling failures
     */
    private function recover_from_failure($failure_type) {
        hic_log("Recovery: Attempting recovery for failure type: $failure_type");
        
        switch ($failure_type) {
            case 'continuous':
                // Force reschedule continuous polling
                \FpHic\Helpers\hic_safe_wp_clear_scheduled_hook('hic_continuous_poll_event');
                \FpHic\Helpers\hic_safe_wp_schedule_event(time(), 'hic_every_thirty_seconds', 'hic_continuous_poll_event');
                // Trigger immediate execution
                $this->execute_continuous_polling();
                break;
                
            case 'deep':
                // Reschedule deep check and execute immediately
                \FpHic\Helpers\hic_safe_wp_clear_scheduled_hook('hic_deep_check_event');
                \FpHic\Helpers\hic_safe_wp_schedule_event(time(), 'hic_every_thirty_minutes', 'hic_deep_check_event');
                $this->execute_deep_check();
                break;
                
            case 'scheduling':
                $lock_age = $this->get_scheduler_restart_lock_age();

                if (null !== $lock_age) {
                    hic_log('Recovery: Scheduler restart skipped due to active lock (' . $lock_age . 's old)');
                    break;
                }

                $this->set_scheduler_restart_lock(time());

                // Full scheduler restart
                $this->clear_all_scheduled_events();
                $this->schedule_scheduler_restart();
                break;
                
            case 'timestamp_error':
                // Handle stuck polling due to timestamp errors
                hic_log("Recovery: Resetting all timestamps due to timestamp errors");
                $current_time = time();
                $safe_timestamp = $current_time - (3 * DAY_IN_SECONDS); // Reset to 3 days ago
                $recent_timestamp = $current_time - HIC_WATCHDOG_THRESHOLD; // 5 minutes ago for polling timestamps
                
                // Validate timestamps before using them (if hic_validate_api_timestamp is available)
                if (function_exists('\\FpHic\\hic_validate_api_timestamp')) {
                    $safe_timestamp = \FpHic\hic_validate_api_timestamp($safe_timestamp, 'Recovery data timestamp reset');
                    $recent_timestamp = \FpHic\hic_validate_api_timestamp($recent_timestamp, 'Recovery polling timestamp reset');
                }
                
                update_option('hic_last_updates_since', $safe_timestamp, false);
                \FpHic\Helpers\hic_clear_option_cache('hic_last_updates_since');
                update_option('hic_last_update_check', $safe_timestamp, false);
                \FpHic\Helpers\hic_clear_option_cache('hic_last_update_check');
                update_option('hic_last_continuous_check', $safe_timestamp, false);
                \FpHic\Helpers\hic_clear_option_cache('hic_last_continuous_check');
                update_option('hic_last_continuous_poll', $recent_timestamp, false);
                \FpHic\Helpers\hic_clear_option_cache('hic_last_continuous_poll');
                // Deep check timestamps reset
                delete_option('hic_last_deep_check');
                \FpHic\Helpers\hic_clear_option_cache('hic_last_deep_check');
                
                // Also restart the scheduler to ensure clean state
                $this->clear_all_scheduled_events();
                $this->schedule_scheduler_restart();

                hic_log("Recovery: All timestamps reset - data timestamps to " . wp_date('Y-m-d H:i:s', $safe_timestamp) . ", polling timestamps to " . wp_date('Y-m-d H:i:s', $recent_timestamp) . ", scheduler restart scheduled with 30-minute deep check");
                break;
        }
        
        hic_log("Recovery: Completed recovery attempt for $failure_type");
    }
    
    /**
     * Public method to trigger timestamp recovery
     * Can be called from diagnostics interface or manual intervention
     */
    public function trigger_timestamp_recovery() {
        hic_log("Manual timestamp recovery triggered");
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
            $settings['interval'] = HIC_CONTINUOUS_POLLING_INTERVAL; // Run every 30 seconds for watchdog
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
            'deep_check_disabled' => false,
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
        if (!isset($_GET['page']) || strpos( sanitize_text_field( wp_unslash( $_GET['page'] ) ), 'hic' ) === false) {
            return;
        }
        
        // Run a lightweight watchdog check
        if ($this->should_poll()) {
            $current_time = time();
            $last_continuous = get_option('hic_last_continuous_poll', 0);
            
            // If polling hasn't run in more than 5 minutes, trigger recovery
            if ($current_time - $last_continuous > HIC_WATCHDOG_THRESHOLD) {
                hic_log("Admin Watchdog: Detected polling failure during admin page load (lag: " . ($current_time - $last_continuous) . "s), triggering recovery");
                $this->run_watchdog_check();
            }
            
            // Also check if events are scheduled at all
            $continuous_next = \FpHic\Helpers\hic_safe_wp_next_scheduled('hic_continuous_poll_event');
            if (!$continuous_next) {
                hic_log("Admin Watchdog: No continuous polling event scheduled, restarting scheduler");
                $this->ensure_scheduler_is_active();
            }
        }
    }
    
    /**
     * Fallback polling check - triggers on every page load as last resort
     * Enhanced to be more proactive in restarting dormant schedulers
     */
    public function fallback_polling_check() {
        // Only run as fallback if polling should be active
        if (!$this->should_poll()) {
            return;
        }
        
        $current_time = time();
        $last_continuous = get_option('hic_last_continuous_poll', 0);
        $polling_lag = $current_time - $last_continuous;
        
        // Get context about the current web request
        $context = $this->get_current_request_context();
        
        // ENHANCED: Be more aggressive about restarting dormant schedulers
        // If polling hasn't run in over 1 hour (3600 seconds), it's likely dormant regardless of WP-Cron status
        $dormancy_threshold = 3600; // 1 hour - indicates system has been dormant
        $critical_threshold = HIC_DEEP_CHECK_INTERVAL; // 30 minutes - WP-Cron definitely not working
        
        $should_restart = false;
        $restart_reason = '';
        
        if ($polling_lag > $dormancy_threshold) {
            $should_restart = true;
            $restart_reason = "Polling dormant for " . round($polling_lag / 60, 1) . " minutes - reactivated by {$context['type']} traffic";
        } elseif (!$this->is_wp_cron_working() && $polling_lag > $critical_threshold) {
            $should_restart = true;
            $restart_reason = "WP-Cron not working and polling delayed for " . round($polling_lag / 60, 1) . " minutes - triggered by {$context['type']} traffic";
        } elseif ($polling_lag > $critical_threshold) {
            // Even if WP-Cron appears to be working, if polling is severely delayed, restart it
            $should_restart = true;
            $restart_reason = "Polling severely delayed for " . round($polling_lag / 60, 1) . " minutes despite WP-Cron appearing active - triggered by {$context['type']} traffic";
        }
        
        if ($should_restart) {
            hic_log("Fallback: $restart_reason - attempting scheduler restart via web traffic from {$context['path']}");
            
            // Use a transient to prevent multiple simultaneous executions
            $fallback_lock = get_transient('hic_fallback_polling_lock');
            if (!$fallback_lock) {
                set_transient('hic_fallback_polling_lock', $current_time, 120); // 2-minute lock
                
                // First try to restart the scheduler
                $this->ensure_scheduler_is_active();
                
                // Then schedule immediate fallback polling
                wp_schedule_single_event(time() + 5, 'hic_fallback_poll_event');
                add_action('hic_fallback_poll_event', array($this, 'execute_fallback_polling'));
                
                hic_log("Fallback: Restarted scheduler and scheduled immediate fallback polling via {$context['type']} traffic");
                
                // Update recovery statistics
                $this->update_web_traffic_recovery_stats($context, $polling_lag);
            } else {
                hic_log("Fallback: Recovery already in progress (lock active) for {$context['type']} traffic");
            }
        } else {
            // Log non-recovery traffic for monitoring
            hic_log("Fallback: Monitoring {$context['type']} traffic to {$context['path']}, polling lag: " . round($polling_lag / 60, 1) . " minutes - system healthy");
        }
    }
    
    /**
     * Proactive scheduler check - runs on all page loads (frontend and backend)
     * More lightweight than fallback check but ensures scheduler stays active
     */
    public function proactive_scheduler_check() {
        // Only run if polling should be active
        if (!$this->should_poll()) {
            return;
        }
        
        // Use a transient to limit how often this check runs (every 5 minutes max)
        $last_proactive_check = get_transient('hic_last_proactive_check');
        if ($last_proactive_check) {
            return; // Don't run too frequently
        }
        
        $current_time = time();
        $last_continuous = get_option('hic_last_continuous_poll', 0);
        $polling_lag = $current_time - $last_continuous;
        
        // Enhanced logging for web traffic monitoring
        $context = $this->get_current_request_context();
        hic_log("Proactive Check: Web traffic detected - {$context['type']} request to {$context['path']}, polling lag: " . round($polling_lag / 60, 1) . " minutes");
        
        // Set the transient to prevent too frequent checks
        set_transient('hic_last_proactive_check', $current_time, 300); // 5 minutes
        
        // Track web traffic polling statistics
        $this->update_web_traffic_stats($context, $polling_lag);
        
        // If polling hasn't run in 30 minutes, proactively restart scheduler
        if ($polling_lag > 1800) { // 30 minutes
            hic_log("Proactive: Polling inactive for " . round($polling_lag / 60, 1) . " minutes via {$context['type']} traffic - restarting scheduler");
            $this->ensure_scheduler_is_active();
        }
        // If events aren't scheduled at all, restart
        else if (!$this->is_wp_cron_working()) {
            hic_log("Proactive: WP-Cron events not properly scheduled via {$context['type']} traffic - restarting scheduler");
            $this->ensure_scheduler_is_active();
        } else {
            hic_log("Proactive: Polling system healthy via {$context['type']} traffic - no action needed");
        }
    }
    
    /**
     * Shutdown polling check - very lightweight check on page end
     */
    public function shutdown_polling_check() {
        // Only run if polling should be active
        if (!$this->should_poll()) {
            return;
        }
        
        // Very quick check - just log if polling seems to be failing
        $current_time = time();
        $last_continuous = get_option('hic_last_continuous_poll', 0);
        
        if ($current_time - $last_continuous > 1800) { // 30 minutes lag
            $context = $this->get_current_request_context();
            hic_log("Shutdown Check: Severe polling lag detected during {$context['type']} request ({$current_time} - {$last_continuous} = " . ($current_time - $last_continuous) . "s)");
        }
    }
    
    /**
     * Get context information about the current web request
     * Helps identify whether polling is being triggered by frontend or backend traffic
     */
    private function get_current_request_context() {
        $context = array(
            'type' => 'unknown',
            'path' => isset($_SERVER['REQUEST_URI']) ? sanitize_text_field($_SERVER['REQUEST_URI']) : 'unknown',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 100) : 'unknown',
            'is_admin' => is_admin(),
            'is_ajax' => defined('DOING_AJAX') && DOING_AJAX,
            'is_cron' => defined('DOING_CRON') && DOING_CRON,
            'timestamp' => time()
        );
        
        // Determine request type
        if ($context['is_cron']) {
            $context['type'] = 'wp-cron';
        } elseif ($context['is_ajax']) {
            $context['type'] = 'ajax';
        } elseif ($context['is_admin']) {
            $context['type'] = 'admin';
        } elseif (wp_doing_ajax()) {
            $context['type'] = 'ajax';
        } elseif (is_admin()) {
            $context['type'] = 'admin';
        } else {
            $context['type'] = 'frontend';
        }
        
        return $context;
    }
    
    /**
     * Update web traffic monitoring statistics
     */
    private function update_web_traffic_stats($context, $polling_lag) {
        $stats = get_option('hic_web_traffic_stats', array());
        
        // Initialize stats if empty
        if (empty($stats)) {
            $stats = array(
                'total_checks' => 0,
                'frontend_checks' => 0,
                'admin_checks' => 0,
                'ajax_checks' => 0,
                'last_frontend_check' => 0,
                'last_admin_check' => 0,
                'average_polling_lag' => 0,
                'max_polling_lag' => 0,
                'recoveries_triggered' => 0
            );
        }
        
        // Update counters
        $stats['total_checks']++;
        $stats[$context['type'] . '_checks'] = ($stats[$context['type'] . '_checks'] ?? 0) + 1;
        $stats['last_' . $context['type'] . '_check'] = $context['timestamp'];
        
        // Update lag statistics
        $stats['average_polling_lag'] = (($stats['average_polling_lag'] * ($stats['total_checks'] - 1)) + $polling_lag) / $stats['total_checks'];
        $stats['max_polling_lag'] = max($stats['max_polling_lag'], $polling_lag);
        
        update_option('hic_web_traffic_stats', $stats, false);
    }
    
    /**
     * Update web traffic recovery statistics
     */
    private function update_web_traffic_recovery_stats($context, $polling_lag) {
        $stats = get_option('hic_web_traffic_stats', array());
        $stats['recoveries_triggered'] = ($stats['recoveries_triggered'] ?? 0) + 1;
        $stats['last_recovery_via'] = $context['type'];
        $stats['last_recovery_lag'] = $polling_lag;
        $stats['last_recovery_time'] = $context['timestamp'];
        
        update_option('hic_web_traffic_stats', $stats, false);
        
        hic_log("Web Traffic Recovery: Recovery #{$stats['recoveries_triggered']} triggered via {$context['type']} traffic with {$polling_lag}s lag");
    }
    
    /**
     * Execute fallback polling when WP-Cron completely fails
     */
    public function execute_fallback_polling() {
        hic_log("Fallback: Executing fallback polling due to WP-Cron failure");
        
        try {
            // Try to restart the scheduler first
            $this->ensure_scheduler_is_active();
            
            // Execute continuous polling
            $this->execute_continuous_polling();
            
            // Also run deep check if it's been a while - RE-ENABLED for safety
            $current_time = time();
            $last_deep = get_option('hic_last_deep_check', 0);
            if ($current_time - $last_deep > 1800) { // 30 minutes
                $this->execute_deep_check();
            }
            
            hic_log("Fallback: Fallback polling completed successfully");
        } catch (Exception $e) {
            hic_log("Fallback: Error during fallback polling: " . $e->getMessage());
        }
    }
    
    /**
     * Execute continuous polling (every 30 seconds)
     * Checks for recent reservations and manual bookings
     */
    public function execute_continuous_polling() {
        hic_log("Scheduler: Executing continuous polling (30-second interval)");
        $result = false;
        try {
            if (function_exists('\FpHic\hic_api_poll_bookings_continuous')) {
                $result = \FpHic\hic_api_poll_bookings_continuous();
            } elseif (function_exists('\FpHic\hic_api_poll_bookings')) {
                // Fallback to existing function if new one doesn't exist yet
                $result = \FpHic\hic_api_poll_bookings();
            } else {
                $result = null;
            }
            if (is_wp_error($result)) {
                $error_message = $result->get_error_message();
                $error_code    = $result->get_error_code();
                $error_data    = $result->get_error_data($error_code);

                if (null === $error_data) {
                    $error_data = $result->get_error_data();
                }

                $error_details = '';

                if (!empty($error_data)) {
                    if (is_array($error_data)) {
                        $normalized_details = array();

                        foreach ($error_data as $detail) {
                            if (is_scalar($detail)) {
                                $normalized_details[] = (string) $detail;
                            } elseif (is_array($detail) || is_object($detail)) {
                                $normalized_details[] = function_exists('wp_json_encode') ? wp_json_encode($detail) : json_encode($detail);
                            }
                        }

                        if (!empty($normalized_details)) {
                            $error_details = implode('; ', $normalized_details);
                        }
                    } elseif (is_scalar($error_data)) {
                        $error_details = (string) $error_data;
                    } else {
                        $error_details = function_exists('wp_json_encode') ? wp_json_encode($error_data) : json_encode($error_data);
                    }
                }

                if ($error_details !== '' && false === strpos($error_message, $error_details)) {
                    $error_message .= ' | Details: ' . $error_details;
                }

                hic_log('Continuous polling error: ' . $error_message, HIC_LOG_LEVEL_ERROR);
                $this->increment_failure_counter('hic_continuous_poll_failures');
            }
        } catch (\Throwable $e) {
            hic_log('Continuous polling error: ' . $e->getMessage(), HIC_LOG_LEVEL_ERROR);
            $this->increment_failure_counter('hic_continuous_poll_failures');
        } finally {
            update_option('hic_last_continuous_poll', time(), false);
            \FpHic\Helpers\hic_clear_option_cache('hic_last_continuous_poll');
        }
        return $result;
    }
    
    /**
     * Execute deep check (every 30 minutes) - RE-ENABLED for safety
     * Focuses on checking the last 5 bookings to ensure none are missed
     * Runs in addition to continuous polling for comprehensive coverage
     */
    public function execute_deep_check() {
        hic_log("Scheduler: Executing deep check (30-minute interval, focusing on last 5 bookings)");
        
        $result = null;
        $success = false;
        try {
            if (function_exists('\\FpHic\\hic_api_poll_bookings_deep_check')) {
                $result = \FpHic\hic_api_poll_bookings_deep_check();
            } else {
                // Fallback implementation - focus on last 5 bookings
                $result = $this->fallback_deep_check_last_bookings();
            }
            // Only mark success if the check ran and produced a valid result
            $success = ($result !== null && $result !== false && !is_wp_error($result));
        } catch (\Throwable $e) {
            hic_log('Deep check error: ' . $e->getMessage(), HIC_LOG_LEVEL_ERROR);
            $this->increment_failure_counter('hic_deep_check_failures');
        } finally {
            if ($success) {
                update_option('hic_last_deep_check', time(), false);
                \FpHic\Helpers\hic_clear_option_cache('hic_last_deep_check');
            }
        }
        
        return $result;
    }
    
    /**
     * Optimized deep check focusing on last 5 bookings
     * More efficient than full lookback approach
     */
    private function fallback_deep_check_last_bookings() {
        $start_time = microtime(true);
        $prop_id = \FpHic\Helpers\hic_get_property_id();
        if (empty($prop_id)) {
            hic_log("Deep check (last 5): No property ID configured");
            return array('new' => 0, 'skipped' => 0, 'errors' => 1);
        }

        hic_log("Deep check (last 5): Checking latest 5 bookings for any missed items");

        $total_new = 0;
        $total_skipped = 0;
        $total_errors = 0;

        // Use the diagnostics function to get latest 5 bookings
        if (function_exists('hic_get_latest_bookings')) {
            // Check both processed and unprocessed bookings
            $reservations = hic_get_latest_bookings(5, false);
            if (!is_wp_error($reservations) && is_array($reservations)) {
                $count = count($reservations);
                hic_log("Deep check (last 5): Found $count latest bookings");

                if ($count > 0) {
                    $process_result = hic_process_reservations_batch($reservations);
                    $total_new += $process_result['new'];
                    $total_skipped += $process_result['skipped'];
                    $total_errors += $process_result['errors'];

                    hic_log("Deep check (last 5): Processed batch - New: {$process_result['new']}, Skipped: {$process_result['skipped']}, Errors: {$process_result['errors']}");
                } else {
                    hic_log("Deep check (last 5): No recent bookings found");
                }
            } else {
                $error_message = is_wp_error($reservations) ? $reservations->get_error_message() : 'Unknown error';
                hic_log("Deep check (last 5): Error fetching latest bookings: $error_message", HIC_LOG_LEVEL_ERROR);
                $total_errors++;
            }
        } else {
            hic_log("Deep check (last 5): hic_get_latest_bookings function not available, falling back to date range check", HIC_LOG_LEVEL_WARNING);
            
            // Fallback to last 3 days if function not available
            $current_time = time();
            $from_date = wp_date('Y-m-d', $current_time - (3 * DAY_IN_SECONDS));
            $to_date = wp_date('Y-m-d', $current_time);
            
            if (function_exists('\\FpHic\\hic_fetch_reservations_raw')) {
                $reservations = \FpHic\hic_fetch_reservations_raw($prop_id, 'checkin', $from_date, $to_date, 5);
                if (!is_wp_error($reservations) && is_array($reservations)) {
                    $count = count($reservations);
                    hic_log("Deep check (last 5): Found $count reservations in last 3 days");

                    $process_result = hic_process_reservations_batch($reservations);
                    $total_new += $process_result['new'];
                    $total_skipped += $process_result['skipped'];
                    $total_errors += $process_result['errors'];

                    hic_log("Deep check (last 5): Processed fallback batch - New: {$process_result['new']}, Skipped: {$process_result['skipped']}, Errors: {$process_result['errors']}");
                } else {
                    $error_message = is_wp_error($reservations) ? $reservations->get_error_message() : 'Unknown error';
                    hic_log("Deep check (last 5): Error in fallback fetch: $error_message", HIC_LOG_LEVEL_ERROR);
                    $total_errors++;
                }
            } else {
                hic_log("Deep check (last 5): No reservation fetching functions available", HIC_LOG_LEVEL_ERROR);
                $total_errors++;
            }
        }

        $execution_time = round((microtime(true) - $start_time) * 1000, 2);
        $current_time = time();

        if ($total_errors === 0) {
            update_option('hic_last_deep_check_count', $total_new, false);
            \FpHic\Helpers\hic_clear_option_cache('hic_last_deep_check_count');
            update_option('hic_last_deep_check_duration', $execution_time, false);
            \FpHic\Helpers\hic_clear_option_cache('hic_last_deep_check_duration');
            update_option('hic_last_successful_deep_check', $current_time, false);
            \FpHic\Helpers\hic_clear_option_cache('hic_last_successful_deep_check');
        }

        hic_log("Deep check (last 5): Completed in {$execution_time}ms - New: $total_new, Skipped: $total_skipped, Errors: $total_errors");

        return array(
            'new' => $total_new,
            'skipped' => $total_skipped,
            'errors' => $total_errors,
        );
    }
    /**
     * Check if polling should be active based on configuration
     */
    private function should_poll() {
        if (!\FpHic\Helpers\hic_reliable_polling_enabled()) {
            hic_log('Reliable polling disabled');
            return false;
        }

        if (\FpHic\Helpers\hic_get_connection_type() !== 'api') {
            hic_log('Connection type is not API');
            return false;
        }

        if (!\FpHic\Helpers\hic_get_api_url()) {
            hic_log('API URL not set');
            return false;
        }

        if (!\FpHic\Helpers\hic_has_basic_auth_credentials()) {
            hic_log('Missing basic auth credentials');
            return false;
        }

        return true;
    }
    
    /**
     * Get polling interval in seconds (simplified - always 30 seconds for continuous)
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
            hic_log("Heartbeat Scheduler Watchdog: Polling lag detected - {$lag}s since last poll (threshold: " . HIC_WATCHDOG_THRESHOLD . "s)");
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
            $error = 'Polling conditions not met. See logs for details.';
            hic_log('Manual polling failed: ' . $error);
            return array('success' => false, 'message' => $error);
        }
        
        try {
            // Execute continuous polling
            $result = $this->execute_continuous_polling();

            if (is_wp_error($result) || $result !== true) {
                $error_message = is_wp_error($result) ? $result->get_error_message() : 'Unexpected polling result';
                hic_log('Manual polling failed: ' . $error_message);
                return array('success' => false, 'message' => $error_message);
            }

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
            $error = 'Polling conditions not met. See logs for details.';
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
            $result = $this->execute_continuous_polling();

            if (is_wp_error($result) || $result !== true) {
                $error_message = is_wp_error($result) ? $result->get_error_message() : 'Unexpected polling result';
                hic_log('Force manual polling failed: ' . $error_message);
                return array(
                    'success' => false,
                    'message' => $error_message,
                    'lock_cleared' => $lock_cleared
                );
            }

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
        hic_log($event, HIC_LOG_LEVEL_INFO, is_array($data) ? $data : array());
    }
    
    /**
     * Get detailed diagnostics including polling conditions
     */
    public function get_detailed_diagnostics() {
        $base_stats = $this->get_stats();
        
        // Add detailed condition checks
        $diagnostics = array_merge($base_stats, array(
            'conditions' => array(
                'reliable_polling_enabled' => \FpHic\Helpers\hic_reliable_polling_enabled(),
                'connection_type_api' => \FpHic\Helpers\hic_get_connection_type() === 'api',
                'api_url_configured' => !empty(\FpHic\Helpers\hic_get_api_url()),
                'has_credentials' => \FpHic\Helpers\hic_has_basic_auth_credentials(),
                'basic_auth_complete' => \FpHic\Helpers\hic_has_basic_auth_credentials(),
                'credentials_type' => \FpHic\Helpers\hic_has_basic_auth_credentials() ? 'basic_auth' : 'none'
            ),
            'configuration' => array(
                'connection_type' => \FpHic\Helpers\hic_get_connection_type(),
                'api_url' => !empty(\FpHic\Helpers\hic_get_api_url()) ? 'configured' : 'missing',
                'property_id' => !empty(\FpHic\Helpers\hic_get_property_id()) ? 'configured' : 'missing',
                'api_email' => !empty(\FpHic\Helpers\hic_get_api_email()) ? 'configured' : 'missing',
                'api_password' => !empty(\FpHic\Helpers\hic_get_api_password()) ? 'configured' : 'missing'
            ),
            'lock_status' => array(
                'active' => get_transient('hic_reliable_polling_lock') ? true : false,
                'timestamp' => get_transient('hic_reliable_polling_lock') ?: null
            )
        ));
        
        return $diagnostics;
    }
    
    /**
     * Get web traffic monitoring statistics
     */
    public function get_web_traffic_stats() {
        $stats = get_option('hic_web_traffic_stats', array());
        
        // Provide defaults if stats don't exist
        $default_stats = array(
            'total_checks' => 0,
            'frontend_checks' => 0,
            'admin_checks' => 0,
            'ajax_checks' => 0,
            'last_frontend_check' => 0,
            'last_admin_check' => 0,
            'average_polling_lag' => 0,
            'max_polling_lag' => 0,
            'recoveries_triggered' => 0,
            'last_recovery_via' => 'none',
            'last_recovery_lag' => 0,
            'last_recovery_time' => 0
        );
        
        $stats = array_merge($default_stats, $stats);
        
        // Add formatted versions for display
        $stats['last_frontend_check_formatted'] = $stats['last_frontend_check'] > 0 ? 
            wp_date('Y-m-d H:i:s', $stats['last_frontend_check']) : 'Never';
        $stats['last_admin_check_formatted'] = $stats['last_admin_check'] > 0 ? 
            wp_date('Y-m-d H:i:s', $stats['last_admin_check']) : 'Never';
        $stats['last_recovery_time_formatted'] = $stats['last_recovery_time'] > 0 ? 
            wp_date('Y-m-d H:i:s', $stats['last_recovery_time']) : 'Never';
        $stats['average_polling_lag_formatted'] = round($stats['average_polling_lag'] / 60, 1) . ' minutes';
        $stats['max_polling_lag_formatted'] = round($stats['max_polling_lag'] / 60, 1) . ' minutes';
        
        return $stats;
    }
    
    /**
     * Reset web traffic monitoring statistics
     */
    public function reset_web_traffic_stats() {
        delete_option('hic_web_traffic_stats');
        hic_log("Web Traffic Stats: Statistics reset");
        return true;
    }
    
    /**
     * Get polling statistics for diagnostics
     */
    public function get_stats() {
        $last_continuous = get_option('hic_last_continuous_poll', 0);
        $last_general = get_option('hic_last_api_poll', 0);
        
        // Check scheduler type - WP-Cron only now
        $is_wp_cron_working = $this->is_wp_cron_working();
        $scheduler_type = $is_wp_cron_working ? 'WP-Cron' : 'Non attivo';
        
        // Get detailed polling conditions
        $should_poll = $this->should_poll();
        $reliable_polling = \FpHic\Helpers\hic_reliable_polling_enabled();
        $connection_type = \FpHic\Helpers\hic_get_connection_type();
        $api_url = \FpHic\Helpers\hic_get_api_url();
        $has_auth = \FpHic\Helpers\hic_has_basic_auth_credentials();
        
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
            'deep_check_disabled' => false,
            'lag_seconds' => $last_general > 0 ? time() - $last_general : 0,
            'continuous_lag' => $last_continuous > 0 ? time() - $last_continuous : 0,
            'polling_active' => $should_poll,
            'polling_interval' => HIC_CONTINUOUS_POLLING_INTERVAL
        );

        // Add WP-Cron specific info (always show for debugging)
        $stats['next_continuous_scheduled'] = \FpHic\Helpers\hic_safe_wp_next_scheduled('hic_continuous_poll_event');
        $stats['deep_check_disabled'] = false;
        $stats['wp_cron_disabled'] = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

        return $stats;
    }

    /**
     * Increment a failure counter for diagnostics.
     *
     * @param string $option_name Option storing the failure count.
     */
    private function increment_failure_counter($option_name) {
        $failures = (int) get_option($option_name, 0);
        update_option($option_name, $failures + 1, false);
        \FpHic\Helpers\hic_clear_option_cache($option_name);
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
function hic_deactivate(): void {
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

// Initialize booking poller when WordPress is ready (safe hook registration)
\FpHic\Helpers\hic_safe_add_hook('action', 'init', __NAMESPACE__ . '\\hic_init_booking_poller');
