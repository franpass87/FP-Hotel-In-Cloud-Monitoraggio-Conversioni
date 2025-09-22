<?php declare(strict_types=1);
/**
 * Enhanced Input Validation for HIC Plugin
 * 
 * Provides comprehensive input validation and sanitization
 * with security-focused validation rules.
 */

namespace FpHic;

if (!defined('ABSPATH')) exit;

class HIC_Input_Validator {

    /**
     * Cached ISO 4217 currency codes list.
     *
     * @var string[]|null
     */
    private static $iso4217Currencies = null;
    
    /**
     * Validate email with enhanced security checks
     */
    public static function validate_email($email) {
        $email = sanitize_email($email);
        
        if (!$email || !is_email($email)) {
            return new \WP_Error('invalid_email', 'Email non valido');
        }
        
        // Check email length (RFC 5321)
        if (strlen($email) > HIC_EMAIL_MAX_LENGTH) {
            return new \WP_Error('email_too_long', 'Email troppo lungo');
        }
        
        // Check for suspicious patterns
        if (self::is_suspicious_email($email)) {
            return new \WP_Error('suspicious_email', 'Email sospetto');
        }
        
        return $email;
    }
    
    /**
     * Validate reservation data with comprehensive checks
     */
    public static function validate_reservation_data($data) {
        if (!is_array($data)) {
            return new \WP_Error('invalid_data_type', 'Dati prenotazione devono essere un array');
        }
        
        $errors = [];
        
        // Email validation
        if (array_key_exists('email', $data)) {
            $email_value = $data['email'];

            if (is_string($email_value)) {
                $email_value = trim($email_value);
            }

            if ($email_value === '' || $email_value === null) {
                unset($data['email']);
            } else {
                $email_validation = self::validate_email($email_value);
                if (is_wp_error($email_validation)) {
                    $errors[] = $email_validation->get_error_message();
                } else {
                    $data['email'] = $email_validation;
                }
            }
        }

        // Amount validation
        if (isset($data['amount'])) {
            $amount_validation = self::validate_amount($data['amount']);
            if (is_wp_error($amount_validation)) {
                $errors[] = $amount_validation->get_error_message();
            } else {
                $data['amount'] = $amount_validation;
            }
        }
        
        // Currency validation
        if (isset($data['currency'])) {
            $currency_validation = self::validate_currency($data['currency']);
            if (is_wp_error($currency_validation)) {
                $errors[] = $currency_validation->get_error_message();
            } else {
                $data['currency'] = $currency_validation;
            }
        }
        
        // Date validation
        if (isset($data['checkin'])) {
            $date_validation = self::validate_date($data['checkin']);
            if (is_wp_error($date_validation)) {
                $errors[] = "Data checkin non valida: " . $date_validation->get_error_message();
            }
        }
        
        if (isset($data['checkout'])) {
            $date_validation = self::validate_date($data['checkout']);
            if (is_wp_error($date_validation)) {
                $errors[] = "Data checkout non valida: " . $date_validation->get_error_message();
            }
        }
        
        // String field sanitization
        $string_fields = ['guest_name', 'phone', 'notes'];
        foreach ($string_fields as $field) {
            if (isset($data[$field])) {
                $data[$field] = self::sanitize_string_field($data[$field]);
            }
        }
        
        if (!empty($errors)) {
            return new \WP_Error('validation_failed', implode('; ', $errors));
        }
        
        return $data;
    }
    
    /**
     * Validate monetary amount
     */
    public static function validate_amount($amount) {
        if (is_string($amount)) {
            // Remove common currency symbols and whitespace
            $amount = preg_replace('/[€$£¥,\s]/', '', $amount);
        }
        
        if (!is_numeric($amount)) {
            return new \WP_Error('invalid_amount', 'Importo non valido');
        }
        
        $amount = floatval($amount);
        
        if ($amount < 0) {
            return new \WP_Error('negative_amount', 'Importo non può essere negativo');
        }
        
        if ($amount > 999999.99) {
            return new \WP_Error('amount_too_large', 'Importo troppo elevato');
        }
        
        return round($amount, 2);
    }
    
    /**
     * Validate currency code
     */
    public static function validate_currency($currency) {
        if (!is_scalar($currency)) {
            return new \WP_Error('invalid_currency', 'Codice valuta non valido');
        }

        $currency = strtoupper(sanitize_text_field((string) $currency));

        if ($currency === '' || !preg_match('/^[A-Z]{3}$/', $currency)) {
            return new \WP_Error('invalid_currency', 'Codice valuta non valido');
        }

        $valid_currencies = self::get_iso_4217_currency_codes();

        if (!in_array($currency, $valid_currencies, true)) {
            return new \WP_Error('invalid_currency', 'Codice valuta non valido');
        }

        return $currency;
    }

