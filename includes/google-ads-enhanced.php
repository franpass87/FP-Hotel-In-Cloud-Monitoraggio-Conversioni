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

    /** @var string Default ISO country used when no explicit fallback is configured */
    private const DEFAULT_PHONE_COUNTRY = 'IT';
    
    public function __construct() {
        if (\is_admin()) {
            $this->register_basic_admin_hooks();
        }

        if (!$this->is_enhanced_conversions_enabled()) {
            return;
        }

        add_action('init', [$this, 'initialize_enhanced_conversions'], 35);
        add_action('hic_process_booking', [$this, 'process_enhanced_conversion'], 10, 2);
        add_action('hic_enhanced_conversions_batch_upload', [$this, 'batch_upload_enhanced_conversions']);
        add_action('hic_booking_processed', [$this, 'queue_enhanced_conversion'], 10, 3);
        add_filter('hic_booking_payload', [$this, 'enrich_booking_data_for_enhanced_conversions'], 10, 3);
        add_action('wp', [$this, 'schedule_batch_processing']);

        if (\is_admin()) {
            $this->register_full_admin_hooks();
        }
    }

    /**
     * Register admin hooks that are required even when Enhanced Conversions is disabled.
     */
    private function register_basic_admin_hooks(): void {
        add_action('admin_menu', [$this, 'add_enhanced_conversions_menu'], 50);
        add_action('admin_init', [$this, 'handle_enhanced_conversions_form']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_enhanced_conversions_assets']);
        add_action('wp_ajax_hic_test_google_ads_connection', [$this, 'ajax_test_google_ads_connection']);
        add_action('wp_ajax_hic_upload_enhanced_conversions', [$this, 'ajax_upload_enhanced_conversions']);
        add_action('wp_ajax_hic_get_enhanced_conversion_stats', [$this, 'ajax_get_enhanced_conversion_stats']);
    }

    /**
     * Register the complete set of admin-only hooks.
     */
    private function register_full_admin_hooks(): void {
        // Placeholder for hooks that should execute only when the integration is fully enabled.
    }

    /**
     * Normalize booking metadata for enhanced conversion processing.
     *
     * @param array $booking      Normalized booking payload provided by the processor.
     * @param array $context      Tracking context such as gclid and sid values.
     * @param array $raw_booking  Original booking array before normalization.
     *
     * @return array Enriched booking payload ready for downstream hooks.
     */
    public function enrich_booking_data_for_enhanced_conversions(array $booking, array $context = [], array $raw_booking = []): array {
        $enriched = $booking;

        $source_booking = $booking;
        if (!empty($raw_booking)) {
            $source_booking += $raw_booking;
        }

        $booking_id = '';
        if (isset($enriched['booking_id']) && is_scalar($enriched['booking_id']) && $enriched['booking_id'] !== '') {
            $booking_id = (string) $enriched['booking_id'];
        } elseif (isset($enriched['reservation_id']) && is_scalar($enriched['reservation_id']) && $enriched['reservation_id'] !== '') {
            $booking_id = (string) $enriched['reservation_id'];
        } else {
            $reservation_helper = '\\FpHic\\Helpers\\hic_extract_reservation_id';
            if (\function_exists($reservation_helper)) {
                $extracted_id = $reservation_helper($source_booking);
                if (!empty($extracted_id)) {
                    $booking_id = (string) $extracted_id;
                }
            }
        }

        if ($booking_id !== '') {
            $enriched['booking_id'] = $booking_id;
            if (empty($enriched['reservation_id'])) {
                $enriched['reservation_id'] = $booking_id;
            }
        }

        $gclid = null;
        if (isset($context['gclid']) && is_scalar($context['gclid']) && $context['gclid'] !== '') {
            $gclid = (string) $context['gclid'];
        } elseif (isset($enriched['gclid']) && is_scalar($enriched['gclid']) && $enriched['gclid'] !== '') {
            $gclid = (string) $enriched['gclid'];
        } elseif (isset($source_booking['gclid']) && is_scalar($source_booking['gclid']) && $source_booking['gclid'] !== '') {
            $gclid = (string) $source_booking['gclid'];
        }
        if ($gclid !== null && $gclid !== '') {
            $enriched['gclid'] = \sanitize_text_field($gclid);
        }

        $amount_value = null;
        foreach (['total_amount', 'revenue', 'amount', 'value'] as $amount_key) {
            if (isset($source_booking[$amount_key]) && is_scalar($source_booking[$amount_key]) && $source_booking[$amount_key] !== '') {
                $amount_value = $source_booking[$amount_key];
                break;
            }
        }
        if ($amount_value !== null) {
            $normalizer = '\\FpHic\\Helpers\\hic_normalize_price';
            if (\function_exists($normalizer)) {
                $normalized_amount = (float) $normalizer($amount_value);
            } else {
                $normalized_amount = (float) preg_replace('/[^0-9.]/', '', (string) $amount_value);
            }

            $enriched['amount'] = $normalized_amount;
            $enriched['total_amount'] = $normalized_amount;
            $enriched['revenue'] = $normalized_amount;
        }

        if (isset($source_booking['currency']) && is_scalar($source_booking['currency'])) {
            $currency = strtoupper(\sanitize_text_field((string) $source_booking['currency']));
            if ($currency !== '') {
                $enriched['currency'] = $currency;
            }
        }

        $email = '';
        foreach (['customer_email', 'email', 'guest_email'] as $email_field) {
            if (!empty($source_booking[$email_field]) && is_scalar($source_booking[$email_field])) {
                $email_candidate = \sanitize_email((string) $source_booking[$email_field]);
                if ($email_candidate !== '') {
                    $email = $email_candidate;
                    break;
                }
            }
        }
        if ($email !== '') {
            $enriched['email'] = $email;
            $enriched['customer_email'] = $email;
        }

        $first_name = '';
        foreach (['customer_first_name', 'customer_firstname', 'first_name', 'firstname', 'guest_first_name', 'guest_firstname'] as $field) {
            if (!empty($source_booking[$field]) && is_scalar($source_booking[$field])) {
                $first_name = \sanitize_text_field((string) $source_booking[$field]);
                break;
            }
        }

        $last_name = '';
        foreach (['customer_last_name', 'customer_lastname', 'last_name', 'lastname', 'guest_last_name', 'guest_lastname'] as $field) {
            if (!empty($source_booking[$field]) && is_scalar($source_booking[$field])) {
                $last_name = \sanitize_text_field((string) $source_booking[$field]);
                break;
            }
        }

        if (($first_name === '' || $last_name === '') && !empty($source_booking['guest_name']) && is_scalar($source_booking['guest_name'])) {
            $parts = preg_split('/\s+/', trim((string) $source_booking['guest_name']), 2);
            if ($first_name === '' && !empty($parts[0])) {
                $first_name = \sanitize_text_field($parts[0]);
            }
            if ($last_name === '' && !empty($parts[1])) {
                $last_name = \sanitize_text_field($parts[1]);
            }
        }

        if (($first_name === '' || $last_name === '') && !empty($source_booking['name']) && is_scalar($source_booking['name'])) {
            $parts = preg_split('/\s+/', trim((string) $source_booking['name']), 2);
            if ($first_name === '' && !empty($parts[0])) {
                $first_name = \sanitize_text_field($parts[0]);
            }
            if ($last_name === '' && !empty($parts[1])) {
                $last_name = \sanitize_text_field($parts[1]);
            }
        }

        if ($first_name !== '') {
            $enriched['first_name'] = $first_name;
            $enriched['customer_first_name'] = $first_name;
        }

        if ($last_name !== '') {
            $enriched['last_name'] = $last_name;
            $enriched['customer_last_name'] = $last_name;
        }

        $raw_phone = '';
        foreach (['customer_phone', 'phone', 'client_phone', 'whatsapp'] as $phone_field) {
            if (!empty($source_booking[$phone_field]) && is_scalar($source_booking[$phone_field])) {
                $raw_phone = (string) $source_booking[$phone_field];
                break;
            }
        }

        if ($raw_phone !== '') {
            $phone_helper = '\\FpHic\\Helpers\\hic_detect_phone_language';
            $normalized_phone = '';
            $phone_language = null;

            if (\function_exists($phone_helper)) {
                $details = $phone_helper($raw_phone);
                if (is_array($details)) {
                    $normalized_phone = $details['phone'] ?? '';
                    $phone_language = $details['language'] ?? null;
                }
            } else {
                $normalized_phone = preg_replace('/[^0-9+]/', '', $raw_phone);
            }

            if (!empty($normalized_phone)) {
                $enriched['phone'] = $normalized_phone;
                $enriched['customer_phone'] = $normalized_phone;
            }

            if (!empty($phone_language)) {
                $enriched['phone_language'] = $phone_language;
            }
        }

        $address_fields = ['address', 'address_line1', 'address_line_1', 'street', 'city', 'province', 'state', 'postal_code', 'zip', 'country'];
        $address_parts = [];
        foreach ($address_fields as $field) {
            if (!empty($booking[$field]) && is_scalar($booking[$field])) {
                $address_parts[] = \sanitize_text_field((string) $booking[$field]);
            }
        }

        $address_parts = array_values(array_filter(array_unique($address_parts)));
        if (!empty($address_parts)) {
            $address_string = implode(', ', $address_parts);
            $enriched['address'] = $address_string;
            $enriched['customer_address'] = $address_string;
        }

        return $enriched;
    }

    /**
     * Check if Enhanced Conversions is enabled
     */
    private function is_enhanced_conversions_enabled() {
        // Check explicit enable/disable option
        $enabled = get_option('hic_google_ads_enhanced_enabled', false);
        
        // Also check if basic configuration is present
        $settings = get_option('hic_google_ads_enhanced_settings', []);
        $has_config = !empty($settings['customer_id']) && !empty($settings['conversion_action_id']);
        
        return $enabled && $has_config;
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
            $hashed_data = $this->hash_customer_data($customer_data, $booking_data);
            
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
     * Hash customer data according to Google Ads requirements.
     *
     * @param array $customer_data Raw customer identifiers.
     * @param array $booking_data  Booking metadata used as context for normalization.
     * @return array<string,string> Hashed identifiers ready for storage.
     */
    private function hash_customer_data($customer_data, array $booking_data = []) {
        $hashed_data = [];

        // Email hashing (normalized and hashed)
        if (!empty($customer_data['email'])) {
            $normalized_email = $this->normalize_email($customer_data['email']);
            $hashed_data['email_hash'] = hash('sha256', $normalized_email);
        }

        // Phone number hashing (normalized and hashed)
        if (!empty($customer_data['phone'])) {
            $normalized_phone = $this->normalize_phone($customer_data['phone'], $customer_data, $booking_data);
            if (!empty($normalized_phone)) {
                $hashed_data['phone_hash'] = hash('sha256', $normalized_phone);
            }
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
     * Normalize phone number for hashing by building an E.164 string.
     *
     * @param mixed $phone          Raw phone value.
     * @param array $customer_data  Customer fields that may contain locale hints.
     * @param array $booking_data   Booking context (SID, language, etc.).
     * @return string|null          Normalized E.164 phone number or null when it cannot be determined.
     */
    private function normalize_phone($phone, array $customer_data = [], array $booking_data = []) {
        $context = [
            'customer_data' => $customer_data,
            'booking_data' => $booking_data,
        ];

        if (!empty($booking_data['sid']) && is_scalar($booking_data['sid'])) {
            $context['sid'] = (string) $booking_data['sid'];
        }

        $default_country = $this->get_default_phone_country();
        if ($default_country !== null) {
            $context['default_country'] = $default_country;
        }

        $context['logger'] = function (string $message, string $level = 'warning'): void {
            $this->log($message, $level);
        };

        return \FpHic\Helpers\hic_normalize_phone_for_hash($phone, $context);
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
     * Retrieve the configured default country for phone normalization.
     *
     * @return array{type:string,value:string}|null
     */
    private function get_default_phone_country(): ?array {
        return \FpHic\Helpers\hic_phone_get_default_country();
    }

    /**
     * Map language or locale hints to a country candidate.
     *
     * @param mixed $language Language code or locale.
     * @return array{type:string,value:string}|null
     */
    private function map_language_to_country($language): ?array {
        return \FpHic\Helpers\hic_phone_map_language_to_country($language);
    }

    /**
     * Normalize country identifiers provided by settings or payloads.
     *
     * @param mixed $value Raw country input.
     * @return array{type:string,value:string}|null
     */
    private function normalize_country_value($value): ?array {
        return \FpHic\Helpers\hic_phone_normalize_country_value($value);
    }

    /**
     * Resolve the telephone calling code for the provided country hint.
     *
     * @param array{type:string,value:string} $country
     * @return string|null
     */
    private function resolve_calling_code(array $country): ?string {
        return \FpHic\Helpers\hic_phone_resolve_calling_code($country);
    }

    /**
     * Determine whether the national trunk prefix (leading zero) must be preserved.
     *
     * @param array{type:string,value:string} $country
     * @return bool
     */
    private function should_retain_trunk_zero(array $country): bool {
        return \FpHic\Helpers\hic_phone_should_retain_trunk_zero($country);
    }

    /**
     * Normalize currency code to ISO 4217 format
     */
    private function normalize_currency_code($currency) {
        if (!is_scalar($currency)) {
            return '';
        }

        $currency_code = (string) $currency;

        if (function_exists('sanitize_text_field')) {
            $currency_code = sanitize_text_field($currency_code);
        } else {
            $currency_code = trim($currency_code);
        }

        $currency_code = strtoupper($currency_code);
        $currency_code = preg_replace('/[^A-Z]/', '', $currency_code);

        if (!is_string($currency_code) || strlen($currency_code) !== 3) {
            return '';
        }

        return $currency_code;
    }

    /**
     * Create enhanced conversion record in database
     */
    private function create_enhanced_conversion_record($booking_data, $hashed_data) {
        $conversion_action_id = $this->get_conversion_action_id('booking_completed');

        if ($conversion_action_id === null || $conversion_action_id === '') {
            $booking_reference = '';

            if (isset($booking_data['booking_id']) && is_scalar($booking_data['booking_id']) && $booking_data['booking_id'] !== '') {
                $booking_reference = (string) $booking_data['booking_id'];
            } elseif (isset($booking_data['gclid']) && is_scalar($booking_data['gclid']) && $booking_data['gclid'] !== '') {
                $booking_reference = 'GCLID ' . (string) $booking_data['gclid'];
            }

            $context = $booking_reference !== '' ? sprintf(' for %s', $booking_reference) : '';
            $this->log(sprintf('Skipping enhanced conversion record creation%s: missing conversion action ID.', $context));

            return false;
        }

        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            $this->log('Skipping enhanced conversion record creation: database connection is not available.');
            return false;
        }

        $table_name = $wpdb->prefix . 'hic_enhanced_conversions';

        $conversion_value = $this->calculate_conversion_value($booking_data);

        $conversion_currency = 'EUR';
        foreach (['currency', 'currency_code', 'conversion_currency'] as $currency_field) {
            if (isset($booking_data[$currency_field])) {
                $normalized_currency = $this->normalize_currency_code($booking_data[$currency_field]);
                if ($normalized_currency !== '') {
                    $conversion_currency = $normalized_currency;
                    break;
                }
            }
        }

        $result = $wpdb->insert($table_name, [
            'booking_id' => $booking_data['booking_id'] ?? '',
            'gclid' => $booking_data['gclid'] ?? '',
            'customer_email_hash' => $hashed_data['email_hash'] ?? null,
            'customer_phone_hash' => $hashed_data['phone_hash'] ?? null,
            'customer_first_name_hash' => $hashed_data['first_name_hash'] ?? null,
            'customer_last_name_hash' => $hashed_data['last_name_hash'] ?? null,
            'customer_address_hash' => $hashed_data['address_hash'] ?? null,
            'conversion_value' => $conversion_value,
            'conversion_currency' => $conversion_currency,
            'conversion_action_id' => $conversion_action_id,
            'upload_status' => 'pending',
            'created_at' => function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s')
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

        $candidate = null;

        if (isset($settings['conversion_actions'][$action_type]['action_id'])) {
            $value = $settings['conversion_actions'][$action_type]['action_id'];
            if (is_scalar($value)) {
                $candidate = $this->sanitize_conversion_action_id((string) $value);
            }
        }

        if (($candidate === null || $candidate === '')
            && isset($settings['conversion_action_id'])
            && is_scalar($settings['conversion_action_id'])) {
            $candidate = $this->sanitize_conversion_action_id((string) $settings['conversion_action_id']);
        }

        if ($candidate === null || $candidate === '') {
            return null;
        }

        return $candidate;
    }

    /**
     * Sanitize conversion action identifiers sourced from settings.
     */
    private function sanitize_conversion_action_id(string $value): string {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (function_exists('sanitize_text_field')) {
            $value = sanitize_text_field($value);
        } else {
            $value = preg_replace('/[^A-Za-z0-9_\-]/', '', $value);
        }

        return trim((string) $value);
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
        
        update_option('hic_enhanced_conversions_queue', $queue, false);
        
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
        update_option('hic_enhanced_conversions_queue', $queue, false);
        
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
                if (!($upload_result['success'] ?? false)) {
                    $failed_count += count($group_conversions);
                    $error_message = isset($upload_result['error']) && is_string($upload_result['error'])
                        ? $upload_result['error']
                        : 'Failed to upload conversions to Google Ads.';
                    $this->mark_conversions_as_failed($group_conversions, $error_message);
                    continue;
                }

                $response_body = $upload_result['response'] ?? [];
                $uploaded_ids = isset($upload_result['uploaded_ids']) && is_array($upload_result['uploaded_ids'])
                    ? array_values(array_unique(array_map('intval', $upload_result['uploaded_ids'])))
                    : [];
                $failed_errors = isset($upload_result['failed_errors']) && is_array($upload_result['failed_errors'])
                    ? $upload_result['failed_errors']
                    : [];
                $pending_ids_from_response = isset($upload_result['pending_ids']) && is_array($upload_result['pending_ids'])
                    ? array_values(array_unique(array_map('intval', $upload_result['pending_ids'])))
                    : [];
                $general_errors = isset($upload_result['general_errors']) && is_array($upload_result['general_errors'])
                    ? $upload_result['general_errors']
                    : [];

                $conversion_map = [];
                foreach ($group_conversions as $conversion) {
                    if (isset($conversion['id'])) {
                        $conversion_map[(int) $conversion['id']] = $conversion;
                    }
                }

                $uploaded_conversions = [];
                foreach ($uploaded_ids as $uploaded_id) {
                    if (isset($conversion_map[$uploaded_id])) {
                        $uploaded_conversions[] = $conversion_map[$uploaded_id];
                    }
                }

                if (!empty($uploaded_conversions)) {
                    $success_count += count($uploaded_conversions);
                    $this->mark_conversions_as_uploaded($uploaded_conversions, $response_body);
                }

                foreach ($failed_errors as $failed_id => $message) {
                    $failed_id = (int) $failed_id;
                    if (!isset($conversion_map[$failed_id])) {
                        continue;
                    }

                    $failed_message = is_string($message) && $message !== ''
                        ? $message
                        : 'Conversion upload failed due to an unknown error.';

                    $failed_count++;
                    $this->mark_conversions_as_failed([$conversion_map[$failed_id]], $failed_message);
                    unset($conversion_map[$failed_id]);
                }

                $processed_ids = $uploaded_ids;
                foreach (array_keys($failed_errors) as $failed_id) {
                    $processed_ids[] = (int) $failed_id;
                }
                $processed_ids = array_values(array_unique(array_map('intval', $processed_ids)));

                $pending_ids = $pending_ids_from_response;

                foreach ($group_conversions as $conversion) {
                    if (!isset($conversion['id'])) {
                        continue;
                    }

                    $conversion_id = (int) $conversion['id'];
                    if (in_array($conversion_id, $processed_ids, true)) {
                        continue;
                    }

                    if (!in_array($conversion_id, $pending_ids, true)) {
                        $pending_ids[] = $conversion_id;
                    }
                }

                if (!empty($pending_ids)) {
                    $pending_ids = array_values(array_unique($pending_ids));
                    foreach ($pending_ids as $conversion_id) {
                        $this->queue_for_batch_upload($conversion_id);
                    }
                }

                if (!empty($general_errors)) {
                    foreach ($general_errors as $message) {
                        if (is_string($message) && $message !== '') {
                            $this->log('Google Ads partial failure: ' . $message);
                        }
                    }
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

        try {
            $formatted_conversion_map = [];
            $formatted_conversions = $this->format_conversions_for_api($conversions, $settings, $formatted_conversion_map);
        } catch (\RuntimeException $exception) {
            return [
                'success' => false,
                'error' => $exception->getMessage()
            ];
        }

        $customer_id = $this->resolve_google_ads_customer_id($settings);

        if ($customer_id === '') {
            $message = 'Missing Google Ads customer ID while preparing conversions for API upload.';
            $this->log($message);
            return [
                'success' => false,
                'error' => $message
            ];
        }

        $access_token = $this->get_google_ads_access_token();

        if (!$access_token) {
            throw new \Exception('Failed to obtain Google Ads access token');
        }

        $url = self::GOOGLE_ADS_API_ENDPOINT . "/{$customer_id}/conversionUploads:uploadClickConversions";

        $request_data = [
            'conversions' => $formatted_conversions,
            'partialFailureEnabled' => true
        ];
        
        $headers = [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json',
            'developer-token' => $settings['developer_token'],
            'login-customer-id' => $customer_id,
        ];
        
        $response = $this->make_google_ads_api_request($url, $request_data, $headers);

        if (!$response['success']) {
            return [
                'success' => false,
                'error' => $response['error']
            ];
        }

        $decoded_body = [];
        if (isset($response['decoded_body']) && is_array($response['decoded_body'])) {
            $decoded_body = $response['decoded_body'];
        } elseif (isset($response['data']) && is_array($response['data'])) {
            $decoded_body = $response['data'];
        }

        $partial_failure_data = $this->extract_partial_failure_errors($decoded_body['partialFailureError'] ?? null);
        $indexed_errors = $partial_failure_data['indexed'];
        $general_errors = $partial_failure_data['general'];

        $failed_errors = [];
        foreach ($indexed_errors as $index => $messages) {
            if (!array_key_exists($index, $formatted_conversion_map)) {
                continue;
            }

            $conversion = $formatted_conversion_map[$index];
            if (!isset($conversion['id'])) {
                continue;
            }

            $message_list = array_values(array_filter(array_map('strval', (array) $messages)));
            if (empty($message_list)) {
                $message_list = ['Conversion upload failed due to an unknown error.'];
            }

            $failed_errors[(int) $conversion['id']] = implode(' | ', array_unique($message_list));
        }

        $results = [];
        if (isset($decoded_body['results']) && is_array($decoded_body['results'])) {
            $results = $decoded_body['results'];
        }

        $uploaded_ids = [];
        foreach ($results as $index => $result) {
            if (!array_key_exists($index, $formatted_conversion_map)) {
                continue;
            }

            if (array_key_exists($index, $indexed_errors)) {
                continue;
            }

            if ($result === null) {
                continue;
            }

            if (!isset($formatted_conversion_map[$index]['id'])) {
                continue;
            }

            $uploaded_ids[] = (int) $formatted_conversion_map[$index]['id'];
        }

        $uploaded_ids = array_values(array_unique($uploaded_ids));

        $included_ids = [];
        foreach ($formatted_conversion_map as $conversion) {
            if (isset($conversion['id'])) {
                $included_ids[] = (int) $conversion['id'];
            }
        }

        $failed_ids = array_map('intval', array_keys($failed_errors));
        $pending_ids = array_values(array_diff($included_ids, $uploaded_ids, $failed_ids));

        if (!empty($general_errors) && empty($uploaded_ids) && empty($failed_errors)) {
            $pending_ids = $included_ids;
        }

        return [
            'success' => true,
            'response' => $decoded_body,
            'uploaded_ids' => $uploaded_ids,
            'failed_errors' => $failed_errors,
            'pending_ids' => $pending_ids,
            'general_errors' => $general_errors,
        ];
    }
    
    /**
     * Normalize Google Ads customer ID from settings or legacy configuration.
     */
    private function resolve_google_ads_customer_id($settings) {
        $customer_id = '';

        if (is_array($settings) && isset($settings['customer_id']) && is_scalar($settings['customer_id'])) {
            $customer_id = trim(str_replace('-', '', (string) $settings['customer_id']));
        }

        if ($customer_id === '') {
            $legacy_customer_id = get_option('hic_google_ads_customer_id');
            if (is_scalar($legacy_customer_id) && $legacy_customer_id !== '') {
                $customer_id = trim(str_replace('-', '', (string) $legacy_customer_id));
            }
        }

        return $customer_id;
    }

    /**
     * Format conversions for Google Ads API
     */
    private function format_conversions_for_api($conversions, $settings = null, &$conversion_map = null) {
        $formatted_conversions = [];
        $included_conversions = [];

        if (!is_array($settings)) {
            $settings = get_option('hic_google_ads_enhanced_settings', []);
        }

        $customer_id = $this->resolve_google_ads_customer_id($settings);

        if ($customer_id === '') {
            $message = 'Missing Google Ads customer ID while formatting conversions for API upload.';
            $this->log($message);
            throw new \RuntimeException($message);
        }

        foreach ($conversions as $conversion) {
            if (!isset($conversion['conversion_action_id']) || !is_scalar($conversion['conversion_action_id'])) {
                $this->log(sprintf(
                    'Skipping conversion record without conversion action ID for GCLID %s.',
                    isset($conversion['gclid']) && is_scalar($conversion['gclid']) ? (string) $conversion['gclid'] : 'unknown'
                ));
                continue;
            }

            $conversion_action_id = $this->sanitize_conversion_action_id((string) $conversion['conversion_action_id']);

            if ($conversion_action_id === '') {
                $this->log(sprintf(
                    'Skipping conversion record with empty conversion action ID for GCLID %s.',
                    isset($conversion['gclid']) && is_scalar($conversion['gclid']) ? (string) $conversion['gclid'] : 'unknown'
                ));
                continue;
            }

            $conversion_action = sprintf('customers/%s/conversionActions/%s', $customer_id, $conversion_action_id);

            $currency_code = 'EUR';
            $currency_is_missing = true;

            if (isset($conversion['conversion_currency'])) {
                $normalized_currency = $this->normalize_currency_code($conversion['conversion_currency']);
                if ($normalized_currency !== '') {
                    $currency_code = $normalized_currency;
                    $currency_is_missing = false;
                }
            }

            if ($currency_is_missing) {
                $reference_parts = [];
                if (isset($conversion['id']) && is_scalar($conversion['id'])) {
                    $reference_parts[] = 'ID ' . (string) $conversion['id'];
                }
                if (isset($conversion['gclid']) && is_scalar($conversion['gclid'])) {
                    $reference_parts[] = 'GCLID ' . (string) $conversion['gclid'];
                }
                $reference_context = empty($reference_parts) ? '' : ' (' . implode(', ', $reference_parts) . ')';
                $this->log('Missing or invalid conversion currency' . $reference_context . ', defaulting to EUR.');
            }

            $api_conversion = [
                'gclid' => $conversion['gclid'],
                'conversionAction' => $conversion_action,
                'conversionDateTime' => $this->format_conversion_datetime($conversion['created_at']),
                'conversionValue' => floatval($conversion['conversion_value']),
                'currencyCode' => $currency_code
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
            $included_conversions[] = $conversion;
        }

        if (func_num_args() >= 3) {
            $conversion_map = $included_conversions;
        }

        return $formatted_conversions;
    }
    
    /**
     * Format conversion datetime for Google Ads API requests.
     *
     * Google Ads expects localized timestamps with a numeric timezone offset.
     * Callers should provide booking timestamps already normalized to the
     * property/WordPress timezone (e.g. values produced via current_time()).
     *
     * @param string|int|\DateTimeInterface|null $datetime Datetime to convert.
     */
    private function format_conversion_datetime($datetime) {
        $timezone = $this->get_conversion_timezone();

        try {
            if ($datetime instanceof \DateTimeInterface) {
                $date = new \DateTimeImmutable($datetime->format('Y-m-d H:i:s'), $timezone);
            } elseif (is_numeric($datetime)) {
                $date = (new \DateTimeImmutable('@' . (string) (int) $datetime))->setTimezone($timezone);
            } elseif (is_string($datetime) && trim($datetime) !== '') {
                $date = new \DateTimeImmutable($datetime, $timezone);
            } else {
                $date = new \DateTimeImmutable('now', $timezone);
            }
        } catch (\Exception $exception) {
            $this->log(sprintf(
                'Failed to parse conversion datetime "%s": %s',
                is_scalar($datetime) ? (string) $datetime : gettype($datetime),
                $exception->getMessage()
            ));
            $date = new \DateTimeImmutable('now', $timezone);
        }

        return $date->setTimezone($timezone)->format('Y-m-d H:i:sP');
    }

    /**
     * Determine which timezone should be used for conversion exports.
     */
    private function get_conversion_timezone(): \DateTimeZone {
        $timezone_string = '';

        if (defined('HIC_PROPERTY_TIMEZONE') && is_string(HIC_PROPERTY_TIMEZONE) && HIC_PROPERTY_TIMEZONE !== '') {
            $timezone_string = trim((string) HIC_PROPERTY_TIMEZONE);
        }

        if ($timezone_string === '') {
            if (function_exists('\\FpHic\\Helpers\\hic_get_option')) {
                $option = \FpHic\Helpers\hic_get_option('property_timezone', '');
            } elseif (function_exists('get_option')) {
                $option = get_option('hic_property_timezone', '');
            } else {
                $option = '';
            }

            if (is_string($option)) {
                $timezone_string = trim($option);
            }
        }

        if ($timezone_string !== '') {
            try {
                return new \DateTimeZone($timezone_string);
            } catch (\Exception $exception) {
                $this->log(sprintf(
                    'Invalid property timezone "%s": %s',
                    $timezone_string,
                    $exception->getMessage()
                ));
            }
        }

        return $this->get_wordpress_timezone();
    }

    /**
     * Retrieve the active WordPress timezone configuration.
     */
    private function get_wordpress_timezone(): \DateTimeZone {
        if (function_exists('wp_timezone')) {
            $wp_timezone = wp_timezone();
            if ($wp_timezone instanceof \DateTimeZone) {
                return $wp_timezone;
            }
        }

        $timezone_string = '';
        if (function_exists('get_option')) {
            $timezone_option = get_option('timezone_string', '');
            if (is_string($timezone_option)) {
                $timezone_string = trim($timezone_option);
            }
        }

        if ($timezone_string !== '') {
            try {
                return new \DateTimeZone($timezone_string);
            } catch (\Exception $exception) {
                $this->log(sprintf(
                    'Invalid WordPress timezone "%s": %s',
                    $timezone_string,
                    $exception->getMessage()
                ));
            }
        }

        $gmt_offset = 0.0;
        if (function_exists('get_option')) {
            $offset_option = get_option('gmt_offset', 0);
            if (is_numeric($offset_option)) {
                $gmt_offset = (float) $offset_option;
            }
        }

        if ($gmt_offset !== 0.0) {
            $hours = (int) $gmt_offset;
            $minutes = (int) round(abs($gmt_offset - $hours) * 60);
            $sign = $gmt_offset < 0 ? '-' : '+';
            $formatted_offset = sprintf('%s%02d:%02d', $sign, abs($hours), $minutes);

            try {
                return new \DateTimeZone($formatted_offset);
            } catch (\Exception $exception) {
                $this->log(sprintf(
                    'Invalid GMT offset "%s": %s',
                    (string) $gmt_offset,
                    $exception->getMessage()
                ));
            }
        }

        return new \DateTimeZone('UTC');
    }
    
    /**
     * Get Google Ads access token
     */
    private function get_google_ads_access_token() {
        $settings = get_option('hic_google_ads_enhanced_settings', []);

        if (!is_array($settings)) {
            $this->log('Google Ads settings are invalid; expected array with credentials.');
            return false;
        }

        $required_fields = ['client_id', 'client_secret', 'refresh_token'];
        $token_data = ['grant_type' => 'refresh_token'];
        $missing_fields = [];

        foreach ($required_fields as $field) {
            if (!array_key_exists($field, $settings) || !is_string($settings[$field])) {
                $missing_fields[] = $field;
                continue;
            }

            $value = trim($settings[$field]);

            if ($value === '') {
                $missing_fields[] = $field;
                continue;
            }

            $token_data[$field] = $value;
        }

        if (!empty($missing_fields)) {
            $this->log(sprintf(
                'Google Ads credentials missing or invalid: %s.',
                implode(', ', $missing_fields)
            ));
            return false;
        }

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
            'body' => wp_json_encode($data),
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

        $decoded_body = null;
        if (is_string($response_body) && $response_body !== '') {
            $decoded = json_decode($response_body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decoded_body = $decoded;
            }
        }

        if ($response_code >= 200 && $response_code < 300) {
            return [
                'success' => true,
                'data' => $decoded_body,
                'decoded_body' => $decoded_body,
                'raw_body' => $response_body
            ];
        }

        return [
            'success' => false,
            'error' => "HTTP {$response_code}: {$response_body}",
            'decoded_body' => $decoded_body,
            'raw_body' => $response_body
        ];
    }

    /**
     * Extract partial failure errors from a Google Ads API response.
     */
    private function extract_partial_failure_errors($partial_failure_error) {
        $indexed_errors = [];
        $general_errors = [];

        if (!is_array($partial_failure_error)) {
            return [
                'indexed' => $indexed_errors,
                'general' => $general_errors,
            ];
        }

        $general_message = '';
        if (isset($partial_failure_error['message']) && is_string($partial_failure_error['message'])) {
            $general_message = trim($partial_failure_error['message']);
        }

        if (isset($partial_failure_error['details']) && is_array($partial_failure_error['details'])) {
            foreach ($partial_failure_error['details'] as $detail) {
                if (!is_array($detail) || !isset($detail['errors']) || !is_array($detail['errors'])) {
                    continue;
                }

                foreach ($detail['errors'] as $error) {
                    if (!is_array($error)) {
                        continue;
                    }

                    $message = '';
                    if (isset($error['message']) && is_string($error['message'])) {
                        $message = trim($error['message']);
                    } elseif ($general_message !== '') {
                        $message = $general_message;
                    } else {
                        $message = 'Conversion upload failed due to an unknown error.';
                    }

                    $index = $this->extract_partial_failure_index($error['location'] ?? null);

                    if ($index !== null) {
                        if (!isset($indexed_errors[$index])) {
                            $indexed_errors[$index] = [];
                        }

                        $indexed_errors[$index][] = $message;
                    } else {
                        $general_errors[] = $message;
                    }
                }
            }
        }

        if (empty($indexed_errors) && empty($general_errors) && $general_message !== '') {
            $general_errors[] = $general_message;
        }

        return [
            'indexed' => $indexed_errors,
            'general' => array_values(array_unique($general_errors)),
        ];
    }

    /**
     * Extract the conversion index reference from a partial failure error location.
     */
    private function extract_partial_failure_index($location) {
        if (!is_array($location)) {
            return null;
        }

        if (isset($location['fieldPathElements']) && is_array($location['fieldPathElements'])) {
            foreach ($location['fieldPathElements'] as $element) {
                if (!is_array($element)) {
                    continue;
                }

                if (array_key_exists('index', $element)) {
                    return (int) $element['index'];
                }
            }
        }

        if (array_key_exists('index', $location) && is_numeric($location['index'])) {
            return (int) $location['index'];
        }

        return null;
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
            'hic_manage',
            'hic-enhanced-conversions',
            [$this, 'render_enhanced_conversions_page']
        );
    }
    
    /**
     * Enqueue enhanced conversions assets
     */
    public function enqueue_enhanced_conversions_assets($hook) {
        if (!$this->is_enhanced_conversions_hook($hook)) {
            return;
        }

        $base_url = plugin_dir_url(dirname(__DIR__) . '/FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php');

        wp_enqueue_style(
            'hic-admin-base',
            $base_url . 'assets/css/hic-admin.css',
            [],
            HIC_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'hic-enhanced-conversions',
            $base_url . 'assets/js/enhanced-conversions.js',
            ['jquery'],
            HIC_PLUGIN_VERSION,
            true
        );
        
        wp_localize_script('hic-enhanced-conversions', 'hicEnhancedConversions', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hic_enhanced_conversions_nonce')
        ]);
    }

    private function is_enhanced_conversions_hook($hook): bool
    {
        if (!is_string($hook)) {
            return false;
        }

        if (strpos($hook, '_page_hic-enhanced-conversions') !== false) {
            return true;
        }

        return strpos($hook, 'hic-enhanced-conversions') !== false;
    }
    
    /**
     * Render enhanced conversions admin page
     */
    public function render_enhanced_conversions_page() {
        $settings = get_option('hic_google_ads_enhanced_settings', []);
        $enabled = get_option('hic_google_ads_enhanced_enabled', false);
        $has_config = !empty($settings['customer_id']) && !empty($settings['conversion_action_id']);

        global $wpdb;

        $pending_conversions = null;
        $last_upload_at = null;

        if ($wpdb instanceof \wpdb) {
            $table_name = $wpdb->prefix . 'hic_enhanced_conversions';
            $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name;

            if ($table_exists) {
                $stats = $wpdb->get_row(
                    "SELECT
                        SUM(CASE WHEN upload_status = 'pending' THEN 1 ELSE 0 END) AS pending,
                        MAX(CASE WHEN upload_status = 'uploaded' THEN uploaded_at ELSE NULL END) AS last_uploaded
                    FROM {$table_name}",
                    ARRAY_A
                );

                if (is_array($stats)) {
                    $pending_conversions = isset($stats['pending']) ? (int) $stats['pending'] : null;
                    $last_upload_at = $stats['last_uploaded'] ?? null;
                }
            }
        }

        $default_phone_country = self::DEFAULT_PHONE_COUNTRY;
        if (is_array($settings) && array_key_exists('default_phone_country', $settings)) {
            $stored_country = $settings['default_phone_country'];
            if (is_scalar($stored_country)) {
                $default_phone_country = (string) $stored_country;
            } else {
                $default_phone_country = '';
            }
        }
        
        // Determine status
        if (!$enabled) {
            $status = 'disabled';
        } elseif (!$has_config) {
            $status = 'not_configured';
        } else {
            $status = 'configured';
        }

        $status_badge_class = 'hic-status-badge--warning';
        $status_badge_label = 'Disabilitato';
        $status_feedback_class = 'hic-feedback is-info';
        $status_feedback_message = 'Status: Disabilitato — Il sistema funziona normalmente senza Enhanced Conversions.';

        if ($status === 'not_configured') {
            $status_badge_class = 'hic-status-badge--warning';
            $status_badge_label = 'Da configurare';
            $status_feedback_class = 'hic-feedback is-warning';
            $status_feedback_message = 'Status: Abilitato ma non configurato — Inserire credenziali Google Ads per completare la configurazione.';
        } elseif ($status === 'configured') {
            $status_badge_class = 'hic-status-badge--success';
            $status_badge_label = 'Attivo';
            $status_feedback_class = 'hic-feedback is-success';
            $status_feedback_message = 'Status: Configurato e attivo — Enhanced Conversions funzionanti.';
        }

        $credentials_complete = !empty($settings['developer_token'])
            && !empty($settings['client_id'])
            && !empty($settings['client_secret'])
            && !empty($settings['refresh_token']);

        $hero_overview = [
            [
                'label' => __('Stato integrazione', 'hotel-in-cloud'),
                'value' => $status_badge_label,
                'description' => $status === 'configured'
                    ? __('Sincronizzazione pronta all\'invio automatico.', 'hotel-in-cloud')
                    : ($status === 'not_configured'
                        ? __('Completa la configurazione per iniziare a inviare conversioni migliorate.', 'hotel-in-cloud')
                        : __('Funzionalità opzionale, il monitoraggio standard resta attivo.', 'hotel-in-cloud')),
                'state' => $status === 'configured' ? 'is-active' : ($status === 'not_configured' ? 'is-warning' : 'is-inactive'),
            ],
            [
                'label' => __('Credenziali API', 'hotel-in-cloud'),
                'value' => $credentials_complete ? __('Complete', 'hotel-in-cloud') : __('Incomplete', 'hotel-in-cloud'),
                'description' => $credentials_complete
                    ? __('Tutte le chiavi richieste sono state salvate.', 'hotel-in-cloud')
                    : __('Inserisci Customer ID, Developer Token, Client ID/Secret e Refresh Token.', 'hotel-in-cloud'),
                'state' => $credentials_complete ? 'is-active' : 'is-warning',
            ],
            [
                'label' => __('Conversioni pendenti', 'hotel-in-cloud'),
                'value' => $pending_conversions === null
                    ? '—'
                    : number_format_i18n($pending_conversions),
                'description' => $pending_conversions === null
                    ? __('Le statistiche saranno disponibili dopo il primo tracciamento.', 'hotel-in-cloud')
                    : __('Eventi in coda verso Google Ads.', 'hotel-in-cloud'),
                'state' => $pending_conversions === null
                    ? 'is-inactive'
                    : ($pending_conversions > 0 ? 'is-warning' : 'is-active'),
            ],
        ];

        if ($last_upload_at) {
            $last_upload_time = strtotime($last_upload_at);
            $hero_overview[] = [
                'label' => __('Ultimo upload', 'hotel-in-cloud'),
                'value' => $last_upload_time ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_upload_time) : '—',
                'description' => __('Sincronizzazione più recente completata con successo.', 'hotel-in-cloud'),
                'state' => 'is-active',
            ];
        }

        ?>
        <div class="wrap hic-admin-page hic-enhanced-page">
            <div class="hic-page-hero">
                <div class="hic-page-header">
                    <div class="hic-page-header__content">
                        <h1 class="hic-page-header__title">⚡ Google Ads Enhanced Conversions</h1>
                        <p class="hic-page-header__subtitle"><?php esc_html_e('Ottimizza il tracciamento importando in Google Ads i dati raccolti da Hotel in Cloud, mantenendo coerenza con la nuova interfaccia del monitoraggio.', 'hotel-in-cloud'); ?></p>
                    </div>
                    <div class="hic-page-actions">
                        <a class="hic-button hic-button--ghost hic-button--inverted" href="<?php echo esc_url(admin_url('admin.php?page=hic-reports')); ?>">
                            <span class="dashicons dashicons-chart-bar"></span>
                            <?php esc_html_e('Vai ai report', 'hotel-in-cloud'); ?>
                        </a>
                        <a class="hic-button hic-button--ghost hic-button--inverted" href="<?php echo esc_url('https://support.francopasseri.it/hic-enhanced'); ?>" target="_blank" rel="noopener noreferrer">
                            <span class="dashicons dashicons-external"></span>
                            <?php esc_html_e('Guida rapida', 'hotel-in-cloud'); ?>
                        </a>
                    </div>
                </div>

                <div class="hic-page-meta">
                    <?php foreach ($hero_overview as $overview_item): ?>
                        <div class="hic-page-meta__item">
                            <span class="hic-page-meta__status <?php echo esc_attr($overview_item['state']); ?>"></span>
                            <div class="hic-page-meta__content">
                                <p class="hic-page-meta__label"><?php echo esc_html($overview_item['label']); ?></p>
                                <p class="hic-page-meta__value"><?php echo esc_html($overview_item['value']); ?></p>
                                <?php if (!empty($overview_item['description'])): ?>
                                    <p class="hic-page-meta__description"><?php echo esc_html($overview_item['description']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (!$enabled): ?>
                <div class="hic-feedback is-info">
                    <strong>Nota:</strong> Il sistema HIC funziona perfettamente anche senza Google Ads Enhanced Conversions.
                    Questa funzionalità è opzionale e migliora l'accuratezza del tracciamento solo se utilizzi Google Ads.
                </div>
            <?php endif; ?>

            <div class="hic-grid hic-grid--two hic-enhanced-conversions-dashboard">
                <!-- Configuration Status -->
                <div class="hic-card">
                    <div class="hic-card__header">
                        <div>
                            <h2 class="hic-card__title">Stato Configurazione</h2>
                            <p class="hic-card__subtitle">Controlla l'attivazione dell'integrazione e abilita la funzione quando sei pronto.</p>
                        </div>
                        <span class="hic-status-badge <?php echo esc_attr($status_badge_class); ?>"><?php echo esc_html($status_badge_label); ?></span>
                    </div>
                    <div class="hic-card__body">
                        <div class="<?php echo esc_attr($status_feedback_class); ?>"><?php echo esc_html($status_feedback_message); ?></div>

                        <form method="post" action="" class="hic-form">
                            <?php wp_nonce_field('hic_enhanced_conversions_toggle', 'hic_enhanced_nonce'); ?>

                            <div class="hic-field-grid">
                                <div class="hic-field-row">
                                    <div class="hic-field-label">
                                        <label for="hic-enhanced-enabled">Abilita Google Ads Enhanced Conversions</label>
                                    </div>
                                    <div class="hic-field-control">
                                        <label class="hic-toggle" for="hic-enhanced-enabled">
                                            <input type="checkbox" id="hic-enhanced-enabled" name="hic_enhanced_enabled" value="1" <?php checked($enabled); ?>>
                                            <span>Funzionalità opzionale consigliata per chi utilizza campagne Google Ads.</span>
                                        </label>
                                        <p class="description">Quando disattivata, il monitoraggio standard continua a funzionare senza interruzioni.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="hic-form-actions">
                                <button type="submit" class="button hic-button hic-button--primary" name="save_enhanced_settings" value="1">
                                    Salva Impostazioni
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- API Configuration -->
                <div class="hic-card">
                    <div class="hic-card__header">
                        <div>
                            <h2 class="hic-card__title">Google Ads API Configuration</h2>
                            <p class="hic-card__subtitle">Inserisci le credenziali API necessarie per sincronizzare i dati delle conversioni.</p>
                        </div>
                    </div>
                    <div class="hic-card__body">
                        <form method="post" action="options.php" class="hic-form" novalidate>
                            <?php settings_fields('hic_google_ads_enhanced_settings'); ?>
                            <div class="hic-field-grid">
                                <div class="hic-field-row">
                                    <label class="hic-field-label" for="hic-google-ads-customer-id">Customer ID</label>
                                    <div class="hic-field-control">
                                        <input type="text" id="hic-google-ads-customer-id" name="hic_google_ads_enhanced_settings[customer_id]"
                                               value="<?php echo esc_attr($settings['customer_id'] ?? ''); ?>"
                                               placeholder="123-456-7890">
                                        <p class="description">Your Google Ads customer ID (format: 123-456-7890)</p>
                                    </div>
                                </div>

                                <div class="hic-field-row">
                                    <label class="hic-field-label" for="hic-google-ads-developer-token">Developer Token</label>
                                    <div class="hic-field-control">
                                        <input type="text" id="hic-google-ads-developer-token" name="hic_google_ads_enhanced_settings[developer_token]"
                                               value="<?php echo esc_attr($settings['developer_token'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="hic-field-row">
                                    <label class="hic-field-label" for="hic-google-ads-client-id">Client ID</label>
                                    <div class="hic-field-control">
                                        <input type="text" id="hic-google-ads-client-id" name="hic_google_ads_enhanced_settings[client_id]"
                                               value="<?php echo esc_attr($settings['client_id'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="hic-field-row">
                                    <label class="hic-field-label" for="hic-google-ads-client-secret">Client Secret</label>
                                    <div class="hic-field-control">
                                        <input type="password" id="hic-google-ads-client-secret" name="hic_google_ads_enhanced_settings[client_secret]"
                                               value="<?php echo esc_attr($settings['client_secret'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="hic-field-row">
                                    <label class="hic-field-label" for="hic-google-ads-refresh-token">Refresh Token</label>
                                    <div class="hic-field-control">
                                        <input type="text" id="hic-google-ads-refresh-token" name="hic_google_ads_enhanced_settings[refresh_token]"
                                               value="<?php echo esc_attr($settings['refresh_token'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="hic-field-row">
                                    <label class="hic-field-label" for="hic-google-ads-default-country">Prefisso telefonico di fallback</label>
                                    <div class="hic-field-control">
                                        <input type="text" id="hic-google-ads-default-country" name="hic_google_ads_enhanced_settings[default_phone_country]"
                                               value="<?php echo esc_attr($default_phone_country); ?>"
                                               placeholder="IT o +39">
                                        <p class="description">
                                            Utilizzato quando il prefisso non è deducibile da SID o lingua. Inserire un codice ISO a due lettere (es. IT) oppure un prefisso numerico (es. +39).
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div class="hic-form-actions">
                                <button type="submit" class="button hic-button hic-button--primary" name="submit" value="save">
                                    Save Configuration
                                </button>
                            </div>
                        </form>

                        <div class="hic-form-actions">
                            <button type="button" class="button hic-button hic-button--secondary" id="test-google-ads-connection">
                                Test Connection
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Conversion Statistics -->
                <div class="hic-card">
                    <div class="hic-card__header">
                        <div>
                            <h2 class="hic-card__title">Enhanced Conversion Statistics</h2>
                            <p class="hic-card__subtitle">Monitora gli invii recenti e verifica che le conversioni arrivino correttamente.</p>
                        </div>
                    </div>
                    <div class="hic-card__body">
                        <div id="enhanced-conversion-stats">Loading statistics...</div>
                    </div>
                </div>

                <!-- Manual Upload -->
                <div class="hic-card">
                    <div class="hic-card__header">
                        <div>
                            <h2 class="hic-card__title">Manual Upload</h2>
                            <p class="hic-card__subtitle">Invia manualmente eventuali conversioni in coda verso Google Ads.</p>
                        </div>
                    </div>
                    <div class="hic-card__body">
                        <p>Upload pending enhanced conversions to Google Ads:</p>
                        <div class="hic-form-actions">
                            <button type="button" class="button hic-button hic-button--primary" id="upload-enhanced-conversions">
                                Upload Pending Conversions
                            </button>
                        </div>
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
        if (!current_user_can('hic_manage')) {
            wp_send_json_error('Insufficient permissions');
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
        if (!current_user_can('hic_manage')) {
            wp_send_json_error('Insufficient permissions');
        }

        if (!check_ajax_referer('hic_enhanced_conversions_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
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
        if (!current_user_can('hic_manage')) {
            wp_send_json_error('Insufficient permissions');
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
     * Register settings for Enhanced Conversions
     */
    public function register_settings() {
        register_setting(
            'hic_google_ads_enhanced_settings',
            'hic_google_ads_enhanced_settings',
            [
                'sanitize_callback' => [$this, 'sanitize_settings'],
            ]
        );
    }

    /**
     * Sanitize settings before saving to the database
     *
     * @param array $settings
     * @return array
     */
    public function sanitize_settings($settings) {
        if (!is_array($settings)) {
            return [];
        }

        $sanitized = [];

        foreach ($settings as $key => $value) {
            if ($key === 'default_phone_country') {
                $sanitized[$key] = $this->sanitize_default_phone_country($value);
                continue;
            }

            $sanitized[$key] = $this->sanitize_setting_value($value);
        }

        return $sanitized;
    }

    /**
     * Sanitize default phone country option.
     */
    private function sanitize_default_phone_country($value): string {
        $candidate = $this->normalize_country_value($value);
        if ($candidate === null) {
            return '';
        }

        return $candidate['value'];
    }

    /**
     * Recursively sanitize settings values while preserving arrays.
     *
     * @param mixed $value
     * @return mixed
     */
    private function sanitize_setting_value($value)
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $k => $v) {
                $sanitized[$k] = $this->sanitize_setting_value($v);
            }
            return $sanitized;
        }

        if (is_scalar($value)) {
            if (function_exists('sanitize_text_field')) {
                return sanitize_text_field((string) $value);
            }

            return trim((string) $value);
        }

        return $value;
    }

    /**
     * Handle Enhanced Conversions form submission
     */
    public function handle_enhanced_conversions_form() {
        if (!isset($_POST['save_enhanced_settings']) || !current_user_can('hic_manage')) {
            return;
        }
        
        if (!wp_verify_nonce($_POST['hic_enhanced_nonce'] ?? '', 'hic_enhanced_conversions_toggle')) {
            wp_die('Security check failed');
        }
        
        $enabled = !empty($_POST['hic_enhanced_enabled']);
        update_option('hic_google_ads_enhanced_enabled', $enabled);
        
        $message = $enabled 
            ? 'Google Ads Enhanced Conversions abilitato.' 
            : 'Google Ads Enhanced Conversions disabilitato. Il sistema continua a funzionare normalmente.';
            
        add_action('admin_notices', function() use ($message) {
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        });
    }
    
    /**
     * Log messages with enhanced conversions prefix
     */
    private function log($message, $level = null, array $context = []) {
        if (!function_exists('\\FpHic\\Helpers\\hic_log')) {
            return;
        }

        $log_level = $level;
        if ($log_level === null) {
            $log_level = defined('HIC_LOG_LEVEL_INFO') ? HIC_LOG_LEVEL_INFO : 'info';
        }

        \FpHic\Helpers\hic_log("[Google Ads Enhanced] {$message}", $log_level, $context);
    }
}

// Note: Class instantiation moved to main plugin file for proper admin menu ordering