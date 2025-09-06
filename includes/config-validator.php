<?php
/**
 * Configuration Validation System for HIC Plugin
 * 
 * Provides comprehensive validation for all plugin settings and configurations.
 */

if (!defined('ABSPATH')) exit;

class HIC_Config_Validator {
    
    private $errors = [];
    private $warnings = [];
    
    /**
     * Validate all plugin configurations
     */
    public function validate_all_config() {
        $this->errors = [];
        $this->warnings = [];
        
        // Validate core settings
        $this->validate_api_settings();
        $this->validate_integration_settings();
        $this->validate_system_settings();
        $this->validate_security_settings();
        
        return [
            'valid' => empty($this->errors),
            'errors' => $this->errors,
            'warnings' => $this->warnings
        ];
    }
    
    /**
     * Validate API settings
     */
    private function validate_api_settings() {
        $connection_type = Helpers\hic_get_connection_type();
        
        if (!in_array($connection_type, ['webhook', 'polling'])) {
            $this->errors[] = 'Invalid connection type: ' . $connection_type;
        }
        
        if ($connection_type === 'polling') {
            $this->validate_polling_config();
        } elseif ($connection_type === 'webhook') {
            $this->validate_webhook_config();
        }
    }
    
    /**
     * Validate polling configuration
     */
    private function validate_polling_config() {
        $prop_id = Helpers\hic_get_property_id();
        $email = Helpers\hic_get_api_email();
        $password = Helpers\hic_get_api_password();
        $api_url = Helpers\hic_get_api_url();
        
        if (empty($prop_id) || !is_numeric($prop_id)) {
            $this->errors[] = 'Property ID is required and must be numeric for API polling';
        }
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = 'Valid API email is required for API polling';
        }
        
        if (empty($password)) {
            $this->errors[] = 'API password is required for API polling';
        }
        
        if (!empty($api_url) && !filter_var($api_url, FILTER_VALIDATE_URL)) {
            $this->errors[] = 'API URL must be a valid URL';
        }
        
