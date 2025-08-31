<?php
/**
 * Reliable Booking Poller with Custom Scheduler and Watchdog
 */

if (!defined('ABSPATH')) exit;

class HIC_Booking_Poller {
    
    const LOCK_KEY = 'hic_reliable_polling_lock';
    const LOCK_TTL = 240; // 4 minutes in seconds
    const WATCHDOG_THRESHOLD = 900; // 15 minutes in seconds
    const OVERLAP_SECONDS = 300; // 5 minutes overlap for moving window
    const BOOTSTRAP_DAYS = 7; // Initial bootstrap range in days
    
    private $log_context = array();
    
    public function __construct() {
        // Register required cron schedules
        add_filter('cron_schedules', array($this, 'add_cron_schedules'), 10);
        
        add_action('init', array($this, 'init_scheduler'));
        add_action('hic_reliable_poll_event', array($this, 'execute_poll'));
        
        // Disable old WP-Cron events when reliable polling is active
        add_action('init', array($this, 'disable_legacy_cron_events'), 20);
    }
    
    /**
     * Add required cron schedules for internal scheduler
     */
    public function add_cron_schedules($schedules) {
        if (!isset($schedules['hic_reliable_interval'])) {
            $schedules['hic_reliable_interval'] = array(
                'interval' => 300, // 5 minutes
                'display' => 'Every 5 Minutes (HIC Internal Scheduler)'
            );
        }
        
        // Keep some intervals for backward compatibility
        if (!isset($schedules['every_minute'])) {
            $schedules['every_minute'] = array(
                'interval' => 60,
                'display' => 'Every Minute'
            );
        }
        
        if (!isset($schedules['every_two_minutes'])) {
            $schedules['every_two_minutes'] = array(
                'interval' => 120,
                'display' => 'Every Two Minutes'
            );
        }
        
        return $schedules;
    }
    
