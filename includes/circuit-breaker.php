<?php declare(strict_types=1);

namespace FpHic\CircuitBreaker;

if (!defined('ABSPATH')) exit;

/**
 * Circuit Breaker Pattern - Enterprise Grade
 * 
 * Implements circuit breaker pattern with automatic fallback when external APIs
 * are down, intelligent retry queues with priority, and resilience monitoring.
 */

class CircuitBreakerManager {
    
    /** @var array Circuit breaker states */
    private const STATES = [
        'CLOSED' => 'closed',       // Normal operation
        'OPEN' => 'open',           // Circuit is open, calls are blocked
        'HALF_OPEN' => 'half_open'  // Testing if service has recovered
    ];
    
    /** @var int Default failure threshold before opening circuit */
    private const DEFAULT_FAILURE_THRESHOLD = 5;
    
    /** @var int Default timeout before testing if service recovered (seconds) */
    private const DEFAULT_RECOVERY_TIMEOUT = 300; // 5 minutes
    
    /** @var int Default success threshold to close circuit in half-open state */
    private const DEFAULT_SUCCESS_THRESHOLD = 3;
    
    /** @var array Retry queue priorities */
    private const PRIORITY_LEVELS = [
        'HIGH' => 1,     // New bookings, critical data
        'MEDIUM' => 2,   // Updates, non-critical data
        'LOW' => 3       // Historical data, cleanup operations
    ];
    
    public function __construct() {
        add_action('init', [$this, 'initialize_circuit_breaker'], 40);
        add_action('hic_process_retry_queue', [$this, 'process_retry_queue']);
        add_action('hic_check_circuit_breaker_recovery', [$this, 'check_circuit_recovery']);
        
        // API integration hooks
        add_filter('pre_http_request', [$this, 'intercept_api_requests'], 10, 3);
        add_action('http_api_debug', [$this, 'track_api_response'], 10, 5);
        
        // Admin integration
        add_action('admin_menu', [$this, 'add_circuit_breaker_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_circuit_breaker_assets']);
        
        // AJAX handlers
        add_action('wp_ajax_hic_get_circuit_status', [$this, 'ajax_get_circuit_status']);
        add_action('wp_ajax_hic_reset_circuit_breaker', [$this, 'ajax_reset_circuit_breaker']);
        add_action('wp_ajax_hic_get_retry_queue_status', [$this, 'ajax_get_retry_queue_status']);
        add_action('wp_ajax_hic_process_retry_queue_manual', [$this, 'ajax_process_retry_queue_manual']);
        
        // Schedule periodic tasks
        add_action('wp', [$this, 'schedule_circuit_breaker_tasks']);
    }
    
    /**
     * Initialize circuit breaker system
     */
    public function initialize_circuit_breaker() {
        $this->log('Initializing Circuit Breaker Manager');
        
        // Create circuit breaker status table
        $this->create_circuit_breaker_table();
        
        // Create retry queue table
        $this->create_retry_queue_table();
        
        // Initialize circuit breakers for each service
        $this->initialize_service_circuit_breakers();
        
        // Set up fallback mechanisms
        $this->setup_fallback_mechanisms();
    }
    
    /**
     * Create circuit breaker status tracking table
     */
    private function create_circuit_breaker_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_circuit_breakers';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            service_name VARCHAR(100) NOT NULL UNIQUE,
            state ENUM('closed', 'open', 'half_open') DEFAULT 'closed',
            failure_count INT DEFAULT 0,
            success_count INT DEFAULT 0,
            last_failure_time TIMESTAMP NULL,
            last_success_time TIMESTAMP NULL,
            last_state_change TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            failure_threshold INT DEFAULT 5,
            recovery_timeout INT DEFAULT 300,
            success_threshold INT DEFAULT 3,
            configuration LONGTEXT,
            