        // Validate polling interval
        $interval = Helpers\hic_get_option('polling_interval', 'two_minutes');
        $valid_intervals = ['one_minute', 'two_minutes', 'five_minutes'];
        if (!in_array($interval, $valid_intervals)) {
            $this->warnings[] = 'Invalid polling interval, using default';
        }
    }
    
    /**
     * Validate webhook configuration
     */
    private function validate_webhook_config() {
        $token = Helpers\hic_get_webhook_token();
        
        if (empty($token)) {
            $this->errors[] = 'Webhook token is required for webhook mode';
        } elseif (strlen($token) < 20) {
            $this->warnings[] = 'Webhook token should be at least 20 characters for security';
        }
    }
    
    /**
     * Validate integration settings
     */
    private function validate_integration_settings() {
        $this->validate_ga4_config();
        $this->validate_meta_config();
        $this->validate_brevo_config();
    }
    
    /**
     * Validate GA4 configuration
     */
    private function validate_ga4_config() {
        $measurement_id = Helpers\hic_get_measurement_id();
        $api_secret = Helpers\hic_get_api_secret();
        
        if (!empty($measurement_id)) {
            if (!preg_match('/^G-[A-Z0-9]+$/', $measurement_id)) {
                $this->errors[] = 'GA4 Measurement ID format is invalid (should be G-XXXXXXXXXX)';
            }
            
            if (empty($api_secret)) {
                $this->errors[] = 'GA4 API Secret is required when Measurement ID is set';
            }
        }
        
        if (!empty($api_secret) && empty($measurement_id)) {
            $this->warnings[] = 'GA4 API Secret is set but Measurement ID is missing';
        }
    }
    
    /**
     * Validate Meta/Facebook configuration
     */
    private function validate_meta_config() {
        $pixel_id = Helpers\hic_get_fb_pixel_id();
        $access_token = Helpers\hic_get_fb_access_token();
        
        if (!empty($pixel_id)) {
            if (!is_numeric($pixel_id)) {
                $this->errors[] = 'Facebook Pixel ID must be numeric';
            }
            
            if (empty($access_token)) {
                $this->errors[] = 'Facebook Access Token is required when Pixel ID is set';
            }
        }
        
        if (!empty($access_token)) {
            if (strlen($access_token) < 50) {
                $this->warnings[] = 'Facebook Access Token seems too short';
            }
            
            if (empty($pixel_id)) {
                $this->warnings[] = 'Facebook Access Token is set but Pixel ID is missing';
            }
        }
    }
    
    /**
     * Validate Brevo configuration
     */
    private function validate_brevo_config() {
        if (!Helpers\hic_is_brevo_enabled()) {
            return;
        }
        
        $api_key = Helpers\hic_get_brevo_api_key();
        $list_it = Helpers\hic_get_brevo_list_it();
        $list_en = Helpers\hic_get_brevo_list_en();
        $list_default = Helpers\hic_get_brevo_list_default();
        
        if (empty($api_key)) {
            $this->errors[] = 'Brevo API Key is required when Brevo is enabled';
        }
        
        if (!is_numeric($list_it) || $list_it <= 0) {
            $this->warnings[] = 'Brevo Italian list ID should be a positive number';
        }
        
        if (!is_numeric($list_en) || $list_en <= 0) {
            $this->warnings[] = 'Brevo English list ID should be a positive number';
        }
        
        if (!is_numeric($list_default) || $list_default <= 0) {
            $this->warnings[] = 'Brevo default list ID should be a positive number';
        }
        
        // Validate event endpoint
        $event_endpoint = Helpers\hic_get_brevo_event_endpoint();
        if (!filter_var($event_endpoint, FILTER_VALIDATE_URL)) {
            $this->errors[] = 'Brevo event endpoint must be a valid URL';
        }
        
        // Validate alias list if configured
        $alias_list = Helpers\hic_get_brevo_list_alias();
        if (!empty($alias_list) && (!is_numeric($alias_list) || $alias_list <= 0)) {
            $this->warnings[] = 'Brevo alias list ID should be a positive number';
        }
    }
    
    /**
     * Validate system settings
     */
    private function validate_system_settings() {
        // Validate log file
        $log_file = Helpers\hic_get_log_file();
        if (!empty($log_file)) {
            $log_dir = dirname($log_file);
            if (!is_dir($log_dir) && !wp_mkdir_p($log_dir)) {
                $this->errors[] = 'Cannot create log directory: ' . $log_dir;
            } elseif (!is_writable($log_dir)) {
                $this->errors[] = 'Log directory is not writable: ' . $log_dir;
            }
        }
        
        // Validate admin email
        $admin_email = Helpers\hic_get_admin_email();
        if (!empty($admin_email) && !filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = 'Admin email address is not valid';
        }
        
        // Check PHP requirements
        if (version_compare(PHP_VERSION, HIC_MIN_PHP_VERSION, '<')) {
            $this->errors[] = sprintf(
                'PHP version %s is required, current version is %s',
                HIC_MIN_PHP_VERSION,
                PHP_VERSION
            );
        }
        
        // Check required PHP extensions
        $required_extensions = ['curl', 'json', 'openssl'];
        foreach ($required_extensions as $ext) {
            if (!extension_loaded($ext)) {
                $this->errors[] = "Required PHP extension missing: {$ext}";
            }
        }
    }
    
    /**
     * Validate security settings
     */
    private function validate_security_settings() {
        // Check if running over HTTPS in production
        if (!is_ssl() && !$this->is_local_environment()) {
            $this->warnings[] = 'HTTPS is recommended for production environments';
        }
        
        // Check WordPress version
        $wp_version = get_bloginfo('version');
        if (version_compare($wp_version, HIC_MIN_WP_VERSION, '<')) {
            $this->errors[] = sprintf(
                'WordPress version %s is required, current version is %s',
                HIC_MIN_WP_VERSION,
                $wp_version
            );
        }
        
        // Check file permissions
        $plugin_dir = plugin_dir_path(__DIR__);
        if (is_writable($plugin_dir)) {
            $this->warnings[] = 'Plugin directory is writable, consider restricting permissions';
        }
    }
    
    /**
     * Check if running in local environment
     */
    private function is_local_environment() {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $local_hosts = ['localhost', '127.0.0.1', '::1'];
        
        foreach ($local_hosts as $local_host) {
            if (strpos($host, $local_host) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Validate specific setting
     */
    public function validate_setting($setting, $value) {
        $errors = [];
        
        switch ($setting) {
            case 'measurement_id':
                if (!empty($value) && !preg_match('/^G-[A-Z0-9]+$/', $value)) {
                    $errors[] = 'GA4 Measurement ID format is invalid';
                }
                break;
                
            case 'api_secret':
                if (!empty($value) && strlen($value) < 20) {
                    $errors[] = 'GA4 API Secret seems too short';
                }
                break;
                
            case 'fb_pixel_id':
                if (!empty($value) && !is_numeric($value)) {
                    $errors[] = 'Facebook Pixel ID must be numeric';
                }
                break;
                
            case 'brevo_api_key':
                if (!empty($value) && strlen($value) < 30) {
                    $errors[] = 'Brevo API Key seems too short';
                }
                break;
                
            case 'admin_email':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Admin email is not valid';
                }
                break;
                
            case 'property_id':
                if (!empty($value) && (!is_numeric($value) || $value <= 0)) {
                    $errors[] = 'Property ID must be a positive number';
                }
                break;
                
            case 'api_email':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'API email is not valid';
                }
                break;
        }
        
        return $errors;
    }
    
    /**
     * Get configuration summary
     */
    public function get_config_summary() {
        $summary = [
            'connection_type' => Helpers\hic_get_connection_type(),
            'integrations' => [
                'ga4' => !empty(Helpers\hic_get_measurement_id()) && !empty(Helpers\hic_get_api_secret()),
                'meta' => !empty(Helpers\hic_get_fb_pixel_id()) && !empty(Helpers\hic_get_fb_access_token()),
                'brevo' => Helpers\hic_is_brevo_enabled() && !empty(Helpers\hic_get_brevo_api_key())
            ],
            'features' => [
                'polling' => Helpers\hic_reliable_polling_enabled(),
                'email_enrichment' => Helpers\hic_updates_enrich_contacts(),
                'real_time_sync' => Helpers\hic_realtime_brevo_sync_enabled(),
                'debug_mode' => Helpers\hic_is_debug_verbose()
            ],
            'system' => [
                'php_version' => PHP_VERSION,
                'wp_version' => get_bloginfo('version'),
                'plugin_version' => HIC_PLUGIN_VERSION,
                'ssl_enabled' => is_ssl()
            ]
        ];
        
        return $summary;
    }
}

/**
 * Get or create global HIC_Config_Validator instance
 */
function hic_get_config_validator() {
    if (!isset($GLOBALS['hic_config_validator'])) {
        // Only instantiate if WordPress is loaded and functions are available
        if (function_exists('get_option') && function_exists('get_bloginfo')) {
            $GLOBALS['hic_config_validator'] = new HIC_Config_Validator();
        }
    }
    return isset($GLOBALS['hic_config_validator']) ? $GLOBALS['hic_config_validator'] : null;
}