    /**
     * Initialize the custom scheduler with watchdog
     */
    public function init_scheduler() {
        // Check if polling should be active
        if (!$this->should_poll()) {
            $this->clear_scheduled_events();
            return;
        }
        
        $this->ensure_scheduled_event();
        $this->run_watchdog_check();
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
     * Ensure the recurring poll event is scheduled
     */
    private function ensure_scheduled_event() {
        if (!wp_next_scheduled('hic_reliable_poll_event')) {
            // Use reliable interval as default, fallback to configured interval
            $schedules = wp_get_schedules();
            if (isset($schedules['hic_reliable_interval'])) {
                $interval = 'hic_reliable_interval';
            } else {
                $interval = hic_get_polling_interval();
            }
            
            $result = wp_schedule_event(time() + 60, $interval, 'hic_reliable_poll_event');
            
            if ($result) {
                $this->log_structured('scheduler_event_created', array(
                    'interval' => $interval,
                    'next_run' => time() + 60
                ));
            } else {
                $this->log_structured('scheduler_event_creation_failed', array(
                    'interval' => $interval,
                    'error' => 'wp_schedule_event returned false'
                ));
            }
        }
    }
    
    /**
     * Watchdog: Check if polling is lagging and create immediate event if needed
     */
    private function run_watchdog_check() {
        $last_poll = get_option('hic_last_reliable_poll', 0);
        $current_time = time();
        $lag = $current_time - $last_poll;
        
        if ($lag > self::WATCHDOG_THRESHOLD) {
            // Schedule immediate one-time event
            if (!wp_next_scheduled('hic_reliable_poll_event')) {
                wp_schedule_single_event(time() + 10, 'hic_reliable_poll_event');
                
                $this->log_structured('watchdog_triggered', array(
                    'lag_seconds' => $lag,
                    'last_poll' => $last_poll,
                    'threshold' => self::WATCHDOG_THRESHOLD,
                    'immediate_event_scheduled' => true
                ));
            } else {
                $this->log_structured('watchdog_check', array(
                    'lag_seconds' => $lag,
                    'event_already_scheduled' => true
                ));
            }
        }
    }
    
    /**
     * Clear all scheduled polling events
     */
    private function clear_scheduled_events() {
        $timestamp = wp_next_scheduled('hic_reliable_poll_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'hic_reliable_poll_event');
            $this->log_structured('scheduler_events_cleared', array(
                'reason' => 'polling conditions not met'
            ));
        }
    }
    
    /**
     * Main poll execution with comprehensive error handling and logging
     */
    public function execute_poll() {
        $start_time = microtime(true);
        $this->log_context = array(
            'poll_start' => $start_time,
            'poll_id' => uniqid('poll_')
        );
        
        // Attempt to acquire lock
        if (!$this->acquire_lock()) {
            $this->log_structured('poll_skipped_lock', array(
                'reason' => 'Another polling process is running'
            ));
            return;
        }
        
        try {
            $this->log_structured('poll_start', array());
            
            // Update last poll timestamp at the start to indicate polling is active
            update_option('hic_last_reliable_poll', time());
            
            $stats = $this->perform_polling();
            
            // Also handle updates polling if enabled
            if ($this->should_poll_updates()) {
                $updates_stats = $this->perform_updates_polling();
                $stats = array_merge($stats, $updates_stats);
            }
            
            // Handle retry notifications
            if ($this->should_retry_notifications()) {
                $retry_stats = $this->perform_retry_notifications();
                $stats = array_merge($stats, $retry_stats);
            }
            
            $execution_time = round(microtime(true) - $start_time, 2);
            
            $this->log_structured('poll_completed', array_merge($stats, array(
                'execution_time' => $execution_time
            )));
            
        } catch (Exception $e) {
            $this->log_structured('poll_error', array(
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
            // Still update timestamp even on error to show polling is running
            update_option('hic_last_reliable_poll', time());
        } finally {
            $this->release_lock();
        }
    }
    
    /**
     * Perform the actual polling with range calculation and processing
     */
    private function perform_polling() {
        $stats = array(
            'reservations_fetched' => 0,
            'reservations_new' => 0,
            'reservations_duplicate' => 0,
            'reservations_processed' => 0,
            'reservations_errors' => 0,
            'api_calls' => 0
        );
        
        // Calculate polling range
        $range = $this->calculate_polling_range();
        
        $this->log_structured('poll_range_calculated', $range);
        
        // Fetch reservations from API
        $reservations = $this->fetch_reservations($range);
        
        $stats['reservations_fetched'] = count($reservations);
        $stats['api_calls'] = 1;
        
        // Process each reservation
        foreach ($reservations as $reservation) {
            try {
                $result = $this->process_reservation($reservation);
                
                if ($result === 'new') {
                    $stats['reservations_new']++;
                    $stats['reservations_processed']++;
                } elseif ($result === 'duplicate') {
                    $stats['reservations_duplicate']++;
                } elseif ($result === 'error') {
                    $stats['reservations_errors']++;
                }
                
            } catch (Exception $e) {
                $stats['reservations_errors']++;
                $this->log_structured('reservation_processing_error', array(
                    'reservation_id' => isset($reservation['id']) ? $reservation['id'] : 'unknown',
                    'error' => $e->getMessage()
                ));
            }
        }
        
        return $stats;
    }
    
    /**
     * Calculate polling range with bootstrap and moving window logic
     */
    private function calculate_polling_range() {
        $current_time = time();
        $last_poll = get_option('hic_last_reliable_poll', 0);
        
        if ($last_poll === 0) {
            // Bootstrap: start from configured days ago
            $from_time = $current_time - (self::BOOTSTRAP_DAYS * DAY_IN_SECONDS);
            $to_time = $current_time;
            
            $this->log_structured('bootstrap_range', array(
                'bootstrap_days' => self::BOOTSTRAP_DAYS,
                'from_time' => $from_time,
                'to_time' => $to_time
            ));
        } else {
            // Moving window with overlap
            $from_time = max(0, $last_poll - self::OVERLAP_SECONDS);
            $to_time = $current_time;
        }
        
        return array(
            'from' => $from_time,
            'to' => $to_time,
            'from_date' => date('Y-m-d H:i:s', $from_time),
            'to_date' => date('Y-m-d H:i:s', $to_time)
        );
    }
    
    /**
     * Fetch reservations from API
     */
    private function fetch_reservations($range) {
        $api_url = hic_get_api_url();
        $prop_id = hic_get_property_id();
        
        if (empty($prop_id)) {
            throw new Exception('Property ID not configured');
        }
        
        $url = rtrim($api_url, '/') . "/reservations/{$prop_id}";
        $url .= '?from=' . urlencode(date('Y-m-d H:i:s', $range['from']));
        $url .= '&to=' . urlencode(date('Y-m-d H:i:s', $range['to']));
        
        $headers = array(
            'Accept' => 'application/json',
            'User-Agent' => 'WordPress/HIC-Plugin'
        );
        
        // Add authentication
        if (hic_has_basic_auth_credentials()) {
            $headers['Authorization'] = 'Basic ' . base64_encode(hic_get_api_email() . ':' . hic_get_api_password());
        } elseif (hic_get_api_key()) {
            $headers['X-API-Key'] = hic_get_api_key();
        }
        
        $response = wp_remote_get($url, array(
            'headers' => $headers,
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('API request failed: ' . $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            throw new Exception("API returned HTTP {$code}");
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response: ' . json_last_error_msg());
        }
        
        if (!is_array($data)) {
            throw new Exception('Invalid response format');
        }
        
        return $data;
    }
    
    /**
     * Process a single reservation with deduplication
     */
    private function process_reservation($reservation) {
        global $wpdb;
        
        if (!isset($reservation['id'])) {
            throw new Exception('Reservation missing ID');
        }
        
        $booking_id = $reservation['id'];
        $version_hash = $this->calculate_version_hash($reservation);
        
        // Check for duplicate in queue table
        $table = $wpdb->prefix . 'hic_booking_events';
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE booking_id = %s AND version_hash = %s",
            $booking_id,
            $version_hash
        ));
        
        if ($existing) {
            return 'duplicate';
        }
        
        // Insert into queue table
        $result = $wpdb->insert(
            $table,
            array(
                'booking_id' => $booking_id,
                'version_hash' => $version_hash,
                'raw_data' => json_encode($reservation),
                'poll_timestamp' => current_time('mysql'),
                'processed' => 0
            ),
            array('%s', '%s', '%s', '%s', '%d')
        );
        
        if ($result === false) {
            throw new Exception('Failed to insert reservation into queue');
        }
        
        // Process the reservation immediately
        try {
            hic_process_booking_data($reservation);
            
            // Mark as processed
            $wpdb->update(
                $table,
                array(
                    'processed' => 1,
                    'processed_at' => current_time('mysql')
                ),
                array('id' => $wpdb->insert_id),
                array('%d', '%s'),
                array('%d')
            );
            
            return 'new';
        } catch (Exception $e) {
            // Update error info
            $wpdb->update(
                $table,
                array(
                    'process_attempts' => 1,
                    'last_error' => $e->getMessage()
                ),
                array('id' => $wpdb->insert_id),
                array('%d', '%s'),
                array('%d')
            );
            
            throw $e;
        }
    }
    
    /**
     * Calculate version hash for deduplication
     */
    private function calculate_version_hash($reservation) {
        // Create hash based on key fields that indicate changes
        $hashable = array(
            'id' => isset($reservation['id']) ? $reservation['id'] : '',
            'status' => isset($reservation['status']) ? $reservation['status'] : '',
            'updated_at' => isset($reservation['updated_at']) ? $reservation['updated_at'] : '',
            'amount' => isset($reservation['amount']) ? $reservation['amount'] : '',
            'email' => isset($reservation['email']) ? $reservation['email'] : ''
        );
        
        return hash('sha256', json_encode($hashable));
    }
    
    /**
     * Acquire polling lock with TTL
     */
    private function acquire_lock() {
        $lock_value = time();
        
        // Check if lock exists and is still valid
        $existing_lock = get_transient(self::LOCK_KEY);
        if ($existing_lock && ($lock_value - $existing_lock) < self::LOCK_TTL) {
            return false; // Lock is held by another process
        }
        
        // Acquire lock
        return set_transient(self::LOCK_KEY, $lock_value, self::LOCK_TTL);
    }
    
    /**
     * Release polling lock
     */
    private function release_lock() {
        return delete_transient(self::LOCK_KEY);
    }
    
    /**
     * Check if updates polling should be active
     */
    private function should_poll_updates() {
        return hic_get_connection_type() === 'api' && 
               hic_get_api_url() && 
               hic_updates_enrich_contacts() && 
               hic_has_basic_auth_credentials();
    }
    
    /**
     * Perform updates polling
     */
    private function perform_updates_polling() {
        $stats = array(
            'updates_processed' => 0,
            'updates_errors' => 0
        );
        
        try {
            if (function_exists('hic_api_poll_updates')) {
                hic_api_poll_updates();
                $stats['updates_processed'] = 1;
                
                $this->log_structured('updates_polling_completed', array(
                    'success' => true
                ));
            }
        } catch (Exception $e) {
            $stats['updates_errors'] = 1;
            $this->log_structured('updates_polling_error', array(
                'error' => $e->getMessage()
            ));
        }
        
        return $stats;
    }
    
    /**
     * Check if retry notifications should be active
     */
    private function should_retry_notifications() {
        return function_exists('hic_should_schedule_retry_event') && 
               hic_should_schedule_retry_event();
    }
    
    /**
     * Perform retry notifications
     */
    private function perform_retry_notifications() {
        $stats = array(
            'retries_processed' => 0,
            'retries_errors' => 0
        );
        
        try {
            if (function_exists('hic_retry_failed_brevo_notifications')) {
                hic_retry_failed_brevo_notifications();
                $stats['retries_processed'] = 1;
                
                $this->log_structured('retry_notifications_completed', array(
                    'success' => true
                ));
            }
        } catch (Exception $e) {
            $stats['retries_errors'] = 1;
            $this->log_structured('retry_notifications_error', array(
                'error' => $e->getMessage()
            ));
        }
        
        return $stats;
    }
    
    /**
     * Structured JSON logging
     */
    private function log_structured($event, $data = array()) {
        $log_entry = array_merge(array(
            'timestamp' => current_time('mysql'),
            'event' => $event,
            'component' => 'HIC_Booking_Poller'
        ), $this->log_context, $data);
        
        $json_log = json_encode($log_entry);
        
        // Log to file if configured
        $log_file = hic_get_log_file();
        if (!empty($log_file)) {
            error_log($json_log . "\n", 3, $log_file);
        }
        
        // Also log to WordPress error log for critical events
        if (in_array($event, array('poll_error', 'watchdog_triggered', 'scheduler_event_creation_failed'))) {
            error_log('HIC Poller: ' . $json_log);
        }
        
        // Legacy text logging for compatibility
        hic_log("Reliable Poller [{$event}]: " . json_encode($data));
    }
    
    /**
     * Get polling statistics for diagnostics
     */
    public function get_stats() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'hic_booking_events';
        
        $stats = array();
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return array('error' => 'Queue table not found');
        }
        
        // Basic counts
        $stats['total_events'] = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $stats['processed_events'] = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE processed = 1");
        $stats['pending_events'] = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE processed = 0");
        $stats['error_events'] = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE last_error IS NOT NULL");
        
        // Recent activity (last 24 hours)
        $stats['events_24h'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE poll_timestamp > %s",
            date('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
        ));
        
        // Last poll info
        $last_poll = get_option('hic_last_reliable_poll', 0);
        $stats['last_poll'] = $last_poll;
        $stats['last_poll_human'] = $last_poll > 0 ? human_time_diff($last_poll) . ' fa' : 'Mai';
        $stats['lag_seconds'] = $last_poll > 0 ? time() - $last_poll : 0;
        
        // Lock status
        $lock = get_transient(self::LOCK_KEY);
        $stats['lock_active'] = $lock !== false;
        if ($lock) {
            $stats['lock_age'] = time() - $lock;
        }
        
        return $stats;
    }
    
    /**
     * Disable legacy WP-Cron events to prevent conflicts
     */
    public function disable_legacy_cron_events() {
        // Only disable if reliable polling should be active
        if (!$this->should_poll()) {
            return;
        }
        
        $legacy_events = array('hic_api_poll_event', 'hic_api_updates_event', 'hic_retry_failed_notifications_event');
        $disabled_count = 0;
        
        foreach ($legacy_events as $event) {
            $timestamp = wp_next_scheduled($event);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $event);
                $disabled_count++;
                
                $this->log_structured('legacy_event_disabled', array(
                    'event' => $event,
                    'was_scheduled_for' => $timestamp,
                    'reason' => 'reliable_polling_active'
                ));
            }
        }
        
        if ($disabled_count > 0) {
            $this->log_structured('legacy_migration', array(
                'disabled_events' => $disabled_count,
                'reliable_polling_active' => true
            ));
        }
    }
}

// Initialize the poller
new HIC_Booking_Poller();