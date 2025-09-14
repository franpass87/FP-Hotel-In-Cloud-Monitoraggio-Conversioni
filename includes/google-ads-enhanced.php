<?php declare(strict_types=1);

namespace FpHic\GoogleAdsEnhanced;

if (!defined('ABSPATH')) exit;

/**
 * Google Ads Enhanced Conversions - Enterprise Grade
 * 
 * Implements enhanced conversions with email hashing, first-party data matching,
 * and improved ROAS attribution for Google Ads campaigns.
 */

class GoogleAdsEnhancedConversions {
    
    /** @var string Google Ads API endpoint */
    private const GOOGLE_ADS_API_ENDPOINT = 'https://googleads.googleapis.com/v14/customers';
    
    /** @var array Supported hash algorithms for enhanced conversions */
    private const HASH_ALGORITHMS = ['sha256'];
    
    /** @var int Batch size for enhanced conversion uploads */
    private const BATCH_SIZE = 100;
    
    public function __construct() {
        add_action('init', [$this, 'initialize_enhanced_conversions'], 35);
        add_action('hic_process_booking', [$this, 'process_enhanced_conversion'], 10, 2);
        add_action('hic_enhanced_conversions_batch_upload', [$this, 'batch_upload_enhanced_conversions']);
        
        // Admin integration
        add_action('admin_menu', [$this, 'add_enhanced_conversions_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_enhanced_conversions_assets']);
        
        // AJAX handlers
        add_action('wp_ajax_hic_test_google_ads_connection', [$this, 'ajax_test_google_ads_connection']);
        add_action('wp_ajax_hic_upload_enhanced_conversions', [$this, 'ajax_upload_enhanced_conversions']);
        add_action('wp_ajax_hic_get_enhanced_conversion_stats', [$this, 'ajax_get_enhanced_conversion_stats']);
        
        // Hooks for booking processing
        add_action('hic_booking_processed', [$this, 'queue_enhanced_conversion'], 10, 3);
        add_filter('hic_booking_data', [$this, 'enrich_booking_data_for_enhanced_conversions'], 10, 2);
        
        // Schedule batch processing
        add_action('wp', [$this, 'schedule_batch_processing']);
    }
    
    /**
     * Initialize enhanced conversions system
     */
    public function initialize_enhanced_conversions() {
        $this->log('Initializing Google Ads Enhanced Conversions');
        
        // Create enhanced conversions tracking table
        $this->create_enhanced_conversions_table();
        
        // Create customer data mapping table
        $this->create_customer_data_table();
        
        // Initialize Google Ads API credentials
        $this->validate_google_ads_credentials();
        
        // Set up conversion tracking
        $this->setup_conversion_tracking();
    }
    
    /**
     * Create enhanced conversions tracking table
     */
    private function create_enhanced_conversions_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_enhanced_conversions';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            booking_id VARCHAR(255) NOT NULL,
            gclid VARCHAR(255),
            customer_email_hash VARCHAR(64),
            customer_phone_hash VARCHAR(64),
            customer_first_name_hash VARCHAR(64),
            customer_last_name_hash VARCHAR(64),
            customer_address_hash VARCHAR(64),
            conversion_value DECIMAL(10,2),
            conversion_currency VARCHAR(3) DEFAULT 'EUR',
            conversion_action_id VARCHAR(255),
            upload_status ENUM('pending', 'uploaded', 'failed') DEFAULT 'pending',
            upload_attempts INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            uploaded_at TIMESTAMP NULL,
            error_message TEXT,
            google_ads_response LONGTEXT,
            
