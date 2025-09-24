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
            return new \WP_Error('invalid_email', 'Email non valida', [
                'status' => 400,
                'field'  => 'email',
            ]);
        }

        // Check email length (RFC 5321)
        if (strlen($email) > HIC_EMAIL_MAX_LENGTH) {
            return new \WP_Error('email_too_long', 'Email troppo lunga', [
                'status' => 400,
                'field'  => 'email',
            ]);
        }

        // Check for suspicious patterns
        if (self::is_suspicious_email($email)) {
            return new \WP_Error('suspicious_email', 'Email sospetta', [
                'status' => 400,
                'field'  => 'email',
            ]);
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
                    $errors[] = $email_validation;
                } else {
                    $data['email'] = $email_validation;
                }
            }
        }

        // Amount validation
        if (isset($data['amount'])) {
            $amount_validation = self::validate_amount($data['amount']);
            if (is_wp_error($amount_validation)) {
                $errors[] = $amount_validation;
            } else {
                $data['amount'] = $amount_validation;
            }
        }

        // Currency validation
        if (isset($data['currency'])) {
            $currency_validation = self::validate_currency($data['currency']);
            if (is_wp_error($currency_validation)) {
                $errors[] = $currency_validation;
            } else {
                $data['currency'] = $currency_validation;
            }
        }

        // Date validation
        if (isset($data['checkin'])) {
            $date_validation = self::validate_date($data['checkin'], 'checkin');
            if (is_wp_error($date_validation)) {
                $errors[] = $date_validation;
            }
        }

        if (isset($data['checkout'])) {
            $date_validation = self::validate_date($data['checkout'], 'checkout');
            if (is_wp_error($date_validation)) {
                $errors[] = $date_validation;
            }
        }
        
        // String field sanitization
        $string_fields = ['guest_name', 'phone', 'notes'];
        foreach ($string_fields as $field) {
            if (isset($data[$field])) {
                $data[$field] = self::sanitize_string_field($data[$field]);
            }
        }

        // Normalize SID aliases after required field validation
        $normalize_sid_candidate = static function ($value) {
            if (!is_scalar($value)) {
                return null;
            }

            $candidate = trim((string) $value);

            return $candidate === '' ? null : $candidate;
        };

        $sid_value = null;
        if (array_key_exists('sid', $data)) {
            $sid_value = $normalize_sid_candidate($data['sid']);
            if ($sid_value === null) {
                unset($data['sid']);
            } else {
                $data['sid'] = $sid_value;
            }
        }

        if ($sid_value === null) {
            $sid_aliases = ['session_id', 'sessionId', 'sessionid', 'hic_sid', 'hicSid'];

            foreach ($sid_aliases as $alias) {
                if (!array_key_exists($alias, $data)) {
                    continue;
                }

                $candidate = $normalize_sid_candidate($data[$alias]);
                if ($candidate === null) {
                    continue;
                }

                $sid_value = $candidate;
                $data['sid'] = $candidate;
                break;
            }
        }

        if ($sid_value !== null) {
            $sid_validation = self::validate_sid($sid_value);
            if (is_wp_error($sid_validation)) {
                $errors[] = self::prefix_field_error($sid_validation, 'sid');
            } else {
                $data['sid'] = $sid_validation;
            }
        }

        if (!empty($errors)) {
            if (count($errors) === 1 && $errors[0] instanceof \WP_Error) {
                return $errors[0];
            }

            $messages = [];
            $error_data = ['errors' => [], 'status' => 400];

            foreach ($errors as $error) {
                if ($error instanceof \WP_Error) {
                    $messages[] = $error->get_error_message();
                    $error_data['errors'][] = [
                        'code'    => $error->get_error_code(),
                        'message' => $error->get_error_message(),
                        'data'    => $error->get_error_data(),
                    ];
                } elseif (is_string($error) && $error !== '') {
                    $messages[] = $error;
                }
            }

            $joined_messages = implode('; ', array_filter($messages, 'strlen'));

            if ($joined_messages === '') {
                $joined_messages = 'Dati non validi';
            }

            return new \WP_Error('validation_failed', $joined_messages, $error_data);
        }

        return $data;
    }

    /**
     * Ensure a validation error contains field metadata.
     */
    private static function prefix_field_error(\WP_Error $error, string $field): \WP_Error
    {
        $field = strtolower((string) $field);
        $field = str_replace('-', '_', $field);
        $field = preg_replace('/[^a-z0-9_]/', '', $field ?? '') ?? '';

        if ($field === '') {
            $field = 'field';
        }

        $data  = $error->get_error_data();

        if (!is_array($data)) {
            $data = [];
        }

        if (!isset($data['field'])) {
            $data['field'] = $field;
        }

        if (!isset($data['status'])) {
            $data['status'] = 400;
        }

        return new \WP_Error($error->get_error_code(), $error->get_error_message(), $data);
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
            return new \WP_Error('invalid_amount', 'Importo non valido', [
                'status' => 400,
                'field'  => 'amount',
            ]);
        }

        $amount = floatval($amount);

        if ($amount < 0) {
            return new \WP_Error('negative_amount', 'Importo non può essere negativo', [
                'status' => 400,
                'field'  => 'amount',
            ]);
        }

        if ($amount > 999999.99) {
            return new \WP_Error('amount_too_large', 'Importo troppo elevato', [
                'status' => 400,
                'field'  => 'amount',
            ]);
        }

        return round($amount, 2);
    }
    
    /**
     * Validate currency code
     */
    public static function validate_currency($currency) {
        if (!is_scalar($currency)) {
            return new \WP_Error('invalid_currency', 'Codice valuta non valido', [
                'status' => 400,
                'field'  => 'currency',
            ]);
        }

        $currency = strtoupper(sanitize_text_field((string) $currency));

        if ($currency === '' || !preg_match('/^[A-Z]{3}$/', $currency)) {
            return new \WP_Error('invalid_currency', 'Codice valuta non valido', [
                'status' => 400,
                'field'  => 'currency',
            ]);
        }

        $valid_currencies = self::get_iso_4217_currency_codes();

        if (!in_array($currency, $valid_currencies, true)) {
            return new \WP_Error('invalid_currency', 'Codice valuta non valido', [
                'status' => 400,
                'field'  => 'currency',
            ]);
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

        self::$iso4217Currencies = Helpers\hic_get_iso4217_currency_codes();

        return self::$iso4217Currencies;
    }
    
    /**
     * Validate date string
     */
    public static function validate_date($date_string, string $field = 'date') {
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
                    return new \WP_Error('date_out_of_range', 'Data fuori intervallo valido', [
                        'status' => 400,
                        'field'  => $field,
                    ]);
                }

                return $date->format('Y-m-d');
            }
        }

        return new \WP_Error('invalid_date_format', 'Formato data non riconosciuto', [
            'status' => 400,
            'field'  => $field,
        ]);
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
            return new \WP_Error('invalid_sid_length', 'Lunghezza SID non valida', [
                'status' => 400,
                'field'  => 'sid',
            ]);
        }

        // Check for valid characters (alphanumeric and some symbols)
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $sid)) {
            return new \WP_Error('invalid_sid_format', 'Formato SID non valido', [
                'status' => 400,
                'field'  => 'sid',
            ]);
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
            $date_validation = self::validate_date($params['from_date'], 'from_date');
            if (is_wp_error($date_validation)) {
                return $date_validation;
            }
            $validated['from_date'] = $date_validation;
        }

        if (isset($params['to_date'])) {
            $date_validation = self::validate_date($params['to_date'], 'to_date');
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
