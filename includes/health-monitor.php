<?php declare(strict_types=1);
/**
 * Health Monitoring System for HIC Plugin
 *
 * Provides comprehensive health checks and monitoring capabilities
 * for the Hotel in Cloud integration plugin.
 */

use FpHic\HIC_Booking_Poller;

if (!defined('ABSPATH')) exit;

class HIC_Health_Monitor {
    
    private $checks = [];
    private $metrics = [];
    private $alerts = [];
    
    public function __construct() {
        // Ensure WordPress functions are available
        if (!function_exists('add_action')) {
            return;
        }
        
        // Register health check hooks
        add_action('wp_ajax_hic_health_check', [$this, 'ajax_health_check']);
        add_action('wp_ajax_nopriv_hic_health_check', [$this, 'public_health_check']);
        add_action('rest_api_init', [$this, 'register_health_endpoint']);
        
        // Schedule regular health checks
        if (HIC_FEATURE_HEALTH_MONITORING) {
            add_action('hic_health_monitor_event', [$this, 'run_scheduled_health_check']);
            $this->schedule_health_checks();
        }
    }
    
    /**
     * Register REST API endpoint for health checks
     */
    public function register_health_endpoint() {
        register_rest_route('hic/v1', '/health', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_health_check'],
            'permission_callback' => [$this, 'rest_token_permission'],
            'args' => [
                'token' => [
                    'required' => true,
                    'type' => 'string'
                ],
            ]
        ]);
    }
    
    /**
     * Main health check method
     */
    public function check_health($level = HIC_DIAGNOSTIC_BASIC) {
        $health_data = [
            'status' => 'healthy',
            'timestamp' => current_time('mysql'),
            'version' => HIC_PLUGIN_VERSION,
            'checks' => [],
            'metrics' => [],
            'alerts' => []
        ];
        
        // Basic health checks
        $health_data['checks']['plugin_active'] = $this->check_plugin_active();
        $health_data['checks']['wp_requirements'] = $this->check_wp_requirements();
        $health_data['checks']['php_requirements'] = $this->check_php_requirements();
        
        if ($level === HIC_DIAGNOSTIC_DETAILED || $level === HIC_DIAGNOSTIC_FULL) {
            $health_data['checks']['api_connection'] = $this->check_api_connection();
            $health_data['checks']['polling_system'] = $this->check_polling_system();
            $health_data['checks']['integrations'] = $this->check_integrations();
            $health_data['checks']['log_health'] = $this->check_log_health();
        }
        
        if ($level === HIC_DIAGNOSTIC_FULL) {
            $health_data['checks']['database'] = $this->check_database_health();
            $health_data['checks']['file_permissions'] = $this->check_file_permissions();
            $health_data['checks']['memory_usage'] = $this->check_memory_usage();
            $health_data['metrics'] = $this->get_performance_metrics();
        }
        
        // Determine overall health status
        $health_data['status'] = $this->determine_overall_status($health_data['checks']);
        
        // Cache health check results
        set_transient(HIC_TRANSIENT_HEALTH_CHECK, $health_data, 300); // 5 minutes
        
        return $health_data;
    }
    
    /**
     * Check if plugin is active and properly loaded
     */
    private function check_plugin_active() {
        return [
            'status' => function_exists('hic_log') ? 'pass' : 'fail',
            'message' => function_exists('hic_log') ? 'Plugin loaded successfully' : 'Plugin functions not available',
            'details' => [
                'functions_loaded' => function_exists('hic_log'),
                'constants_loaded' => defined('HIC_PLUGIN_VERSION'),
                'classes_loaded' => class_exists(HIC_Booking_Poller::class)
            ]
        ];
    }
    
    /**
     * Check WordPress version requirements
     */
    private function check_wp_requirements() {
        $wp_version = get_bloginfo('version');
        $meets_requirements = version_compare($wp_version, HIC_MIN_WP_VERSION, '>=');
        
        return [
            'status' => $meets_requirements ? 'pass' : 'fail',
            'message' => $meets_requirements 
                ? "WordPress {$wp_version} meets requirements" 
                : "WordPress {$wp_version} below minimum " . HIC_MIN_WP_VERSION,
            'details' => [
                'current_version' => $wp_version,
                'required_version' => HIC_MIN_WP_VERSION,
                'meets_requirements' => $meets_requirements
            ]
        ];
    }
    
    /**
     * Check PHP version and extensions
     */
    private function check_php_requirements() {
        $php_version = PHP_VERSION;
        $meets_version = version_compare($php_version, HIC_MIN_PHP_VERSION, '>=');
        
        $required_extensions = ['curl', 'json', 'openssl'];
        $missing_extensions = [];
        
        foreach ($required_extensions as $ext) {
            if (!extension_loaded($ext)) {
                $missing_extensions[] = $ext;
            }
        }
        
        $status = $meets_version && empty($missing_extensions) ? 'pass' : 'fail';
        
        return [
            'status' => $status,
            'message' => $status === 'pass' 
                ? "PHP {$php_version} meets all requirements"
                : "PHP requirements not met",
            'details' => [
                'php_version' => $php_version,
                'required_version' => HIC_MIN_PHP_VERSION,
                'meets_version' => $meets_version,
                'missing_extensions' => $missing_extensions,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time')
            ]
        ];
    }
    
    /**
     * Check API connection health
     */
    private function check_api_connection() {
        if (!function_exists('\\FpHic\\hic_test_api_connection')) {
            return [
                'status' => 'warning',
                'message' => 'API test function not available',
                'details' => []
            ];
        }
        
        $prop_id = \FpHic\Helpers\hic_get_property_id();
        $email = \FpHic\Helpers\hic_get_api_email();
        $password = \FpHic\Helpers\hic_get_api_password();
        
        if (empty($prop_id) || empty($email) || empty($password)) {
            return [
                'status' => 'warning',
                'message' => 'API credentials not configured',
                'details' => [
                    'has_prop_id' => !empty($prop_id),
                    'has_email' => !empty($email),
                    'has_password' => !empty($password)
                ]
            ];
        }
        
        $test_result = \FpHic\hic_test_api_connection($prop_id, $email, $password);
        
        return [
            'status' => $test_result['success'] ? 'pass' : 'fail',
            'message' => $test_result['message'],
            'details' => $test_result
        ];
    }
    
    /**
     * Check polling system health
     */
    private function check_polling_system() {
        if (!\FpHic\Helpers\hic_reliable_polling_enabled()) {
            return [
                'status' => 'warning',
                'message' => 'Polling system disabled',
                'details' => ['enabled' => false]
            ];
        }
        
        $last_continuous = get_option('hic_last_continuous_poll');
        $last_deep = get_option('hic_last_deep_check');
        $now = current_time('timestamp');
        
        $continuous_delay = $last_continuous ? ($now - strtotime($last_continuous)) : null;
        $deep_delay = $last_deep ? ($now - strtotime($last_deep)) : null;
        
        $continuous_ok = $continuous_delay === null || $continuous_delay < (HIC_CONTINUOUS_POLLING_INTERVAL * 3);
        $deep_ok = $deep_delay === null || $deep_delay < (HIC_DEEP_CHECK_INTERVAL * 2);
        
        $status = $continuous_ok && $deep_ok ? 'pass' : 'warning';
        
        return [
            'status' => $status,
            'message' => $status === 'pass' ? 'Polling system active' : 'Polling delays detected',
            'details' => [
                'continuous_polling' => [
                    'last_run' => $last_continuous,
                    'delay_seconds' => $continuous_delay,
                    'status' => $continuous_ok ? 'ok' : 'delayed'
                ],
                'deep_check' => [
                    'last_run' => $last_deep,
                    'delay_seconds' => $deep_delay,
                    'status' => $deep_ok ? 'ok' : 'delayed'
                ]
            ]
        ];
    }
    
    /**
     * Check integration health (GA4, Meta, Brevo)
     */
    private function check_integrations() {
        $integrations = [
            'ga4' => [
                'enabled' => !empty(\FpHic\Helpers\hic_get_measurement_id()) && !empty(\FpHic\Helpers\hic_get_api_secret()),
                'measurement_id' => !empty(\FpHic\Helpers\hic_get_measurement_id()),
                'api_secret' => !empty(\FpHic\Helpers\hic_get_api_secret())
            ],
            'meta' => [
                'enabled' => !empty(\FpHic\Helpers\hic_get_fb_pixel_id()) && !empty(\FpHic\Helpers\hic_get_fb_access_token()),
                'pixel_id' => !empty(\FpHic\Helpers\hic_get_fb_pixel_id()),
                'access_token' => !empty(\FpHic\Helpers\hic_get_fb_access_token())
            ],
            'brevo' => [
                'enabled' => \FpHic\Helpers\hic_is_brevo_enabled() && !empty(\FpHic\Helpers\hic_get_brevo_api_key()),
                'api_key' => !empty(\FpHic\Helpers\hic_get_brevo_api_key()),
                'lists_configured' => !empty(\FpHic\Helpers\hic_get_brevo_list_it()) && !empty(\FpHic\Helpers\hic_get_brevo_list_en())
            ]
        ];
        
        $enabled_count = 0;
        foreach ($integrations as $integration) {
            if ($integration['enabled']) $enabled_count++;
        }
        
        return [
            'status' => $enabled_count > 0 ? 'pass' : 'warning',
            'message' => "{$enabled_count} integrations enabled",
            'details' => $integrations
        ];
    }
    
    /**
     * Check log file health
     */
    private function check_log_health() {
        $log_file = \FpHic\Helpers\hic_get_log_file();
        
        if (empty($log_file)) {
            return [
                'status' => 'warning',
                'message' => 'Log file not configured',
                'details' => []
            ];
        }
        
        $log_exists = file_exists($log_file);
        $log_writable = $log_exists && is_writable($log_file);
        $log_size = $log_exists ? filesize($log_file) : 0;
        $log_too_large = $log_size > HIC_LOG_MAX_SIZE;
        
        $status = $log_writable && !$log_too_large ? 'pass' : 'warning';
        
        return [
            'status' => $status,
            'message' => $status === 'pass' ? 'Log system healthy' : 'Log system issues detected',
            'details' => [
                'log_file' => $log_file,
                'exists' => $log_exists,
                'writable' => $log_writable,
                'size_bytes' => $log_size,
                'size_mb' => round($log_size / 1048576, 2),
                'too_large' => $log_too_large,
                'max_size_mb' => round(HIC_LOG_MAX_SIZE / 1048576, 2)
            ]
        ];
    }
    
    /**
     * Check database health
     */
    private function check_database_health() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_sid_gclid_mapping';
        $escaped_table_name = esc_sql($table_name);
        $table_sql = "`{$escaped_table_name}`";
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
        
        if (!$table_exists) {
            return [
                'status' => 'fail',
                'message' => 'Database table missing',
                'details' => ['table_name' => $table_name, 'exists' => false]
            ];
        }
        
        $row_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_sql}");
        
        return [
            'status' => 'pass',
            'message' => 'Database healthy',
            'details' => [
                'table_name' => $table_name,
                'exists' => true,
                'row_count' => (int) $row_count
            ]
        ];
    }
    
    /**
     * Check file permissions
     */
    private function check_file_permissions() {
        $checks = [
            'wp_content' => is_writable(WP_CONTENT_DIR),
            'plugin_dir' => is_writable(plugin_dir_path(__DIR__)),
            'log_dir' => is_writable(dirname(\FpHic\Helpers\hic_get_log_file()))
        ];
        
        $all_ok = array_reduce($checks, function($carry, $item) {
            return $carry && $item;
        }, true);
        
        return [
            'status' => $all_ok ? 'pass' : 'warning',
            'message' => $all_ok ? 'File permissions OK' : 'Some directories not writable',
            'details' => $checks
        ];
    }
    
    /**
     * Check memory usage
     */
    private function check_memory_usage() {
        $memory_used = memory_get_usage(true);
        $memory_limit = ini_get('memory_limit');
        $memory_limit_bytes = $this->parse_memory_limit($memory_limit);
        
        $usage_percent = $memory_limit_bytes > 0 ? ($memory_used / $memory_limit_bytes) * 100 : 0;
        $status = $usage_percent < 80 ? 'pass' : ($usage_percent < 95 ? 'warning' : 'fail');
        
        return [
            'status' => $status,
            'message' => sprintf('Memory usage: %.1f%%', $usage_percent),
            'details' => [
                'used_bytes' => $memory_used,
                'used_mb' => round($memory_used / 1048576, 2),
                'limit' => $memory_limit,
                'limit_bytes' => $memory_limit_bytes,
                'usage_percent' => round($usage_percent, 1)
            ]
        ];
    }
    
    /**
     * Get performance metrics
     */
    private function get_performance_metrics() {
        return [
            'api_calls_today' => get_option('hic_api_calls_today', 0),
            'successful_bookings_today' => get_option('hic_successful_bookings_today', 0),
            'failed_bookings_today' => get_option('hic_failed_bookings_today', 0),
            'last_polling_duration' => get_option('hic_last_polling_duration', 0),
            'average_processing_time' => get_option('hic_average_processing_time', 0)
        ];
    }
    
    /**
     * Determine overall health status
     */
    private function determine_overall_status($checks) {
        $statuses = array_column($checks, 'status');
        
        if (in_array('fail', $statuses)) {
            return 'unhealthy';
        }
        
        if (in_array('warning', $statuses)) {
            return 'degraded';
        }
        
        return 'healthy';
    }
    
    /**
     * Parse memory limit string to bytes
     */
    private function parse_memory_limit($memory_limit) {
        if (is_numeric($memory_limit)) {
            return (int) $memory_limit;
        }
        
        $unit = strtolower(substr($memory_limit, -1));
        $value = (int) substr($memory_limit, 0, -1);
        
        switch ($unit) {
            case 'g': return $value * 1073741824;
            case 'm': return $value * 1048576;
            case 'k': return $value * 1024;
            default: return $value;
        }
    }
    
    /**
     * Schedule health checks
     */
    private function schedule_health_checks() {
        // Use safe WordPress cron functions
        if (!\FpHic\Helpers\hic_safe_wp_next_scheduled('hic_health_monitor_event')) {
            \FpHic\Helpers\hic_safe_wp_schedule_event(time(), 'hourly', 'hic_health_monitor_event');
        }
    }

    /**
     * Validate public health check token
     */
    private function validate_health_token($token) {
        $saved = get_option('hic_health_token');
        return !empty($token) && !empty($saved) && hash_equals($saved, $token);
    }

    /**
     * Permission callback for REST health endpoint
     */
    public function rest_token_permission($request) {
        $token = sanitize_text_field($request->get_param('token'));
        return $this->validate_health_token($token);
    }

    /**
     * AJAX health check handler
     */
    public function ajax_health_check() {
        if (!check_ajax_referer('hic_monitor_nonce', 'nonce', false)) {
            wp_send_json(['error' => 'Invalid nonce'], 403);
        }

        if (!current_user_can('hic_manage')) {
            wp_send_json(['error' => 'Insufficient permissions'], 403);
        }

        $level = sanitize_text_field( wp_unslash( $_GET['level'] ?? HIC_DIAGNOSTIC_BASIC ) );
        $allowed_levels = [
            HIC_DIAGNOSTIC_BASIC,
            HIC_DIAGNOSTIC_DETAILED,
            HIC_DIAGNOSTIC_FULL,
        ];

        if ( ! in_array( $level, $allowed_levels, true ) ) {
            $level = HIC_DIAGNOSTIC_BASIC;
        }

        $health_data = $this->check_health($level);

        wp_send_json($health_data);
    }
    
    /**
     * Public health check (limited info)
     */
    public function public_health_check() {
        $token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
        if (!$this->validate_health_token($token)) {
            wp_send_json(['error' => 'Invalid token'], 403);
        }

        $health_data = [
            'status' => get_transient(HIC_TRANSIENT_HEALTH_CHECK)['status'] ?? 'unknown',
            'timestamp' => current_time('mysql'),
            'version' => HIC_PLUGIN_VERSION
        ];

        wp_send_json($health_data);
    }
    
    /**
     * REST API health check
     */
    public function rest_health_check($request) {
        $level = sanitize_text_field( $request->get_param('level') ?? HIC_DIAGNOSTIC_BASIC );
        $allowed_levels = [
            HIC_DIAGNOSTIC_BASIC,
            HIC_DIAGNOSTIC_DETAILED,
            HIC_DIAGNOSTIC_FULL,
        ];

        if ( ! in_array( $level, $allowed_levels, true ) ) {
            $level = HIC_DIAGNOSTIC_BASIC;
        }

        // Public endpoint only returns basic info
        if (!current_user_can('hic_manage')) {
            $level = HIC_DIAGNOSTIC_BASIC;
        }

        return $this->check_health($level);
    }
    
    /**
     * Run scheduled health check
     */
    public function run_scheduled_health_check() {
        $health_data = $this->check_health(HIC_DIAGNOSTIC_DETAILED);
        
        // Log critical issues
        if ($health_data['status'] === 'unhealthy') {
            hic_log('Health check failed: ' . json_encode($health_data));
            
            // Send alert if configured
            $this->send_health_alert($health_data);
        }
    }
    
    /**
     * Send health alert
     */
    private function send_health_alert($health_data) {
        $admin_email = \FpHic\Helpers\hic_get_admin_email();
        
        if (!empty($admin_email)) {
            $subject = 'HIC Plugin Health Alert';
            $message = sprintf(
                "Health check failed at %s\n\nStatus: %s\n\nDetails:\n%s",
                $health_data['timestamp'],
                $health_data['status'],
                json_encode($health_data['checks'], JSON_PRETTY_PRINT)
            );
            
            wp_mail($admin_email, $subject, $message);
        }
    }
}

/**
 * Get or create global HIC_Health_Monitor instance
 */
function hic_get_health_monitor() {
    if (!isset($GLOBALS['hic_health_monitor'])) {
        // Only instantiate if WordPress is loaded and functions are available
        if (HIC_FEATURE_HEALTH_MONITORING && function_exists('add_action') && function_exists('get_option')) {
            $GLOBALS['hic_health_monitor'] = new HIC_Health_Monitor();
        }
    }
    return isset($GLOBALS['hic_health_monitor']) ? $GLOBALS['hic_health_monitor'] : null;
}

/**
 * Initialize health monitor safely
 */
function hic_init_health_monitor() {
    // Use the global getter to ensure consistent instance management
    hic_get_health_monitor();
}

// Initialize health monitor when WordPress is ready
add_action('init', 'hic_init_health_monitor');