            INDEX idx_booking_id (booking_id),
            INDEX idx_gclid (gclid),
            INDEX idx_upload_status (upload_status),
            INDEX idx_created_at (created_at),
            UNIQUE KEY idx_booking_gclid (booking_id, gclid)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        $this->log('Enhanced conversions table created/verified');
    }
    
    /**
     * Create customer data mapping table
     */
    private function create_customer_data_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_customer_data';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            booking_id VARCHAR(255) NOT NULL,
            raw_email VARCHAR(255),
            raw_phone VARCHAR(50),
            raw_first_name VARCHAR(100),
            raw_last_name VARCHAR(100),
            raw_address TEXT,
            data_source ENUM('booking', 'crm', 'manual') DEFAULT 'booking',
            consent_given BOOLEAN DEFAULT FALSE,
            privacy_policy_version VARCHAR(20),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_booking_id (booking_id),
            INDEX idx_raw_email (raw_email),
            INDEX idx_data_source (data_source),
            INDEX idx_consent_given (consent_given)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        $this->log('Customer data table created/verified');
    }
    
    /**
     * Validate Google Ads API credentials
     */
    private function validate_google_ads_credentials() {
        $settings = get_option('hic_google_ads_enhanced_settings', []);
        
        $required_fields = [
            'customer_id',
            'developer_token',
            'client_id',
            'client_secret',
            'refresh_token'
        ];
        
        $missing_fields = [];
        foreach ($required_fields as $field) {
            if (empty($settings[$field])) {
                $missing_fields[] = $field;
            }
        }
        
        if (!empty($missing_fields)) {
            $this->log('Missing Google Ads credentials: ' . implode(', ', $missing_fields));
            update_option('hic_google_ads_enhanced_status', 'credentials_missing');
            return false;
        }
        
        update_option('hic_google_ads_enhanced_status', 'credentials_configured');
        return true;
    }
    
    /**
     * Setup conversion tracking configuration
     */
    private function setup_conversion_tracking() {
        $settings = get_option('hic_google_ads_enhanced_settings', []);
        
        // Default conversion actions
        $default_conversion_actions = [
            'booking_completed' => [
                'name' => 'Hotel Booking Completed',
                'category' => 'PURCHASE',
                'value_settings' => 'USE_ACTUAL_VALUE'
            ],
            'booking_confirmation' => [
                'name' => 'Hotel Booking Confirmation',
                'category' => 'PURCHASE',
                'value_settings' => 'USE_ACTUAL_VALUE'
            ]
        ];
        
        if (!isset($settings['conversion_actions'])) {
            $settings['conversion_actions'] = $default_conversion_actions;
            update_option('hic_google_ads_enhanced_settings', $settings);
        }
        
        $this->log('Conversion tracking configuration initialized');
    }
    
    /**
     * Process enhanced conversion for a booking
     */
    public function process_enhanced_conversion($booking_data, $customer_data) {
        if (empty($booking_data['gclid'])) {
            $this->log('No GCLID found for booking, skipping enhanced conversion');
            return;
        }
        
        try {
            // Hash customer data
            $hashed_data = $this->hash_customer_data($customer_data);
            
            // Create enhanced conversion record
            $conversion_id = $this->create_enhanced_conversion_record($booking_data, $hashed_data);
            
            if ($conversion_id) {
                $this->log("Enhanced conversion record created: ID {$conversion_id}");
                
                // Queue for batch upload or upload immediately based on settings
                $upload_mode = get_option('hic_enhanced_conversions_upload_mode', 'batch');
                
                if ($upload_mode === 'immediate') {
                    $this->upload_single_enhanced_conversion($conversion_id);
                } else {
                    $this->queue_for_batch_upload($conversion_id);
                }
            }
            
        } catch (\Exception $e) {
            $this->log('Error processing enhanced conversion: ' . $e->getMessage());
        }
    }
    
    /**
     * Hash customer data according to Google Ads requirements
     */
    private function hash_customer_data($customer_data) {
        $hashed_data = [];
        
        // Email hashing (normalized and hashed)
        if (!empty($customer_data['email'])) {
            $normalized_email = $this->normalize_email($customer_data['email']);
            $hashed_data['email_hash'] = hash('sha256', $normalized_email);
        }
        
        // Phone number hashing (normalized and hashed)
        if (!empty($customer_data['phone'])) {
            $normalized_phone = $this->normalize_phone($customer_data['phone']);
            $hashed_data['phone_hash'] = hash('sha256', $normalized_phone);
        }
        
        // First name hashing (normalized and hashed)
        if (!empty($customer_data['first_name'])) {
            $normalized_first_name = $this->normalize_name($customer_data['first_name']);
            $hashed_data['first_name_hash'] = hash('sha256', $normalized_first_name);
        }
        
        // Last name hashing (normalized and hashed)
        if (!empty($customer_data['last_name'])) {
            $normalized_last_name = $this->normalize_name($customer_data['last_name']);
            $hashed_data['last_name_hash'] = hash('sha256', $normalized_last_name);
        }
        
        // Address hashing (if available)
        if (!empty($customer_data['address'])) {
            $normalized_address = $this->normalize_address($customer_data['address']);
            $hashed_data['address_hash'] = hash('sha256', $normalized_address);
        }
        
        return $hashed_data;
    }
    
    /**
     * Normalize email for hashing
     */
    private function normalize_email($email) {
        // Remove leading/trailing whitespace and convert to lowercase
        $email = trim(strtolower($email));
        
        // Remove dots from Gmail addresses (but not from domain)
        if (strpos($email, '@gmail.com') !== false) {
            $parts = explode('@', $email);
            $parts[0] = str_replace('.', '', $parts[0]);
            
            // Remove everything after + in Gmail addresses
            if (strpos($parts[0], '+') !== false) {
                $parts[0] = substr($parts[0], 0, strpos($parts[0], '+'));
            }
            
            $email = implode('@', $parts);
        }
        
        return $email;
    }
    
    /**
     * Normalize phone number for hashing
     */
    private function normalize_phone($phone) {
        // Remove all non-digit characters
        $phone = preg_replace('/\D/', '', $phone);
        
        // Add country code if not present (assuming Italian numbers if no country code)
        if (strlen($phone) === 10 && substr($phone, 0, 1) === '3') {
            $phone = '39' . $phone; // Add Italy country code
        }
        
        return $phone;
    }
    
    /**
     * Normalize name for hashing
     */
    private function normalize_name($name) {
        // Remove leading/trailing whitespace, convert to lowercase, remove extra spaces
        return trim(strtolower(preg_replace('/\s+/', ' ', $name)));
    }
    
    /**
     * Normalize address for hashing
     */
    private function normalize_address($address) {
        // Remove leading/trailing whitespace, convert to lowercase, remove extra spaces
        return trim(strtolower(preg_replace('/\s+/', ' ', $address)));
    }
    
    /**
     * Create enhanced conversion record in database
     */
    private function create_enhanced_conversion_record($booking_data, $hashed_data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_enhanced_conversions';
        
        $conversion_value = $this->calculate_conversion_value($booking_data);
        $conversion_action_id = $this->get_conversion_action_id('booking_completed');
        
        $result = $wpdb->insert($table_name, [
            'booking_id' => $booking_data['booking_id'] ?? '',
            'gclid' => $booking_data['gclid'] ?? '',
            'customer_email_hash' => $hashed_data['email_hash'] ?? null,
            'customer_phone_hash' => $hashed_data['phone_hash'] ?? null,
            'customer_first_name_hash' => $hashed_data['first_name_hash'] ?? null,
            'customer_last_name_hash' => $hashed_data['last_name_hash'] ?? null,
            'customer_address_hash' => $hashed_data['address_hash'] ?? null,
            'conversion_value' => $conversion_value,
            'conversion_action_id' => $conversion_action_id,
            'upload_status' => 'pending'
        ]);
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Calculate conversion value from booking data
     */
    private function calculate_conversion_value($booking_data) {
        // Default value if not provided
        $default_value = 150.00;
        
        if (isset($booking_data['total_amount']) && $booking_data['total_amount'] > 0) {
            return floatval($booking_data['total_amount']);
        }
        
        if (isset($booking_data['revenue']) && $booking_data['revenue'] > 0) {
            return floatval($booking_data['revenue']);
        }
        
        // Estimate based on nights and room type if available
        if (isset($booking_data['nights']) && $booking_data['nights'] > 0) {
            $avg_nightly_rate = 100; // Default average
            return $booking_data['nights'] * $avg_nightly_rate;
        }
        
        return $default_value;
    }
    
    /**
     * Get conversion action ID for specific action type
     */
    private function get_conversion_action_id($action_type) {
        $settings = get_option('hic_google_ads_enhanced_settings', []);
        
        if (isset($settings['conversion_actions'][$action_type]['action_id'])) {
            return $settings['conversion_actions'][$action_type]['action_id'];
        }
        
        // Return default or placeholder
        return 'AUTO_GENERATED_' . strtoupper($action_type);
    }
    
    /**
     * Queue enhanced conversion for batch upload
     */
    public function queue_enhanced_conversion($booking_id, $gclid, $customer_data) {
        if (empty($gclid)) {
            return;
        }
        
        $booking_data = [
            'booking_id' => $booking_id,
            'gclid' => $gclid
        ];
        
        $this->process_enhanced_conversion($booking_data, $customer_data);
    }
    
    /**
     * Queue conversion for batch upload
     */
    private function queue_for_batch_upload($conversion_id) {
        $queue = get_option('hic_enhanced_conversions_queue', []);
        $queue[] = $conversion_id;
        
        // Limit queue size
        if (count($queue) > 1000) {
            $queue = array_slice($queue, -1000);
        }
        
        update_option('hic_enhanced_conversions_queue', $queue);
        
        $this->log("Queued enhanced conversion {$conversion_id} for batch upload");
    }
    
    /**
     * Schedule batch processing
     */
    public function schedule_batch_processing() {
        if (!wp_next_scheduled('hic_enhanced_conversions_batch_upload')) {
            wp_schedule_event(time(), 'hourly', 'hic_enhanced_conversions_batch_upload');
            $this->log('Scheduled enhanced conversions batch upload');
        }
    }
    
    /**
     * Batch upload enhanced conversions
     */
    public function batch_upload_enhanced_conversions() {
        $batch_size = get_option('hic_enhanced_conversions_batch_size', self::BATCH_SIZE);
        $queue = get_option('hic_enhanced_conversions_queue', []);
        
        if (empty($queue)) {
            $this->log('No enhanced conversions queued for upload');
            return;
        }
        
        $batch = array_splice($queue, 0, $batch_size);
        update_option('hic_enhanced_conversions_queue', $queue);
        
        $this->log("Processing batch of " . count($batch) . " enhanced conversions");
        
        $results = $this->upload_enhanced_conversions_batch($batch);
        
        $this->log("Batch upload completed: {$results['success']} successful, {$results['failed']} failed");
    }
    
    /**
     * Upload batch of enhanced conversions to Google Ads
     */
    private function upload_enhanced_conversions_batch($conversion_ids) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_enhanced_conversions';
        $placeholders = implode(',', array_fill(0, count($conversion_ids), '%d'));
        
        $conversions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id IN ({$placeholders}) AND upload_status = 'pending'",
            ...$conversion_ids
        ), ARRAY_A);
        
        if (empty($conversions)) {
            return ['success' => 0, 'failed' => 0];
        }
        
        $success_count = 0;
        $failed_count = 0;
        
        // Process conversions in groups by GCLID for efficiency
        $grouped_conversions = $this->group_conversions_by_gclid($conversions);
        
        foreach ($grouped_conversions as $gclid => $group_conversions) {
            try {
                $upload_result = $this->upload_enhanced_conversions_to_google_ads($group_conversions);
                
                if ($upload_result['success']) {
                    $success_count += count($group_conversions);
                    $this->mark_conversions_as_uploaded($group_conversions, $upload_result['response']);
                } else {
                    $failed_count += count($group_conversions);
                    $this->mark_conversions_as_failed($group_conversions, $upload_result['error']);
                }
                
            } catch (\Exception $e) {
                $failed_count += count($group_conversions);
                $this->mark_conversions_as_failed($group_conversions, $e->getMessage());
                $this->log('Batch upload error: ' . $e->getMessage());
            }
        }
        
        return ['success' => $success_count, 'failed' => $failed_count];
    }
    
    /**
     * Group conversions by GCLID for efficient upload
     */
    private function group_conversions_by_gclid($conversions) {
        $grouped = [];
        
        foreach ($conversions as $conversion) {
            $gclid = $conversion['gclid'];
            if (!isset($grouped[$gclid])) {
                $grouped[$gclid] = [];
            }
            $grouped[$gclid][] = $conversion;
        }
        
        return $grouped;
    }
    
    /**
     * Upload enhanced conversions to Google Ads API
     */
    private function upload_enhanced_conversions_to_google_ads($conversions) {
        $settings = get_option('hic_google_ads_enhanced_settings', []);
        
        if (!$this->validate_google_ads_credentials()) {
            throw new \Exception('Google Ads credentials not properly configured');
        }
        
        $access_token = $this->get_google_ads_access_token();
        
        if (!$access_token) {
            throw new \Exception('Failed to obtain Google Ads access token');
        }
        
        $customer_id = str_replace('-', '', $settings['customer_id']);
        $url = self::GOOGLE_ADS_API_ENDPOINT . "/{$customer_id}/conversionUploads:uploadClickConversions";
        
        $request_data = [
            'conversions' => $this->format_conversions_for_api($conversions),
            'partialFailureEnabled' => true
        ];
        
        $headers = [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
            'developer-token: ' . $settings['developer_token'],
            'login-customer-id: ' . $customer_id
        ];
        
        $response = $this->make_google_ads_api_request($url, $request_data, $headers);
        
        if ($response['success']) {
            return [
                'success' => true,
                'response' => $response['data']
            ];
        } else {
            return [
                'success' => false,
                'error' => $response['error']
            ];
        }
    }
    
    /**
     * Format conversions for Google Ads API
     */
    private function format_conversions_for_api($conversions) {
        $formatted_conversions = [];
        
        foreach ($conversions as $conversion) {
            $api_conversion = [
                'gclid' => $conversion['gclid'],
                'conversionAction' => 'customers/' . str_replace('-', '', get_option('hic_google_ads_customer_id')) . '/conversionActions/' . $conversion['conversion_action_id'],
                'conversionDateTime' => $this->format_conversion_datetime($conversion['created_at']),
                'conversionValue' => floatval($conversion['conversion_value']),
                'currencyCode' => $conversion['conversion_currency']
            ];
            
            // Add user identifiers for enhanced conversions
            $user_identifiers = [];
            
            if (!empty($conversion['customer_email_hash'])) {
                $user_identifiers[] = [
                    'hashedEmail' => $conversion['customer_email_hash']
                ];
            }
            
            if (!empty($conversion['customer_phone_hash'])) {
                $user_identifiers[] = [
                    'hashedPhoneNumber' => $conversion['customer_phone_hash']
                ];
            }
            
            if (!empty($conversion['customer_first_name_hash']) && !empty($conversion['customer_last_name_hash'])) {
                $address_info = [
                    'hashedFirstName' => $conversion['customer_first_name_hash'],
                    'hashedLastName' => $conversion['customer_last_name_hash']
                ];
                
                if (!empty($conversion['customer_address_hash'])) {
                    $address_info['hashedStreetAddress'] = $conversion['customer_address_hash'];
                }
                
                $user_identifiers[] = [
                    'addressInfo' => $address_info
                ];
            }
            
            if (!empty($user_identifiers)) {
                $api_conversion['userIdentifiers'] = $user_identifiers;
            }
            
            $formatted_conversions[] = $api_conversion;
        }
        
        return $formatted_conversions;
    }
    
    /**
     * Format conversion datetime for API
     */
    private function format_conversion_datetime($datetime) {
        return date('Y-m-d H:i:s', strtotime($datetime)) . ' Europe/Rome';
    }
    
    /**
     * Get Google Ads access token
     */
    private function get_google_ads_access_token() {
        $settings = get_option('hic_google_ads_enhanced_settings', []);
        
        $token_data = [
            'client_id' => $settings['client_id'],
            'client_secret' => $settings['client_secret'],
            'refresh_token' => $settings['refresh_token'],
            'grant_type' => 'refresh_token'
        ];
        
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => $token_data,
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            $this->log('Failed to refresh Google Ads token: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['access_token'])) {
            return $data['access_token'];
        }
        
        $this->log('No access token in Google Ads refresh response: ' . $body);
        return false;
    }
    
    /**
     * Make Google Ads API request
     */
    private function make_google_ads_api_request($url, $data, $headers) {
        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => json_encode($data),
            'timeout' => 60
        ]);
        
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => $response->get_error_message()
            ];
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code >= 200 && $response_code < 300) {
            return [
                'success' => true,
                'data' => json_decode($response_body, true)
            ];
        } else {
            return [
                'success' => false,
                'error' => "HTTP {$response_code}: {$response_body}"
            ];
        }
    }
    
    /**
     * Mark conversions as uploaded
     */
    private function mark_conversions_as_uploaded($conversions, $api_response) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_enhanced_conversions';
        $ids = array_column($conversions, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name} 
             SET upload_status = 'uploaded', 
                 uploaded_at = NOW(), 
                 google_ads_response = %s 
             WHERE id IN ({$placeholders})",
            json_encode($api_response),
            ...$ids
        ));
    }
    
    /**
     * Mark conversions as failed
     */
    private function mark_conversions_as_failed($conversions, $error_message) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_enhanced_conversions';
        $ids = array_column($conversions, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name} 
             SET upload_status = 'failed', 
                 upload_attempts = upload_attempts + 1,
                 error_message = %s 
             WHERE id IN ({$placeholders})",
            $error_message,
            ...$ids
        ));
    }
    
    /**
     * Add enhanced conversions admin menu
     */
    public function add_enhanced_conversions_menu() {
        add_submenu_page(
            'hic-monitoring',
            'Google Ads Enhanced Conversions',
            'Enhanced Conversions',
            'manage_options',
            'hic-enhanced-conversions',
            [$this, 'render_enhanced_conversions_page']
        );
    }
    
    /**
     * Enqueue enhanced conversions assets
     */
    public function enqueue_enhanced_conversions_assets($hook) {
        if ($hook !== 'hic-monitoring_page_hic-enhanced-conversions') {
            return;
        }
        
        wp_enqueue_script(
            'hic-enhanced-conversions',
            plugins_url('assets/js/enhanced-conversions.js', dirname(__FILE__, 2)),
            ['jquery'],
            '3.1.0',
            true
        );
        
        wp_localize_script('hic-enhanced-conversions', 'hicEnhancedConversions', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hic_enhanced_conversions_nonce')
        ]);
    }
    
    /**
     * Render enhanced conversions admin page
     */
    public function render_enhanced_conversions_page() {
        $settings = get_option('hic_google_ads_enhanced_settings', []);
        $status = get_option('hic_google_ads_enhanced_status', 'not_configured');
        
        ?>
        <div class="wrap">
            <h1>Google Ads Enhanced Conversions</h1>
            
            <div class="hic-enhanced-conversions-dashboard">
                <!-- Configuration Status -->
                <div class="postbox">
                    <h2>Configuration Status</h2>
                    <div class="inside">
                        <p class="status-indicator status-<?php echo esc_attr($status); ?>">
                            Status: <?php echo esc_html(ucfirst(str_replace('_', ' ', $status))); ?>
                        </p>
                        
                        <?php if ($status === 'credentials_missing'): ?>
                            <p>Please configure your Google Ads API credentials below.</p>
                        <?php elseif ($status === 'credentials_configured'): ?>
                            <p>Enhanced conversions are configured and ready to use.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- API Configuration -->
                <div class="postbox">
                    <h2>Google Ads API Configuration</h2>
                    <div class="inside">
                        <form method="post" action="options.php">
                            <?php settings_fields('hic_google_ads_enhanced_settings'); ?>
                            <table class="form-table">
                                <tr>
                                    <th>Customer ID</th>
                                    <td>
                                        <input type="text" name="hic_google_ads_enhanced_settings[customer_id]" 
                                               value="<?php echo esc_attr($settings['customer_id'] ?? ''); ?>" 
                                               placeholder="123-456-7890" class="regular-text">
                                        <p class="description">Your Google Ads customer ID (format: 123-456-7890)</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Developer Token</th>
                                    <td>
                                        <input type="text" name="hic_google_ads_enhanced_settings[developer_token]" 
                                               value="<?php echo esc_attr($settings['developer_token'] ?? ''); ?>" 
                                               class="regular-text">
                                    </td>
                                </tr>
                                <tr>
                                    <th>Client ID</th>
                                    <td>
                                        <input type="text" name="hic_google_ads_enhanced_settings[client_id]" 
                                               value="<?php echo esc_attr($settings['client_id'] ?? ''); ?>" 
                                               class="regular-text">
                                    </td>
                                </tr>
                                <tr>
                                    <th>Client Secret</th>
                                    <td>
                                        <input type="password" name="hic_google_ads_enhanced_settings[client_secret]" 
                                               value="<?php echo esc_attr($settings['client_secret'] ?? ''); ?>" 
                                               class="regular-text">
                                    </td>
                                </tr>
                                <tr>
                                    <th>Refresh Token</th>
                                    <td>
                                        <input type="text" name="hic_google_ads_enhanced_settings[refresh_token]" 
                                               value="<?php echo esc_attr($settings['refresh_token'] ?? ''); ?>" 
                                               class="regular-text">
                                    </td>
                                </tr>
                            </table>
                            <?php submit_button('Save Configuration'); ?>
                        </form>
                        
                        <p>
                            <button type="button" class="button" id="test-google-ads-connection">
                                Test Connection
                            </button>
                        </p>
                    </div>
                </div>
                
                <!-- Conversion Statistics -->
                <div class="postbox">
                    <h2>Enhanced Conversion Statistics</h2>
                    <div class="inside">
                        <div id="enhanced-conversion-stats">Loading statistics...</div>
                    </div>
                </div>
                
                <!-- Manual Upload -->
                <div class="postbox">
                    <h2>Manual Upload</h2>
                    <div class="inside">
                        <p>Upload pending enhanced conversions to Google Ads:</p>
                        <button type="button" class="button button-primary" id="upload-enhanced-conversions">
                            Upload Pending Conversions
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX: Test Google Ads connection
     */
    public function ajax_test_google_ads_connection() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        if (!check_ajax_referer('hic_enhanced_conversions_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        try {
            $access_token = $this->get_google_ads_access_token();
            
            if ($access_token) {
                wp_send_json_success(['message' => 'Google Ads connection successful']);
            } else {
                wp_send_json_error(['message' => 'Failed to obtain access token']);
            }
            
        } catch (\Exception $e) {
            wp_send_json_error(['message' => 'Connection test failed: ' . $e->getMessage()]);
        }
    }
    
    /**
     * AJAX: Get enhanced conversion statistics
     */
    public function ajax_get_enhanced_conversion_stats() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'hic_enhanced_conversions';
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_conversions,
                SUM(CASE WHEN upload_status = 'uploaded' THEN 1 ELSE 0 END) as uploaded_conversions,
                SUM(CASE WHEN upload_status = 'pending' THEN 1 ELSE 0 END) as pending_conversions,
                SUM(CASE WHEN upload_status = 'failed' THEN 1 ELSE 0 END) as failed_conversions,
                SUM(conversion_value) as total_value
            FROM {$table_name}
        ", ARRAY_A);
        
        wp_send_json_success($stats);
    }
    
    /**
     * AJAX: Upload enhanced conversions
     */
    public function ajax_upload_enhanced_conversions() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        if (!check_ajax_referer('hic_enhanced_conversions_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        
        try {
            // Trigger immediate batch upload
            $this->batch_upload_enhanced_conversions();
            
            wp_send_json_success(['message' => 'Enhanced conversions upload initiated']);
            
        } catch (\Exception $e) {
            wp_send_json_error(['message' => 'Upload failed: ' . $e->getMessage()]);
        }
    }
    
    /**
     * Log messages with enhanced conversions prefix
     */
    private function log($message) {
        if (function_exists('\\FpHic\\Helpers\\hic_log')) {
            \FpHic\Helpers\hic_log("[Google Ads Enhanced] {$message}");
        }
    }
}

// Note: Class instantiation moved to main plugin file for proper admin menu ordering