            INDEX idx_service_name (service_name),
            INDEX idx_state (state),
            INDEX idx_last_failure_time (last_failure_time)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        $this->log('Circuit breaker table created/verified');
    }
    
    /**
     * Create retry queue table
     */
    private function create_retry_queue_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_retry_queue';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            service_name VARCHAR(100) NOT NULL,
            operation_type VARCHAR(100) NOT NULL,
            priority ENUM('HIGH', 'MEDIUM', 'LOW') DEFAULT 'MEDIUM',
            payload LONGTEXT NOT NULL,
            original_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            scheduled_retry_at TIMESTAMP NULL,
            retry_count INT DEFAULT 0,
            max_retries INT DEFAULT 3,
            status ENUM('queued', 'processing', 'completed', 'failed', 'expired') DEFAULT 'queued',
            last_error TEXT,
            completion_time TIMESTAMP NULL,
            
            INDEX idx_service_name (service_name),
            INDEX idx_priority (priority),
            INDEX idx_scheduled_retry_at (scheduled_retry_at),
            INDEX idx_status (status),
            INDEX idx_retry_count (retry_count)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        $this->log('Retry queue table created/verified');
    }
    
    /**
     * Initialize circuit breakers for each service
     */
    private function initialize_service_circuit_breakers() {
        $services = [
            'hic_api' => [
                'name' => 'Hotel in Cloud API',
                'failure_threshold' => 5,
                'recovery_timeout' => 300,
                'success_threshold' => 3
            ],
            'google_analytics' => [
                'name' => 'Google Analytics 4',
                'failure_threshold' => 3,
                'recovery_timeout' => 180,
                'success_threshold' => 2
            ],
            'facebook_api' => [
                'name' => 'Facebook Conversions API',
                'failure_threshold' => 4,
                'recovery_timeout' => 240,
                'success_threshold' => 2
            ],
            'brevo_api' => [
                'name' => 'Brevo API',
                'failure_threshold' => 3,
                'recovery_timeout' => 180,
                'success_threshold' => 2
            ],
            'google_ads' => [
                'name' => 'Google Ads API',
                'failure_threshold' => 4,
                'recovery_timeout' => 300,
                'success_threshold' => 2
            ]
        ];
        
        foreach ($services as $service_key => $config) {
            $this->ensure_circuit_breaker_exists($service_key, $config);
        }
    }
    
    /**
     * Ensure circuit breaker exists for a service
     */
    private function ensure_circuit_breaker_exists($service_name, $config) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_circuit_breakers';
        
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE service_name = %s",
            $service_name
        ));
        
        if (!$existing) {
            $wpdb->insert($table_name, [
                'service_name' => $service_name,
                'state' => 'closed',
                'failure_threshold' => $config['failure_threshold'],
                'recovery_timeout' => $config['recovery_timeout'],
                'success_threshold' => $config['success_threshold'],
                'configuration' => json_encode($config)
            ]);
            
            $this->log("Created circuit breaker for service: {$service_name}");
        }
    }
    
    /**
     * Setup fallback mechanisms
     */
    private function setup_fallback_mechanisms() {
        // Set up database-only mode fallback
        add_action('hic_api_unavailable', [$this, 'enable_database_only_mode']);
        add_action('hic_api_recovered', [$this, 'disable_database_only_mode']);
        
        // Set up offline data storage
        add_action('hic_store_offline_booking', [$this, 'store_booking_offline']);
        add_action('hic_sync_offline_bookings', [$this, 'sync_offline_bookings']);
        
        $this->log('Fallback mechanisms configured');
    }
    
    /**
     * Schedule circuit breaker maintenance tasks
     */
    public function schedule_circuit_breaker_tasks() {
        // Schedule retry queue processing
        if (!wp_next_scheduled('hic_process_retry_queue')) {
            wp_schedule_event(time(), 'hic_every_minute', 'hic_process_retry_queue');
            $this->log('Scheduled retry queue processing');
        }
        
        // Schedule circuit breaker recovery checks
        if (!wp_next_scheduled('hic_check_circuit_breaker_recovery')) {
            wp_schedule_event(time(), 'hic_every_minute', 'hic_check_circuit_breaker_recovery');
            $this->log('Scheduled circuit breaker recovery checks');
        }
    }
    
    /**
     * Intercept API requests for circuit breaker evaluation
     */
    public function intercept_api_requests($preempt, $args, $url) {
        $service_name = $this->identify_service_from_url($url);
        
        if (!$service_name) {
            return $preempt; // Not a monitored service
        }
        
        $circuit_state = $this->get_circuit_state($service_name);
        
        switch ($circuit_state) {
            case self::STATES['OPEN']:
                // Circuit is open - block the request and use fallback
                $this->log("Circuit breaker OPEN for {$service_name}, blocking request");
                $this->queue_for_retry($service_name, 'api_request', $args, 'HIGH');
                return $this->get_fallback_response($service_name, $url, $args);
                
            case self::STATES['HALF_OPEN']:
                // Allow limited requests to test service recovery
                if ($this->should_allow_test_request($service_name)) {
                    $this->log("Circuit breaker HALF_OPEN for {$service_name}, allowing test request");
                    return $preempt; // Allow the request to proceed
                } else {
                    $this->log("Circuit breaker HALF_OPEN for {$service_name}, blocking additional request");
                    return $this->get_fallback_response($service_name, $url, $args);
                }
                
            case self::STATES['CLOSED']:
            default:
                // Normal operation - allow request
                return $preempt;
        }
    }
    
    /**
     * Track API responses to update circuit breaker state
     */
    public function track_api_response($response, $type, $url, $args, $request) {
        $service_name = $this->identify_service_from_url($url);
        
        if (!$service_name) {
            return; // Not a monitored service
        }
        
        if (is_wp_error($response)) {
            $this->record_failure($service_name, $response->get_error_message());
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            
            if ($response_code >= 200 && $response_code < 300) {
                $this->record_success($service_name);
            } elseif ($response_code >= 400) {
                $this->record_failure($service_name, "HTTP {$response_code}");
            }
        }
    }
    
    /**
     * Identify service from URL
     */
    private function identify_service_from_url($url) {
        $service_patterns = [
            'hic_api' => ['hotelincloud.com'],
            'google_analytics' => ['google-analytics.com', 'analytics.google.com'],
            'facebook_api' => ['graph.facebook.com'],
            'brevo_api' => ['api.brevo.com', 'api.sendinblue.com'],
            'google_ads' => ['googleads.googleapis.com']
        ];
        
        foreach ($service_patterns as $service => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($url, $pattern) !== false) {
                    return $service;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Get circuit breaker state for a service
     */
    private function get_circuit_state($service_name) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_circuit_breakers';
        
        $circuit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE service_name = %s",
            $service_name
        ), ARRAY_A);
        
        return $circuit ? $circuit['state'] : self::STATES['CLOSED'];
    }
    
    /**
     * Record API failure
     */
    private function record_failure($service_name, $error_message) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_circuit_breakers';
        
        $circuit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE service_name = %s",
            $service_name
        ), ARRAY_A);
        
        if (!$circuit) {
            return;
        }
        
        $new_failure_count = $circuit['failure_count'] + 1;
        $new_state = $circuit['state'];
        
        // Check if we should open the circuit
        if ($circuit['state'] === self::STATES['CLOSED'] && 
            $new_failure_count >= $circuit['failure_threshold']) {
            $new_state = self::STATES['OPEN'];
            $this->log("Circuit breaker OPENED for {$service_name} after {$new_failure_count} failures");
            
            // Trigger fallback activation
            do_action('hic_circuit_breaker_opened', $service_name, $error_message);
        }
        
        // Reset success count on failure
        $wpdb->update($table_name, [
            'failure_count' => $new_failure_count,
            'success_count' => 0,
            'last_failure_time' => current_time('mysql'),
            'state' => $new_state
        ], ['service_name' => $service_name]);
        
        $this->log("Recorded failure for {$service_name}: {$error_message}");
    }
    
    /**
     * Record API success
     */
    private function record_success($service_name) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_circuit_breakers';
        
        $circuit = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE service_name = %s",
            $service_name
        ), ARRAY_A);
        
        if (!$circuit) {
            return;
        }
        
        $new_success_count = $circuit['success_count'] + 1;
        $new_state = $circuit['state'];
        
        // Handle state transitions based on success
        switch ($circuit['state']) {
            case self::STATES['HALF_OPEN']:
                if ($new_success_count >= $circuit['success_threshold']) {
                    $new_state = self::STATES['CLOSED'];
                    $this->log("Circuit breaker CLOSED for {$service_name} after {$new_success_count} successful tests");
                    
                    // Trigger recovery
                    do_action('hic_circuit_breaker_closed', $service_name);
                }
                break;
                
            case self::STATES['OPEN']:
                // This shouldn't happen, but handle gracefully
                $new_state = self::STATES['HALF_OPEN'];
                break;
        }
        
        // Reset failure count on success
        $wpdb->update($table_name, [
            'success_count' => $new_success_count,
            'failure_count' => 0,
            'last_success_time' => current_time('mysql'),
            'state' => $new_state
        ], ['service_name' => $service_name]);
        
        $this->log("Recorded success for {$service_name}");
    }
    
    /**
     * Check if we should allow a test request in half-open state
     */
    private function should_allow_test_request($service_name) {
        // Simple rate limiting for test requests
        $last_test_key = "hic_circuit_test_{$service_name}";
        $last_test = get_transient($last_test_key);
        
        if ($last_test) {
            return false; // Too soon for another test
        }
        
        // Allow one test request per minute
        set_transient($last_test_key, time(), 60);
        return true;
    }
    
    /**
     * Get fallback response for blocked requests
     */
    private function get_fallback_response($service_name, $url, $args) {
        // Return a standardized fallback response
        $fallback_response = [
            'body' => json_encode([
                'success' => false,
                'error' => 'Service temporarily unavailable - circuit breaker active',
                'service' => $service_name,
                'fallback' => true,
                'retry_scheduled' => true
            ]),
            'response' => [
                'code' => 503,
                'message' => 'Service Unavailable'
            ]
        ];
        
        $this->log("Returned fallback response for {$service_name}");
        
        return $fallback_response;
    }
    
    /**
     * Queue operation for retry
     */
    private function queue_for_retry($service_name, $operation_type, $payload, $priority = 'MEDIUM') {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_retry_queue';
        
        // Calculate retry schedule based on priority
        $retry_delay = $this->calculate_retry_delay($priority);
        
        $wpdb->insert($table_name, [
            'service_name' => $service_name,
            'operation_type' => $operation_type,
            'priority' => $priority,
            'payload' => json_encode($payload),
            'scheduled_retry_at' => date('Y-m-d H:i:s', time() + $retry_delay),
            'status' => 'queued'
        ]);
        
        $this->log("Queued {$operation_type} for {$service_name} with priority {$priority}");
    }
    
    /**
     * Calculate retry delay based on priority
     */
    private function calculate_retry_delay($priority) {
        $delays = [
            'HIGH' => 60,    // 1 minute
            'MEDIUM' => 300, // 5 minutes
            'LOW' => 900     // 15 minutes
        ];
        
        return $delays[$priority] ?? $delays['MEDIUM'];
    }
    
    /**
     * Process retry queue
     */
    public function process_retry_queue() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_retry_queue';
        
        // Get items ready for retry, ordered by priority and scheduled time
        $retry_items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} 
             WHERE status = 'queued' 
             AND scheduled_retry_at <= %s 
             AND retry_count < max_retries
             ORDER BY 
                CASE priority 
                    WHEN 'HIGH' THEN 1 
                    WHEN 'MEDIUM' THEN 2 
                    WHEN 'LOW' THEN 3 
                END,
                scheduled_retry_at ASC
             LIMIT 10",
            current_time('mysql')
        ), ARRAY_A);
        
        if (empty($retry_items)) {
            return;
        }
        
        $this->log("Processing " . count($retry_items) . " items from retry queue");
        
        foreach ($retry_items as $item) {
            $this->process_retry_item($item);
        }
    }
    
    /**
     * Process individual retry item
     */
    private function process_retry_item($item) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_retry_queue';
        
        // Check if circuit is still open
        $circuit_state = $this->get_circuit_state($item['service_name']);
        
        if ($circuit_state === self::STATES['OPEN']) {
            // Circuit still open, reschedule for later
            $new_retry_time = date('Y-m-d H:i:s', time() + $this->calculate_retry_delay($item['priority']));
            
            $wpdb->update($table_name, [
                'scheduled_retry_at' => $new_retry_time,
                'retry_count' => $item['retry_count'] + 1
            ], ['id' => $item['id']]);
            
            return;
        }
        
        // Mark as processing
        $wpdb->update($table_name, [
            'status' => 'processing'
        ], ['id' => $item['id']]);
        
        try {
            // Attempt to execute the operation
            $success = $this->execute_retry_operation($item);
            
            if ($success) {
                // Mark as completed
                $wpdb->update($table_name, [
                    'status' => 'completed',
                    'completion_time' => current_time('mysql')
                ], ['id' => $item['id']]);
                
                $this->log("Successfully retried {$item['operation_type']} for {$item['service_name']}");
            } else {
                // Retry failed, reschedule or mark as failed
                $this->handle_retry_failure($item);
            }
            
        } catch (\Exception $e) {
            $this->handle_retry_failure($item, $e->getMessage());
        }
    }
    
    /**
     * Execute retry operation
     */
    private function execute_retry_operation($item) {
        $payload = json_decode($item['payload'], true);
        
        switch ($item['operation_type']) {
            case 'api_request':
                return $this->retry_api_request($item['service_name'], $payload);
            case 'booking_sync':
                return $this->retry_booking_sync($payload);
            case 'analytics_event':
                return $this->retry_analytics_event($payload);
            default:
                $this->log("Unknown operation type: {$item['operation_type']}");
                return false;
        }
    }
    
    /**
     * Retry API request
     */
    private function retry_api_request($service_name, $payload) {
        // Extract URL and args from payload
        $url = $payload['url'] ?? '';
        $args = $payload['args'] ?? [];
        
        if (empty($url)) {
            return false;
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        return $response_code >= 200 && $response_code < 300;
    }
    
    /**
     * Retry booking sync
     */
    private function retry_booking_sync($payload) {
        // Implementation depends on your booking sync logic
        // This is a placeholder
        return do_action('hic_retry_booking_sync', $payload);
    }
    
    /**
     * Retry analytics event
     */
    private function retry_analytics_event($payload) {
        // Implementation depends on your analytics integration
        // This is a placeholder
        return do_action('hic_retry_analytics_event', $payload);
    }
    
    /**
     * Handle retry failure
     */
    private function handle_retry_failure($item, $error_message = null) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_retry_queue';
        $new_retry_count = $item['retry_count'] + 1;
        
        if ($new_retry_count >= $item['max_retries']) {
            // Max retries reached, mark as failed
            $wpdb->update($table_name, [
                'status' => 'failed',
                'last_error' => $error_message,
                'completion_time' => current_time('mysql')
            ], ['id' => $item['id']]);
            
            $this->log("Retry failed permanently for {$item['operation_type']} (service: {$item['service_name']})");
        } else {
            // Reschedule with exponential backoff
            $backoff_delay = $this->calculate_retry_delay($item['priority']) * pow(2, $new_retry_count);
            $max_delay = 3600; // Maximum 1 hour delay
            $retry_delay = min($backoff_delay, $max_delay);
            
            $new_retry_time = date('Y-m-d H:i:s', time() + $retry_delay);
            
            $wpdb->update($table_name, [
                'status' => 'queued',
                'retry_count' => $new_retry_count,
                'scheduled_retry_at' => $new_retry_time,
                'last_error' => $error_message
            ], ['id' => $item['id']]);
            
            $this->log("Rescheduled retry for {$item['operation_type']} (attempt {$new_retry_count}/{$item['max_retries']})");
        }
    }
    
    /**
     * Check for circuit breaker recovery
     */
    public function check_circuit_recovery() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_circuit_breakers';
        
        // Get all open circuits that might be ready for recovery testing
        $open_circuits = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} 
             WHERE state = %s 
             AND last_failure_time <= DATE_SUB(NOW(), INTERVAL recovery_timeout SECOND)",
            self::STATES['OPEN']
        ), ARRAY_A);
        
        foreach ($open_circuits as $circuit) {
            $this->transition_to_half_open($circuit['service_name']);
        }
    }
    
    /**
     * Transition circuit to half-open state
     */
    private function transition_to_half_open($service_name) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_circuit_breakers';
        
        $wpdb->update($table_name, [
            'state' => self::STATES['HALF_OPEN'],
            'success_count' => 0
        ], ['service_name' => $service_name]);
        
        $this->log("Circuit breaker transitioned to HALF_OPEN for {$service_name}");
        
        do_action('hic_circuit_breaker_half_open', $service_name);
    }
    
    /**
     * Enable database-only mode fallback
     */
    public function enable_database_only_mode() {
        update_option('hic_database_only_mode', true);
        $this->log('Enabled database-only fallback mode');
    }
    
    /**
     * Disable database-only mode fallback
     */
    public function disable_database_only_mode() {
        update_option('hic_database_only_mode', false);
        $this->log('Disabled database-only fallback mode');
    }
    
    /**
     * Store booking offline during circuit breaker activation
     */
    public function store_booking_offline($booking_data) {
        $offline_bookings = get_option('hic_offline_bookings', []);
        $offline_bookings[] = [
            'timestamp' => time(),
            'data' => $booking_data
        ];
        
        // Limit offline storage
        if (count($offline_bookings) > 1000) {
            $offline_bookings = array_slice($offline_bookings, -1000);
        }
        
        update_option('hic_offline_bookings', $offline_bookings);
        $this->log('Stored booking offline during service outage');
    }
    
    /**
     * Sync offline bookings when service recovers
     */
    public function sync_offline_bookings() {
        $offline_bookings = get_option('hic_offline_bookings', []);
        
        if (empty($offline_bookings)) {
            return;
        }
        
        $this->log('Syncing ' . count($offline_bookings) . ' offline bookings');
        
        foreach ($offline_bookings as $booking) {
            $this->queue_for_retry('hic_api', 'booking_sync', $booking['data'], 'HIGH');
        }
        
        // Clear offline bookings after queuing
        delete_option('hic_offline_bookings');
    }
    
    /**
     * Add circuit breaker admin menu
     */
    public function add_circuit_breaker_menu() {
        add_submenu_page(
            'hic-monitoring',
            'Circuit Breaker Status',
            'Circuit Breakers',
            'manage_options',
            'hic-circuit-breakers',
            [$this, 'render_circuit_breaker_page']
        );
    }
    
    /**
     * Enqueue circuit breaker assets
     */
    public function enqueue_circuit_breaker_assets($hook) {
        if ($hook !== 'hic-monitoring_page_hic-circuit-breakers') {
            return;
        }
        
        wp_enqueue_script(
            'hic-circuit-breaker',
            plugins_url('assets/js/circuit-breaker.js', dirname(__FILE__, 2)),
            ['jquery'],
            '3.1.0',
            true
        );
        
        wp_localize_script('hic-circuit-breaker', 'hicCircuitBreaker', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hic_circuit_breaker_nonce')
        ]);
    }
    
    /**
     * Render circuit breaker admin page
     */
    public function render_circuit_breaker_page() {
        ?>
        <div class="wrap">
            <h1>Circuit Breaker Status</h1>
            
            <div class="hic-circuit-breaker-dashboard">
                <!-- Circuit Status Overview -->
                <div class="postbox">
                    <h2>Service Status Overview</h2>
                    <div class="inside">
                        <div id="circuit-status-grid">Loading circuit breaker status...</div>
                    </div>
                </div>
                
                <!-- Retry Queue Status -->
                <div class="postbox">
                    <h2>Retry Queue Status</h2>
                    <div class="inside">
                        <div id="retry-queue-status">Loading retry queue status...</div>
                        <p>
                            <button type="button" class="button button-primary" id="process-retry-queue">
                                Process Retry Queue Now
                            </button>
                        </p>
                    </div>
                </div>
                
                <!-- Manual Controls -->
                <div class="postbox">
                    <h2>Manual Controls</h2>
                    <div class="inside">
                        <p>Reset circuit breakers or force service checks:</p>
                        <div id="manual-controls">
                            <!-- Populated by JavaScript -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX: Get circuit status
     */
    public function ajax_get_circuit_status() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_circuit_breakers';
        
        $circuits = $wpdb->get_results(
            "SELECT * FROM {$table_name} ORDER BY service_name",
            ARRAY_A
        );
        
        wp_send_json_success($circuits);
    }
    
    /**
     * AJAX: Reset circuit breaker
     */
    public function ajax_reset_circuit_breaker() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        if (!check_ajax_referer('hic_circuit_breaker_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        $service_name = sanitize_text_field($_POST['service_name'] ?? '');
        
        if (empty($service_name)) {
            wp_send_json_error('Service name required');
        }
        
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_circuit_breakers';
        
        $updated = $wpdb->update($table_name, [
            'state' => self::STATES['CLOSED'],
            'failure_count' => 0,
            'success_count' => 0
        ], ['service_name' => $service_name]);
        
        if ($updated) {
            $this->log("Circuit breaker reset for {$service_name}");
            wp_send_json_success(['message' => "Circuit breaker reset for {$service_name}"]);
        } else {
            wp_send_json_error('Failed to reset circuit breaker');
        }
    }
    
    /**
     * AJAX: Get retry queue status
     */
    public function ajax_get_retry_queue_status() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_retry_queue';
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_items,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued_items,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_items,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_items,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_items
            FROM {$table_name}
        ", ARRAY_A);
        
        wp_send_json_success($stats);
    }
    
    /**
     * AJAX: Process retry queue manually
     */
    public function ajax_process_retry_queue_manual() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        if (!check_ajax_referer('hic_circuit_breaker_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        $this->process_retry_queue();
        
        wp_send_json_success(['message' => 'Retry queue processing initiated']);
    }
    
    /**
     * Log messages with circuit breaker prefix
     */
    private function log($message) {
        if (function_exists('\\FpHic\\Helpers\\hic_log')) {
            \FpHic\Helpers\hic_log("[Circuit Breaker] {$message}");
        }
    }
}

// Note: Class instantiation moved to main plugin file for proper admin menu ordering