    /**
     * Retrieve the ISO 4217 currency codes list.
     *
     * @return string[]
     */
    private static function get_iso_4217_currency_codes() {
        if (is_array(self::$iso4217Currencies)) {
            return self::$iso4217Currencies;
        }

        if (function_exists('get_woocommerce_currencies')) {
            $currencies = array_keys((array) get_woocommerce_currencies());
            self::$iso4217Currencies = array_map('strtoupper', $currencies);

            return self::$iso4217Currencies;
        }

        self::$iso4217Currencies = [
            'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN',
            'BAM', 'BBD', 'BDT', 'BGN', 'BHD', 'BIF', 'BMD', 'BND', 'BOB', 'BOV',
            'BRL', 'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHE', 'CHF',
            'CHW', 'CLF', 'CLP', 'CNY', 'COP', 'COU', 'CRC', 'CUC', 'CUP', 'CVE',
            'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ERN', 'ETB', 'EUR', 'FJD',
            'FKP', 'GBP', 'GEL', 'GHS', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HKD',
            'HNL', 'HRK', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'IQD', 'IRR', 'ISK',
            'JMD', 'JOD', 'JPY', 'KES', 'KGS', 'KHR', 'KMF', 'KPW', 'KRW', 'KWD',
            'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'LYD', 'MAD', 'MDL',
            'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU', 'MUR', 'MVR', 'MWK', 'MXN',
            'MXV', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK', 'NPR', 'NZD', 'OMR',
            'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD',
            'RUB', 'RWF', 'SAR', 'SBD', 'SCR', 'SDG', 'SEK', 'SGD', 'SHP', 'SLE',
            'SLL', 'SOS', 'SRD', 'SSP', 'STN', 'SVC', 'SYP', 'SZL', 'THB', 'TJS', 'TMT',
            'TND', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD', 'USN',
            'UYI', 'UYU', 'UYW', 'UZS', 'VED', 'VES', 'VND', 'VUV', 'WST', 'XAF',
            'XAG', 'XAU', 'XBA', 'XBB', 'XBC', 'XBD', 'XCD', 'XDR', 'XOF', 'XPD',
            'XPF', 'XPT', 'XSU', 'XTS', 'XUA', 'XXX', 'YER', 'ZAR', 'ZMW', 'ZWL',
        ];

        return self::$iso4217Currencies;
    }
    
    /**
     * Validate date string
     */
    public static function validate_date($date_string) {
        $date_string = sanitize_text_field($date_string);
        
        // Try multiple date formats
        $formats = ['Y-m-d', 'Y-m-d H:i:s', 'd/m/Y', 'm/d/Y'];
        
        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $date_string);
            if ($date && $date->format($format) === $date_string) {
                // Validate date range (not too far in the past or future)
                $now = new \DateTime();
                $min_date = (clone $now)->sub(new \DateInterval('P5Y')); // 5 years ago
                $max_date = (clone $now)->add(new \DateInterval('P5Y')); // 5 years ahead
                
                if ($date < $min_date || $date > $max_date) {
                    return new \WP_Error('date_out_of_range', 'Data fuori intervallo valido');
                }
                
                return $date->format('Y-m-d');
            }
        }
        
        return new \WP_Error('invalid_date_format', 'Formato data non riconosciuto');
    }
    
    /**
     * Sanitize string field with XSS protection
     */
    public static function sanitize_string_field($value) {
        $value = sanitize_text_field($value);
        
        // Additional XSS protection
        $value = wp_kses($value, []);
        
        // Limit length
        if (strlen($value) > 255) {
            $value = substr($value, 0, 255);
        }
        
        return $value;
    }
    
    /**
     * Validate SID (Session ID) parameter
     */
    public static function validate_sid($sid) {
        $sid = sanitize_text_field($sid);
        
        if (strlen($sid) < HIC_SID_MIN_LENGTH || strlen($sid) > HIC_SID_MAX_LENGTH) {
            return new \WP_Error('invalid_sid_length', 'Lunghezza SID non valida');
        }
        
        // Check for valid characters (alphanumeric and some symbols)
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $sid)) {
            return new \WP_Error('invalid_sid_format', 'Formato SID non valido');
        }
        
        return $sid;
    }
    
    /**
     * Check if email is suspicious
     */
    private static function is_suspicious_email($email) {
        // Check for suspicious patterns
        $suspicious_patterns = [
            '/script/i',
            '/javascript/i',
            '/eval\(/i',
            '/<\s*script/i',
            '/on\w+\s*=/i' // Event handlers
        ];
        
        foreach ($suspicious_patterns as $pattern) {
            if (preg_match($pattern, $email)) {
                return true;
            }
        }
        
        // Check for multiple @ symbols
        if (substr_count($email, '@') > 1) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Validate webhook payload structure
     */
    public static function validate_webhook_payload($payload) {
        if (!is_array($payload)) {
            return new \WP_Error('invalid_payload_type', 'Payload deve essere un array');
        }
        
        // Check payload size
        $json_size = strlen(json_encode($payload));
        if ($json_size > HIC_WEBHOOK_MAX_PAYLOAD_SIZE) {
            return new \WP_Error('payload_too_large', 'Payload troppo grande');
        }
        
        // Validate structure
        $validated = self::validate_reservation_data($payload);
        if (is_wp_error($validated)) {
            return $validated;
        }
        
        return $validated;
    }
    
    /**
     * Validate API parameters for polling
     */
    public static function validate_polling_params($params) {
        $validated = [];
        
        // Property ID validation
        if (isset($params['property_id'])) {
            $prop_id = intval($params['property_id']);
            if ($prop_id <= 0) {
                return new \WP_Error('invalid_property_id', 'ID proprietà non valido');
            }
            $validated['property_id'] = $prop_id;
        }
        
        // Date validation
        if (isset($params['from_date'])) {
            $date_validation = self::validate_date($params['from_date']);
            if (is_wp_error($date_validation)) {
                return $date_validation;
            }
            $validated['from_date'] = $date_validation;
        }
        
        if (isset($params['to_date'])) {
            $date_validation = self::validate_date($params['to_date']);
            if (is_wp_error($date_validation)) {
                return $date_validation;
            }
            $validated['to_date'] = $date_validation;
        }
        
        // Limit validation
        if (isset($params['limit'])) {
            $limit = intval($params['limit']);
            if ($limit < 1 || $limit > 1000) {
                return new \WP_Error('invalid_limit', 'Limite deve essere tra 1 e 1000');
            }
            $validated['limit'] = $limit;
        }
        
        return $validated;
    }
}
