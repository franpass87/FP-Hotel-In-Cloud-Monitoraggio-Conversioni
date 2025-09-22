<?php declare(strict_types=1);
namespace FpHic;

use \WP_Error;
/**
 * API Polling Handler - Core API Functions Only
 * Note: Scheduling is handled by booking-poller.php via WP-Cron; this file focuses on API polling logic
 */

if (!defined('ABSPATH')) exit;

/**
 * Validate and sanitize timestamp for API requests
 * Ensures timestamp is within acceptable range for HIC API
 * 
 * @param int $timestamp Unix timestamp to validate
 * @param string $context Context for logging (e.g., 'continuous_polling', 'deep_check')
 * @return int Validated and potentially adjusted timestamp
 */
if (!function_exists(__NAMESPACE__ . '\\hic_validate_api_timestamp')) {
    function hic_validate_api_timestamp($timestamp, $context = 'api_request') {
        $current_time = time();
        $day_in_seconds = 86400; // 24 * 60 * 60
        $max_lookback_seconds = 6 * $day_in_seconds; // 6 days for safety margin from 7-day API limit
        $max_lookahead_seconds = 1 * $day_in_seconds; // 1 day in future for safety
        $earliest_allowed = $current_time - $max_lookback_seconds;
        $latest_allowed = $current_time + $max_lookahead_seconds;

        $original_timestamp = $timestamp;
        $adjusted = false;

        // Handle invalid/unset timestamps
        if (empty($timestamp) || !is_numeric($timestamp)) {
            $timestamp = $current_time - 7200; // Default to 2 hours ago
            $adjusted = true;
            hic_log("$context: Invalid timestamp, using default: " . wp_date('Y-m-d H:i:s', $timestamp));
        }
        // Handle timestamps that are too old
        elseif ($timestamp < $earliest_allowed) {
            $timestamp = $earliest_allowed;
            $adjusted = true;
            hic_log("$context: Timestamp too old (" . wp_date('Y-m-d H:i:s', $original_timestamp) . "), reset to: " . wp_date('Y-m-d H:i:s', $timestamp));
        }
        // Handle timestamps that are too far in the future
        elseif ($timestamp > $latest_allowed) {
            $timestamp = $current_time - 3600; // Set to 1 hour ago
            $adjusted = true;
            hic_log("$context: Timestamp too far in future (" . wp_date('Y-m-d H:i:s', $original_timestamp) . "), reset to: " . wp_date('Y-m-d H:i:s', $timestamp));
        }
        // Additional safety check for unreasonable timestamps
        elseif ($timestamp < 0 || $timestamp < ($current_time - (365 * $day_in_seconds))) {
            $timestamp = $earliest_allowed;
            $adjusted = true;
            hic_log("$context: Unreasonable timestamp (" . wp_date('Y-m-d H:i:s', $original_timestamp) . "), reset to safe value: " . wp_date('Y-m-d H:i:s', $timestamp));
        }

        return $timestamp;
    }
}

/**
 * Reset all polling-related timestamps to safe values
 * Used for recovery scenarios when polling gets stuck
 * 
 * @param string $reason Reason for the reset (for logging)
 */
function hic_reset_all_polling_timestamps($reason = 'Manual reset') {
    $current_time = time();
    $day_in_seconds = 86400; // 24 * 60 * 60
    $safe_timestamp = $current_time - (3 * $day_in_seconds); // 3 days ago for safety
    
    // Validate the safe timestamp
    $validated_safe = hic_validate_api_timestamp($safe_timestamp, "Reset: $reason");
    
    $options_to_reset = [
        'hic_last_continuous_check',
        'hic_last_continuous_poll', 
        'hic_last_update_check',
        'hic_last_deep_check',
        'hic_last_updates_since',
        'hic_last_api_poll'
    ];
    
    foreach ($options_to_reset as $option) {
        update_option($option, $validated_safe, false);
        if (function_exists('\\FpHic\\Helpers\\hic_clear_option_cache')) {
            \FpHic\Helpers\hic_clear_option_cache($option);
        }
    }
    
    // Reset error counters
    update_option('hic_consecutive_update_errors', 0, false);
    
    hic_log("$reason: Reset all polling timestamps to: " . wp_date('Y-m-d H:i:s', $validated_safe) . " ($validated_safe)");
}

/**
 * Emergency recovery function - forces a complete reset of the polling system
 * Can be called manually if the system appears to be stuck
 */
function hic_emergency_polling_recovery() {
    hic_log('Emergency Recovery: Starting emergency polling system recovery');
    
    // Clear circuit breaker
    delete_option('hic_circuit_breaker_until');
    
    // Reset all timestamps 
    hic_reset_all_polling_timestamps('Emergency recovery');
    
    // Clear any potential locks
    delete_option('hic_polling_lock');
    delete_option('hic_polling_lock_timestamp');
    
    // Reset error counters
    update_option('hic_consecutive_update_errors', 0, false);
    
    // Clear related caches if functions exist
    if (function_exists('\\FpHic\\Helpers\\hic_clear_option_cache')) {
        $cache_options = [
            'hic_circuit_breaker_until',
            'hic_polling_lock',
            'hic_polling_lock_timestamp',
            'hic_consecutive_update_errors'
        ];
        
        foreach ($cache_options as $option) {
            \FpHic\Helpers\hic_clear_option_cache($option);
        }
    }
    
    hic_log('Emergency Recovery: Polling system recovery completed - all blocks cleared');
    
    return true;
}

/**
 * Helper function for consistent API error handling
 */
function hic_handle_api_response($response, $context = 'API call') {
  if (is_wp_error($response)) {
    hic_log("$context failed: " . $response->get_error_message());
    return $response;
  }
  
  // Validate response object
  if (!is_array($response) && !is_object($response)) {
    hic_log("$context: Invalid response object");
    return new WP_Error('hic_invalid_response', 'Invalid response object');
  }
  
  $code = wp_remote_retrieve_response_code($response);
  if ($code !== 200) {
    $body = wp_remote_retrieve_body($response);
    hic_log("$context HTTP $code - Response body: " . substr($body, 0, 500));
    
    // Provide more specific error messages for common HTTP codes
    switch ($code) {
      case 400:
        // Check for specific timestamp error patterns (multiple languages and variations)
        $timestamp_error_patterns = [
          'timestamp can\'t be older than seven days',
          'the timestamp can\'t be older than seven days',
          'timestamp cannot be older than seven days',
          'the timestamp cannot be older than seven days',
          'timestamp troppo vecchio',
          'timestamp too old',
          'updated_after',
          'invalid timestamp',
          'timestamp non valido'
        ];
        
        $is_timestamp_error = false;
        $body_lower = strtolower($body);
        
        foreach ($timestamp_error_patterns as $pattern) {
          if (strpos($body_lower, strtolower($pattern)) !== false) {
            $is_timestamp_error = true;
            break;
          }
        }
        
        // For reservations_updates endpoint, be more aggressive about timestamp error detection
        // If we get a 400 error on this endpoint, it's very likely a timestamp issue
        $request_url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (!$is_timestamp_error && strpos($context, 'updates') !== false) {
          hic_log("$context: HTTP 400 on updates endpoint - treating as potential timestamp error. Body: " . substr($body, 0, 200));
          $is_timestamp_error = true;
        }
        
        if ($is_timestamp_error) {
          return new WP_Error('hic_timestamp_too_old', "HTTP 400 - Il timestamp è troppo vecchio (oltre 7 giorni). Il sistema resetterà automaticamente il timestamp per la prossima richiesta.");
        }
        
        return new WP_Error('hic_http', "HTTP 400 - Richiesta non valida. Verifica i parametri: date_type deve essere checkin, checkout o presence per /reservations. Usa /reservations_updates con updated_after per modifiche recenti.");
      case 401:
        return new WP_Error('hic_http', "HTTP 401 - Credenziali non valide. Verifica email e password API.");
      case 403:
        return new WP_Error('hic_http', "HTTP 403 - Accesso negato. L'account potrebbe non avere permessi per questa struttura.");
      case 404:
        return new WP_Error('hic_http', "HTTP 404 - Struttura non trovata. Verifica l'ID Struttura (propId).");
      case 429:
        return new WP_Error('hic_http', "HTTP 429 - Troppe richieste. Riprova tra qualche minuto.");
      case 500:
      case 502:
      case 503:
        return new WP_Error('hic_http', "HTTP $code - Errore del server Hotel in Cloud. Riprova più tardi.");
      default:
        return new WP_Error('hic_http', "HTTP $code - Errore API. Verifica la configurazione.");
    }
  }
  
  $body = wp_remote_retrieve_body($response);
  if (empty($body)) {
    hic_log("$context: Empty response body");
    return new WP_Error('hic_empty_response', 'Empty response body');
  }
  
  $data = json_decode($body, true);
  if (json_last_error() !== JSON_ERROR_NONE) {
    hic_log("$context JSON error: " . json_last_error_msg() . " - Body: " . substr($body, 0, 200));
    return new WP_Error('hic_json', 'Invalid JSON response: ' . json_last_error_msg());
  }
  
  return $data;
}

/* ============ Core API Functions ============ */
// Note: WP-Cron scheduling is managed by the booking-poller
// in booking-poller.php. This file now contains only core API functions.

/**
 * Chiama HIC: GET /reservations/{propId} o /reservations_updates/{propId}
 */
function hic_fetch_reservations($prop_id, $date_type, $from_date, $to_date, $limit = null){
    $base = rtrim(Helpers\hic_get_api_url(), '/'); // es: https://api.hotelincloud.com/api/partner
    $email = Helpers\hic_get_api_email();
    $pass  = Helpers\hic_get_api_password();
    if (!$base || !$email || !$pass || !$prop_id) {
        return new WP_Error('hic_missing_conf', 'URL/credenziali/propId mancanti');
    }
    
    // Validate date_type - only checkin, checkout, presence are valid for /reservations endpoint
    if (!in_array($date_type, array('checkin', 'checkout', 'presence'))) {
        return new WP_Error('hic_invalid_date_type', 'date_type deve essere checkin, checkout o presence. Per le modifiche recenti usa hic_fetch_reservations_updates()');
    }
    
    $endpoint = $base . '/reservations/' . rawurlencode($prop_id);
    $args = array('date_type'=>$date_type,'from_date'=>$from_date,'to_date'=>$to_date);
    if ($limit) $args['limit'] = (int)$limit;
    $url = add_query_arg($args, $endpoint);
    
    // Log API call details for debugging
    hic_log("API Call (Reservations endpoint): $url with params: " . json_encode($args));

    $request_args = array(
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode("$email:$pass"),
        ),
    );
    $res = Helpers\hic_http_request($url, $request_args);
    
    $data = hic_handle_api_response($res, 'HIC reservations fetch');
    if (is_wp_error($data)) {
        return $data;
    }

    // Handle new API response format with success/error structure
    $reservations = hic_extract_reservations_from_response($data);
    if (is_wp_error($reservations)) {
        return $reservations;
    }

    // Log di debug iniziale (ridotto)
    hic_log(array('hic_reservations_count' => is_array($reservations) ? count($reservations) : 0));

    // Processa singole prenotazioni con la nuova pipeline
    $processed_count = 0;
    $partial_processed = 0;
    $dispatch_failures = 0;
    if (is_array($reservations)) {
        $normalize_fetch_result = static function ($raw, $uid) {
            if (is_array($raw)) {
                return $raw;
            }

            $result = Helpers\hic_create_integration_result([
                'uid' => $uid,
            ]);
            $result['status'] = $raw ? 'success' : 'failed';

            return Helpers\hic_finalize_integration_result($result);
        };
        foreach ($reservations as $reservation) {
            try {
                if (hic_should_process_reservation($reservation)) {
                    $uid = Helpers\hic_booking_uid($reservation);

                    // Acquire processing lock to prevent concurrent processing
                    if (!empty($uid) && !Helpers\hic_acquire_reservation_lock($uid)) {
                        hic_log("Polling skipped: reservation $uid is being processed concurrently");
                        continue;
                    }

                    try {
                        $dispatch_result = $normalize_fetch_result(hic_process_single_reservation($reservation), $uid);
                        $status_value = isset($dispatch_result['status']) ? (string) $dispatch_result['status'] : 'failed';

                        if ($status_value === 'failed') {
                            $dispatch_failures++;
                            if (!empty($uid)) {
                                hic_log("Reservation $uid: dispatch failed during fetch, will retry");
                            } else {
                                hic_log('Reservation dispatch failed during fetch, will retry');
                            }
                        } else {
                            if (!empty($dispatch_result['should_mark_processed'])) {
                                hic_mark_reservation_processed($reservation);
                            }
                            $processed_count++;

                            if ($status_value === 'partial') {
                                $partial_processed++;
                                if (!empty($dispatch_result['failed_details'])) {
                                    Helpers\hic_queue_integration_retry($uid, $dispatch_result['failed_details'], array(
                                        'source' => 'polling',
                                        'type' => 'immediate_fetch',
                                    ));
                                }
                                $failed = isset($dispatch_result['failed_integrations']) && is_array($dispatch_result['failed_integrations'])
                                    ? $dispatch_result['failed_integrations']
                                    : array();
                                $failed_message = !empty($failed) ? ' - Failed integrations: ' . implode(', ', $failed) : '';
                                hic_log("Reservation $uid: processed during fetch with partial failures$failed_message", HIC_LOG_LEVEL_WARNING);
                            }
                        }
                    } finally {
                        // Always release the lock
                        if (!empty($uid)) {
                            Helpers\hic_release_reservation_lock($uid);
                        }
                    }
                }
            } catch (\Exception $e) {
                hic_log('Process reservation error: '.$e->getMessage()); 
            }
        }
        if (count($reservations) > 0) {
            $log_message = "Processed $processed_count out of " . count($reservations) . " reservations (duplicates/invalid skipped)";
            if ($partial_processed > 0) {
                $log_message .= " - Partial: $partial_processed";
            }
            if ($dispatch_failures > 0) {
                $log_message .= " - Dispatch failures: $dispatch_failures";
            }
            hic_log($log_message);
        }
    }
    return $reservations;
}

/**
 * Extract reservations from API response, handling both new and old formats
 */
function hic_extract_reservations_from_response($data) {
    // Handle new API response format with success/error structure
    if (is_array($data) && isset($data['success'])) {
        // Check for error response
        if ($data['success'] == 0 || $data['success'] === false) {
            $error_message = isset($data['error']) ? $data['error'] : 'Unknown API error';
            hic_log("API returned error: $error_message");
            return new WP_Error('hic_api_error', "API Error: $error_message");
        }
        
        // Success response - extract reservations
        if (isset($data['reservations']) && is_array($data['reservations'])) {
            hic_log("New API format detected with " . count($data['reservations']) . " reservations");
            return $data['reservations'];
        } else {
            // Success but no reservations array
            hic_log("API success but no reservations array found");
            return array(); // Return empty array for successful response with no data
        }
    }
    
    // Handle old format - direct array of reservations
    if (is_array($data)) {
        // Check if this looks like an array of reservations (each element should be an array with reservation fields)
        if (empty($data) || (isset($data[0]) && is_array($data[0]))) {
            hic_log("Old API format detected with " . count($data) . " reservations");
            return $data;
        }
    }
    
    // Invalid format
    hic_log("Invalid API response format - expected array with reservations");
    return new WP_Error('hic_invalid_format', 'Invalid API response format');
}

// Hook pubblico per esecuzione manuale
add_action('hic_fetch_reservations', function($prop_id, $date_type, $from_date, $to_date, $limit = null){
    return hic_fetch_reservations($prop_id, $date_type, $from_date, $to_date, $limit);
}, 10, 5);

/**
 * Validates if a reservation should be processed
 */
function hic_should_process_reservation($reservation) {
    // Only require essential booking data - dates are critical for booking logic
    $from = $reservation['from_date'] ?? $reservation['checkin'] ?? '';
    $to   = $reservation['to_date']   ?? $reservation['checkout'] ?? '';
    if (empty($from) || empty($to)) {
        $missing = array();
        if (empty($from)) {
            $missing[] = 'from_date/checkin';
        }
        if (empty($to)) {
            $missing[] = 'to_date/checkout';
        }
        hic_log('Reservation skipped: missing critical field(s) ' . implode(', ', $missing));
        return false;
    }

    // Check for any valid ID field (more flexible than requiring specific 'id' field)
    $uid = Helpers\hic_booking_uid($reservation);
    if (empty($uid)) {
        $tried_fields = Helpers\hic_candidate_reservation_id_fields(Helpers\hic_booking_uid_primary_fields());
        hic_log('Reservation skipped: no valid ID field found (tried: ' . implode(', ', $tried_fields) . ')');
        return false;
    }

    $aliases = is_array($reservation)
        ? Helpers\hic_collect_reservation_ids($reservation)
        : array();
    if (empty($aliases) && $uid !== '') {
        $sanitized_uid = Helpers\hic_normalize_reservation_id((string) $uid);
        if ($sanitized_uid !== '') {
            $aliases[] = $sanitized_uid;
        }
    }

    $processed_alias = is_array($reservation)
        ? Helpers\hic_find_processed_reservation_alias($reservation)
        : null;
    $log_uid = $processed_alias ?? $uid;
    if ($log_uid === '' && !empty($aliases)) {
        $log_uid = $aliases[0];
    }

    // Log warnings for missing optional data but don't block processing
    $optional_fields = ['accommodation_id', 'accommodation_name'];
    foreach ($optional_fields as $field) {
        if (empty($reservation[$field])) {
            hic_log("Reservation $log_uid: Warning - missing optional field '$field', using defaults");
        }
    }

    // Check valid flag
    $valid = isset($reservation['valid']) ? intval($reservation['valid']) : 1;
    if ($valid === 0 && !Helpers\hic_process_invalid()) {
        hic_log("Reservation $log_uid skipped: valid=0 and process_invalid=false");
        return false;
    }

    // Check deduplication
    if (!empty($aliases) && Helpers\hic_is_reservation_already_processed($aliases)) {
        // Check if status update is allowed
        if (Helpers\hic_allow_status_updates()) {
            $presence = $reservation['presence'] ?? '';
            if (in_array($presence, ['arrived', 'departed'])) {
                Helpers\hic_mark_reservation_processed_by_id($aliases);
                hic_log("Reservation $log_uid: status update allowed for presence=$presence");
                return true;
            }
        }
        Helpers\hic_mark_reservation_processed_by_id($aliases);
        hic_log("Reservation $log_uid already processed, skipping");
        return false;
    }

    return true;
}

/**
 * Transform reservation data to standardized format
 */
function hic_transform_reservation($reservation) {
    $extract_currency = static function ($source) use (&$extract_currency) {
        if (is_string($source) || is_numeric($source)) {
            $normalized = strtoupper(\sanitize_text_field((string) $source));
            if (preg_match('/([A-Z]{3})/', $normalized, $matches)) {
                return $matches[1];
            }

            return '';
        }

        if (is_array($source)) {
            foreach ($source as $value) {
                $candidate = $extract_currency($value);
                if ($candidate !== '') {
                    return $candidate;
                }
            }

            return '';
        }

        if (is_object($source)) {
            return $extract_currency(get_object_vars($source));
        }

        return '';
    };

    $currency = '';
    if (is_array($reservation)) {
        $currency_fields = [
            'currency',
            'booking_currency',
            'bookingCurrency',
            'reservation_currency',
            'currency_code',
            'currencyCode',
            'payment_currency',
            'paymentCurrency'
        ];

        foreach ($currency_fields as $field) {
            if (array_key_exists($field, $reservation)) {
                $candidate = $extract_currency($reservation[$field]);
                if ($candidate !== '') {
                    $currency = $candidate;
                    break;
                }
            }
        }

        if ($currency === '') {
            foreach ($reservation as $key => $value) {
                if (is_string($key) && stripos($key, 'currency') !== false) {
                    $candidate = $extract_currency($value);
                    if ($candidate !== '') {
                        $currency = $candidate;
                        break;
                    }
                }
            }
        }
    }

    if ($currency === '') {
        $fallback = $extract_currency(Helpers\hic_get_currency());
        $currency = $fallback !== '' ? $fallback : 'EUR';
    }

    $price = Helpers\hic_normalize_price(isset($reservation['price']) ? $reservation['price'] : 0);
    $unpaid_balance = Helpers\hic_normalize_price(isset($reservation['unpaid_balance']) ? $reservation['unpaid_balance'] : 0);
    
    // Calculate value (use net value if configured)
    $value = $price;
    if (Helpers\hic_use_net_value() && $unpaid_balance > 0) {
        $value = max(0, $price - $unpaid_balance);
    }
    
    // Normalize guests
    $guests = max(1, intval(isset($reservation['guests']) ? $reservation['guests'] : 1));
    
    // Normalize language - check all possible fields
    $language = '';
    $lang_value = '';
    if (!empty($reservation['language']) && is_string($reservation['language'])) {
        $lang_value = $reservation['language'];
    } elseif (!empty($reservation['lang']) && is_string($reservation['lang'])) {
        $lang_value = $reservation['lang']; 
    } elseif (!empty($reservation['lingua']) && is_string($reservation['lingua'])) {
        $lang_value = $reservation['lingua'];
    }
    
    if (!empty($lang_value)) {
        $lang = strtolower(trim($lang_value));
        if (strlen($lang) >= 2) {
            $language = substr($lang, 0, 2); // Extract first 2 chars
        }
    }
    
    // Get transaction_id using flexible ID resolution
    $transaction_id = Helpers\hic_booking_uid($reservation);
    if (empty($transaction_id)) {
        // Fallback to first available scalar field if no standard ID found
        foreach ($reservation as $key => $value) {
            if (is_scalar($value) && !empty($value)) {
                $transaction_id = (string) $value;
                hic_log("Using fallback transaction_id from field '$key': $transaction_id");
                break;
            }
        }
    }
    
    // Provide fallback accommodation data if missing
    $accommodation_id = isset($reservation['accommodation_id']) ? $reservation['accommodation_id'] : '';
    $accommodation_name = isset($reservation['accommodation_name']) ? $reservation['accommodation_name'] : '';
    if (empty($accommodation_name) && !empty($accommodation_id)) {
        $accommodation_name = "Accommodation $accommodation_id"; // Fallback name
    } elseif (empty($accommodation_name)) {
        $accommodation_name = "Unknown Accommodation"; // Ultimate fallback
    }

    // Determine guest name from available fields
    $first = $reservation['guest_first_name']
        ?? $reservation['guest_firstname']
        ?? $reservation['first_name']
        ?? $reservation['firstname']
        ?? $reservation['client_first_name']
        ?? $reservation['customer_first_name']
        ?? $reservation['customer_firstname']
        ?? '';
    $last = $reservation['guest_last_name']
        ?? $reservation['guest_lastname']
        ?? $reservation['last_name']
        ?? $reservation['lastname']
        ?? $reservation['client_last_name']
        ?? $reservation['customer_last_name']
        ?? $reservation['customer_lastname']
        ?? '';

    if ((empty($first) || empty($last)) && !empty($reservation['guest_name']) && is_string($reservation['guest_name'])) {
        $parts = preg_split('/\s+/', trim($reservation['guest_name']), 2);
        if (empty($first) && isset($parts[0])) {
            $first = $parts[0];
        }
        if (empty($last) && isset($parts[1])) {
            $last = $parts[1];
        }
    }

    // Determine primary email from available fields
    $email = $reservation['guest_email']
        ?? $reservation['email']
        ?? $reservation['client_email']
        ?? '';
    if (!is_string($email)) {
        $email = '';
    }

    $sid_keys = ['sid', 'sessionid', 'hicsid', 'hicsessionid'];
    $extract_sid = static function ($source) use (&$extract_sid, $sid_keys) {
        if (is_array($source)) {
            foreach ($source as $key => $value) {
                $normalized_key = '';
                if (is_string($key) || is_int($key)) {
                    $normalized_key = strtolower(preg_replace('/[^a-z0-9]/', '', (string) $key));
                }

                if ($normalized_key !== '' && in_array($normalized_key, $sid_keys, true)) {
                    if (is_scalar($value)) {
                        $candidate = \sanitize_text_field((string) $value);
                        if ($candidate !== '') {
                            return $candidate;
                        }
                    } elseif (is_array($value) || is_object($value)) {
                        $candidate = $extract_sid($value);
                        if ($candidate !== '') {
                            return $candidate;
                        }
                    }
                }

                if (is_array($value) || is_object($value)) {
                    $candidate = $extract_sid(is_object($value) ? get_object_vars($value) : $value);
                    if ($candidate !== '') {
                        return $candidate;
                    }
                } elseif (is_string($value) && stripos($value, 'sid') !== false) {
                    $candidate = $extract_sid($value);
                    if ($candidate !== '') {
                        return $candidate;
                    }
                }
            }

            return '';
        }

        if (is_object($source)) {
            return $extract_sid(get_object_vars($source));
        }

        if (is_string($source)) {
            $trimmed = trim($source);
            if ($trimmed === '' || stripos($trimmed, 'sid') === false) {
                return '';
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $candidate = $extract_sid($decoded);
                if ($candidate !== '') {
                    return $candidate;
                }
            }

            if (strpos($trimmed, '=') !== false) {
                parse_str($trimmed, $parsed);
                if (!empty($parsed)) {
                    $candidate = $extract_sid($parsed);
                    if ($candidate !== '') {
                        return $candidate;
                    }
                }
            }

            if (preg_match('/\bsid\s*[:=]\s*([^&\s]+)/i', $trimmed, $matches)) {
                $candidate = \sanitize_text_field($matches[1]);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return '';
    };

    $sid = $extract_sid($reservation);

    $from = $reservation['from_date'] ?? $reservation['checkin'] ?? '';
    $to   = $reservation['to_date']   ?? $reservation['checkout'] ?? '';

    return array(
        'transaction_id' => $transaction_id,
        'reservation_code' => isset($reservation['reservation_code']) ? $reservation['reservation_code'] : '',
        'value' => $value,
        // Both 'value' and 'amount' are required by different consumers/APIs; currently, they are set to the same value.
        'amount' => $value,
        'currency' => $currency,
        'accommodation_id' => $accommodation_id,
        'accommodation_name' => $accommodation_name,
        'room_name' => isset($reservation['room_name']) ? $reservation['room_name'] : '',
        'guests' => $guests,
        'from_date' => $from,
        'to_date' => $to,
        'presence' => isset($reservation['presence']) ? $reservation['presence'] : '',
        'unpaid_balance' => $unpaid_balance,
        'guest_first_name' => $first,
        'guest_last_name' => $last,
        'email' => $email,
        'phone' => $reservation['phone'] ?? $reservation['whatsapp'] ?? '',
        'language' => $language,
        'original_price' => $price,
        'sid' => $sid
    );
}

/**
 * Dispatch transformed reservation to all services
 */
function hic_dispatch_reservation($transformed, $original) {
    $canonical_uid = Helpers\hic_booking_uid($original);
    $aliases = is_array($original)
        ? Helpers\hic_collect_reservation_ids($original)
        : array();
    if (empty($aliases) && $canonical_uid !== '') {
        $sanitized_uid = \sanitize_text_field((string) $canonical_uid);
        $sanitized_uid = trim($sanitized_uid);
        if ($sanitized_uid !== '') {
            $aliases[] = $sanitized_uid;
        }
    }

    $processed_alias = is_array($original)
        ? Helpers\hic_find_processed_reservation_alias($original)
        : null;
    $uid = $processed_alias ?? $canonical_uid;
    if ($uid === '' && !empty($aliases)) {
        $uid = $aliases[0];
    }

    $is_status_update = Helpers\hic_is_reservation_already_processed($aliases);

    // Debug log to verify fixes are in place
    $realtime_enabled = Helpers\hic_realtime_brevo_sync_enabled();
    $connection_type = Helpers\hic_get_connection_type();
    hic_log(array('Reservation dispatch debug' => array(
        'uid' => $uid,
        'is_status_update' => $is_status_update,
        'realtime_brevo_enabled' => $realtime_enabled,
        'connection_type' => $connection_type,
        'value' => $transformed['value'] ?? 'missing',
        'currency' => $transformed['currency'] ?? 'missing',
        'email' => !empty($transformed['email']) ? 'present' : 'missing'
    )));

    try {
        // Get tracking mode to determine which integrations to use
        $tracking_mode = Helpers\hic_get_tracking_mode();
        $sid = '';
        if (!empty($transformed['sid']) && is_scalar($transformed['sid'])) {
            $sid = \sanitize_text_field((string) $transformed['sid']);
        }

        $result = Helpers\hic_create_integration_result([
            'uid' => $uid,
        ]);
        $result['context'] = array(
            'connection_type' => $connection_type,
            'tracking_mode' => $tracking_mode,
            'is_status_update' => $is_status_update ? '1' : '0',
        );

        $record_result = static function (string $integration, string $status, string $note = '') use (&$result): void {
            Helpers\hic_append_integration_result($result, $integration, $status, $note);
        };

        // GA4 - only send once unless it's a status update we want to track
        if (!$is_status_update) {
            if ($tracking_mode === 'ga4_only' || $tracking_mode === 'hybrid') {
                if (Helpers\hic_get_measurement_id() && Helpers\hic_get_api_secret()) {
                    $ga4_success = hic_dispatch_ga4_reservation($transformed, $sid);
                    $record_result('GA4', $ga4_success ? 'success' : 'failed');
                } else {
                    $record_result('GA4', 'skipped');
                }
            }

            // GTM - send to dataLayer for client-side processing
            if ($tracking_mode === 'gtm_only' || $tracking_mode === 'hybrid') {
                if (Helpers\hic_is_gtm_enabled()) {
                    $gtm_success = hic_dispatch_gtm_reservation($transformed, $sid);
                    $record_result('GTM', $gtm_success ? 'success' : 'failed');
                } else {
                    $record_result('GTM', 'skipped');
                }
            }
        }

        // Meta Pixel - only send once unless it's a status update we want to track
        if (!$is_status_update) {
            if (Helpers\hic_get_fb_pixel_id() && Helpers\hic_get_fb_access_token()) {
                $pixel_success = hic_dispatch_pixel_reservation($transformed, $sid);
                $record_result('Meta Pixel', $pixel_success ? 'success' : 'failed');
            } else {
                $record_result('Meta Pixel', 'skipped');
            }
        }

        // Retrieve tracking IDs using SID (fallback to transaction ID when missing)
        $lookup_id = $sid !== '' ? $sid : ($transformed['transaction_id'] ?? '');
        $gclid = '';
        $fbclid = '';
        $msclkid = '';
        $ttclid = '';
        $gbraid = '';
        $wbraid = '';
        if (!empty($lookup_id) && is_scalar($lookup_id)) {
            $tracking = Helpers\hic_get_tracking_ids_by_sid((string) $lookup_id);
            if (is_array($tracking)) {
                $gclid = $tracking['gclid'] ?? '';
                $fbclid = $tracking['fbclid'] ?? '';
                $msclkid = $tracking['msclkid'] ?? '';
                $ttclid = $tracking['ttclid'] ?? '';
                $gbraid = $tracking['gbraid'] ?? '';
                $wbraid = $tracking['wbraid'] ?? '';
            }
        }

        $brevo_enabled = Helpers\hic_is_brevo_enabled() && Helpers\hic_get_brevo_api_key();

        // Brevo - handle differently based on connection type to prevent duplication
        if ($brevo_enabled) {
            $email_value = '';
            if (isset($transformed['email']) && is_scalar($transformed['email'])) {
                $email_value = \sanitize_email((string) $transformed['email']);
            }

            if (!Helpers\hic_is_valid_email($email_value)) {
                hic_log('Brevo contact skipped for reservation ' . $uid . ': missing or invalid email');
                $record_result('Brevo contact', 'skipped', 'missing email');
            } else {
                $transformed['email'] = $email_value;
                $brevo_result = hic_dispatch_brevo_reservation($transformed, false, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid, $sid);

                if ($brevo_result === true) {
                    $record_result('Brevo contact', 'success');
                } elseif ($brevo_result === 'skipped') {
                    $record_result('Brevo contact', 'skipped');
                } else {
                    hic_log('Brevo contact dispatch failed for reservation ' . $uid);
                    $record_result('Brevo contact', 'failed');
                }
            }
        } else {
            $record_result('Brevo contact', 'skipped');
        }

        $should_send_brevo_event = !$is_status_update
            && $connection_type !== 'webhook'
            && Helpers\hic_realtime_brevo_sync_enabled();

        if ($should_send_brevo_event) {
            if ($brevo_enabled) {
                $event_result = hic_send_brevo_reservation_created_event($transformed, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid);
                if (is_array($event_result)) {
                    if (!empty($event_result['success'])) {
                        $record_result('Brevo event', 'success');
                    } elseif (!empty($event_result['skipped'])) {
                        $record_result('Brevo event', 'skipped');
                    } elseif (array_key_exists('retryable', $event_result) && $event_result['retryable'] === false) {
                        $error_message = '';
                        if (isset($event_result['error']) && is_scalar($event_result['error'])) {
                            $error_message = trim((string) $event_result['error']);
                        }
                        if ($error_message !== '') {
                            $error_message = \sanitize_text_field($error_message);
                        }
                        $warning_message = 'Brevo reservation_created event permanently failed for reservation ' . $uid;
                        if ($error_message !== '') {
                            $warning_message .= ': ' . $error_message;
                        }
                        hic_log($warning_message, HIC_LOG_LEVEL_WARNING);

                        $note = 'permanent Brevo failure';
                        if ($error_message !== '') {
                            $note .= ': ' . $error_message;
                        }
                        $record_result('Brevo event', 'skipped', $note);
                    } else {
                        $error_message = '';
                        if (isset($event_result['error']) && is_scalar($event_result['error'])) {
                            $error_message = trim((string) $event_result['error']);
                        }
                        if ($error_message !== '') {
                            $error_message = \sanitize_text_field($error_message);
                        }
                        hic_log('Failed to send Brevo reservation_created event in dispatch: ' . $error_message);
                        $record_result('Brevo event', 'failed');
                    }
                } else {
                    $record_result('Brevo event', 'failed');
                }
            } else {
                $record_result('Brevo event', 'skipped');
            }
        }

        // Admin email notification - send only for new reservations to avoid duplicates
        if (!$is_status_update) {
            $admin_data = array(
                'reservation_id' => isset($transformed['transaction_id']) ? $transformed['transaction_id'] : '',
                'amount'        => isset($transformed['value']) ? $transformed['value'] : 0,
                'currency'      => isset($transformed['currency']) ? $transformed['currency'] : 'EUR',
                'first_name'    => isset($transformed['guest_first_name']) ? $transformed['guest_first_name'] : '',
                'last_name'     => isset($transformed['guest_last_name']) ? $transformed['guest_last_name'] : '',
                'email'         => isset($transformed['email']) ? $transformed['email'] : '',
                'phone'         => isset($transformed['phone']) ? $transformed['phone'] : '',
                'lingua'        => isset($transformed['language']) ? $transformed['language'] : '',
                'room'          => isset($transformed['accommodation_name']) ? $transformed['accommodation_name'] : (isset($transformed['room_name']) ? $transformed['room_name'] : ''),
                'checkin'       => isset($transformed['from_date']) ? $transformed['from_date'] : '',
                'checkout'      => isset($transformed['to_date']) ? $transformed['to_date'] : ''
            );

            $email_identifier = $sid !== '' ? $sid : ($transformed['transaction_id'] ?? '');
            $admin_email = Helpers\hic_get_admin_email();
            if (!empty($admin_email) && Helpers\hic_is_valid_email($admin_email)) {
                $email_result = Helpers\hic_send_admin_email($admin_data, $gclid, $fbclid, (string) $email_identifier);
                if ($email_result) {
                    hic_log('Admin email dispatch succeeded for reservation ' . $uid);
                    $record_result('Admin email', 'success');
                } else {
                    $warning_message = 'Admin email dispatch failed for reservation ' . $uid . ' - marking as skipped. Check SMTP or wp_mail configuration.';
                    hic_log($warning_message, HIC_LOG_LEVEL_WARNING);
                    $record_result('Admin email', 'skipped', 'send failed');
                }
            } else {
                $record_result('Admin email', 'skipped');
            }
        }

        $result = Helpers\hic_finalize_integration_result($result);

        $summary_parts = array();
        foreach ($result['integrations'] as $integration => $details) {
            $status = isset($details['status']) ? (string) $details['status'] : '';
            $note = isset($details['note']) ? (string) $details['note'] : '';
            $part = $integration . '=' . $status;
            if ($note !== '') {
                $part .= ' (' . $note . ')';
            }
            $summary_parts[] = $part;
        }
        $summary = !empty($summary_parts) ? implode(', ', $summary_parts) : '';

        $skipped_messages = array();
        foreach ($result['skipped_integrations'] as $integration => $note) {
            $entry = $integration;
            if ($note !== '') {
                $entry .= ' (' . $note . ')';
            }
            $skipped_messages[] = $entry;
        }

        $result['summary'] = $summary;

        if ($result['status'] === 'failed') {
            $message = "Reservation $uid dispatch failed (mode: $connection_type)";
            if (!empty($result['failed_integrations'])) {
                $message .= ' - Failed integrations: ' . implode(', ', $result['failed_integrations']);
            }
            if ($summary !== '') {
                $message .= " - Summary: $summary";
            }
            hic_log($message, HIC_LOG_LEVEL_ERROR);
            return $result;
        }

        if ($result['status'] === 'partial') {
            $message = "Reservation $uid dispatched with partial success (mode: $connection_type)";
            if (!empty($result['failed_integrations'])) {
                $message .= ' - Failed integrations: ' . implode(', ', $result['failed_integrations']);
            }
            if ($summary !== '') {
                $message .= " - Summary: $summary";
            }
            if (!empty($skipped_messages)) {
                $message .= " - Skipped: " . implode(', ', $skipped_messages);
            }
            hic_log($message, HIC_LOG_LEVEL_WARNING);
            return $result;
        }

        $message = "Reservation $uid dispatched successfully (mode: $connection_type)";
        if ($summary !== '') {
            $message .= " - Summary: $summary";
        }
        if (!empty($skipped_messages)) {
            $message .= " - Skipped: " . implode(', ', $skipped_messages);
        }
        hic_log($message);

        return $result;
    } catch (\Exception $e) {
        hic_log("Error dispatching reservation $uid: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Deduplication functions
 */

function hic_mark_reservation_processed($reservation) {
    if (!is_array($reservation)) {
        $uid = Helpers\hic_booking_uid($reservation);
        if ($uid === '') {
            return;
        }

        Helpers\hic_mark_reservation_processed_by_id($uid);
        return;
    }

    $ids = Helpers\hic_collect_reservation_ids($reservation);
    if (empty($ids)) {
        $uid = Helpers\hic_booking_uid($reservation);
        if ($uid === '') {
            return;
        }
        $ids = array($uid);
    }

    Helpers\hic_mark_reservation_processed_by_id($ids);
}

// Wrapper function - now simplified to use continuous polling by default
if (!function_exists(__NAMESPACE__ . '\\hic_api_poll_bookings')) {
    function hic_api_poll_bookings(){
        $start_time = microtime(true);
        hic_log('Internal Scheduler: hic_api_poll_bookings execution started');
        $log_manager = hic_get_log_manager();
        if ($log_manager) {
            $log_manager->rotate_if_needed();
        }

        // Use the new simplified continuous polling
        hic_api_poll_bookings_continuous();

        $execution_time = round((microtime(true) - $start_time) * 1000, 2);
        hic_log("Internal Scheduler: hic_api_poll_bookings completed in {$execution_time}ms");
    }
}

/**
 * Quasi-realtime polling with moving window approach
 */
function hic_quasi_realtime_poll($prop_id, $start_time) {
    $current_time = time();
    
    // Moving window: 15 minutes back + 5 minutes forward
    $window_back_minutes = 15;
    $window_forward_minutes = 5;
    
    $from_time = $current_time - ($window_back_minutes * 60);
    $to_time = $current_time + ($window_forward_minutes * 60);
    
    $from_date = wp_date('Y-m-d H:i:s', $from_time);
    $to_date = wp_date('Y-m-d H:i:s', $to_time);
    
    hic_log("Internal Scheduler: Moving window polling from $from_date to $to_date (property: $prop_id)");
    
    $total_new = 0;
    $total_skipped = 0;
    $total_errors = 0;
    $polling_errors = array();
    
        // Check for new and updated reservations using /reservations_updates endpoint
        // This catches all recently created or modified reservations
        $max_lookback_seconds = 6 * 86400; // 6 days for safety margin
        $earliest_allowed = $current_time - $max_lookback_seconds;
        $default_check = max($earliest_allowed, $current_time - 7200); // Default to 2 hours ago or earliest allowed
        
        $last_update_check = get_option('hic_last_update_check', $default_check);
        
        // Use centralized timestamp validation  
        $validated_check = hic_validate_api_timestamp($last_update_check, 'Quasi-realtime Poll');
        
        // Update stored timestamp if it was adjusted
        if ($validated_check !== $last_update_check) {
            update_option('hic_last_update_check', $validated_check, false);
            Helpers\hic_clear_option_cache('hic_last_update_check');
            hic_log("Quasi-realtime Poll: Updated stored timestamp from " . wp_date('Y-m-d H:i:s', $last_update_check) . " to " . wp_date('Y-m-d H:i:s', $validated_check));
            $last_update_check = $validated_check;
        }
        
        hic_log("Internal Scheduler: Checking for updates since " . wp_date('Y-m-d H:i:s', $last_update_check));
        $updated_reservations = hic_fetch_reservations_updates($prop_id, $last_update_check, 100);
        
        if (!is_wp_error($updated_reservations)) {
            $updated_count = is_array($updated_reservations) ? count($updated_reservations) : 0;
            if ($updated_count > 0) {
                hic_log("Internal Scheduler: Found $updated_count updated/new reservations");
                $process_result = hic_process_reservations_batch($updated_reservations);
                $total_new += $process_result['new'];
                $total_skipped += $process_result['skipped'];
                $total_errors += $process_result['errors'];
            }
            
            // Update the last check timestamp
            update_option('hic_last_update_check', $current_time, false);
            Helpers\hic_clear_option_cache('hic_last_update_check');
        } else {
            $error_message = $updated_reservations->get_error_message();
            $polling_errors[] = "updates polling: " . $error_message;
            $total_errors++;
            
            // Check if this is a timestamp too old error and reset if necessary
            if ($updated_reservations->get_error_code() === 'hic_timestamp_too_old') {
                // Use more conservative reset - go back 3 days to ensure we're well within limits
                $reset_timestamp = $current_time - (3 * 86400); // Reset to 3 days ago
                
                // Validate the reset timestamp before using it
                $validated_reset = hic_validate_api_timestamp($reset_timestamp, 'Quasi-realtime Poll timestamp reset');
                
                update_option('hic_last_update_check', $validated_reset, false);
                Helpers\hic_clear_option_cache('hic_last_update_check');
                hic_log('Quasi-realtime Poll: Timestamp error detected, reset timestamp to: ' . wp_date('Y-m-d H:i:s', $validated_reset) . " ($validated_reset)");
                
                // Also reset scheduler timestamps to restart polling immediately with safe values
                $recent_timestamp = $current_time - 300; // 5 minutes ago
                $validated_recent = hic_validate_api_timestamp($recent_timestamp, 'Quasi-realtime Poll scheduler restart');
                
                update_option('hic_last_continuous_poll', $validated_recent, false);
                Helpers\hic_clear_option_cache('hic_last_continuous_poll');
                update_option('hic_last_deep_check', $validated_recent, false);
                Helpers\hic_clear_option_cache('hic_last_deep_check');
                hic_log('Quasi-realtime Poll: Reset scheduler timestamps to restart polling: ' . wp_date('Y-m-d H:i:s', $validated_recent));
            }
        }
    
    // Also poll by checkin date to catch any updates to existing bookings
    $checkin_from = wp_date('Y-m-d', $from_time);
    $checkin_to = wp_date('Y-m-d', $to_time + (7 * 86400)); // Extend checkin window
    
    hic_log("Internal Scheduler: Polling by checkin date from $checkin_from to $checkin_to");
    $checkin_reservations = hic_fetch_reservations_raw($prop_id, 'checkin', $checkin_from, $checkin_to, 100);
    
    if (!is_wp_error($checkin_reservations)) {
        $checkin_count = is_array($checkin_reservations) ? count($checkin_reservations) : 0;
        hic_log("Internal Scheduler: Found $checkin_count reservations by checkin date");
        
        if ($checkin_count > 0) {
            $process_result = hic_process_reservations_batch($checkin_reservations);
            $total_new += $process_result['new'];
            $total_skipped += $process_result['skipped'];
            $total_errors += $process_result['errors'];
        }
    } else {
        $polling_errors[] = "checkin date polling: " . $checkin_reservations->get_error_message();
        $total_errors++;
    }
    
    // Calculate execution time
    $execution_time = round((microtime(true) - $start_time) * 1000, 2);
    
    // Store metrics for diagnostics
    update_option('hic_last_poll_count', $total_new, false);
    Helpers\hic_clear_option_cache('hic_last_poll_count');
    update_option('hic_last_poll_skipped', $total_skipped, false);
    Helpers\hic_clear_option_cache('hic_last_poll_skipped');
    update_option('hic_last_poll_duration', $execution_time, false);
    Helpers\hic_clear_option_cache('hic_last_poll_duration');
    
    // Update last successful run if polling succeeded or processed new reservations
    $polling_successful = empty($polling_errors) && $total_errors === 0;
    if ($polling_successful || $total_new > 0) {
        update_option('hic_last_successful_poll', $current_time, false);
        Helpers\hic_clear_option_cache('hic_last_successful_poll');
        hic_log("Internal Scheduler: Updated last successful poll timestamp");
    }
    
    // Comprehensive logging
    $log_msg = sprintf(
        "Internal Scheduler: Completed in %sms - Window: %s to %s, New: %d, Skipped: %d, Errors: %d",
        $execution_time,
        $from_date,
        $to_date, 
        $total_new,
        $total_skipped,
        $total_errors
    );
    
    if (!empty($polling_errors)) {
        $log_msg .= " - API Errors: " . implode('; ', $polling_errors);
    }
    
    hic_log($log_msg);
}

/**
 * Process a batch of reservations with comprehensive filtering and counting
 */
function hic_process_reservations_batch($reservations) {
    $start = microtime(true);
    $new_count = 0;
    $partial_count = 0;
    $skipped_count = 0;
    $error_count = 0;
    $remaining_count = 0;

    $normalize_result = static function ($raw, $uid) {
        if (is_array($raw)) {
            return $raw;
        }

        $result = Helpers\hic_create_integration_result([
            'uid' => $uid,
        ]);
        $result['status'] = $raw ? 'success' : 'failed';

        return Helpers\hic_finalize_integration_result($result);
    };

    if (!is_array($reservations)) {
        return array('new' => 0, 'skipped' => 0, 'errors' => 1, 'remaining' => 0);
    }

    foreach ($reservations as $index => $reservation) {
        // Stop processing if execution time exceeds 25 seconds
        if ((microtime(true) - $start) > 25) {
            $remaining_count = count($reservations) - $index;
            hic_log("Batch processing time limit reached, $remaining_count reservations remaining");
            // Schedule immediate retry via WP-Cron
            if (function_exists('wp_schedule_single_event')) {
                \wp_schedule_single_event(time() + 5, 'hic_continuous_poll_event');
            }
            break;
        }
        $canonical_uid = '';
        $dedup_uid = '';
        $aliases = array();
        try {
            // Apply minimal filters first
            if (!hic_should_process_reservation($reservation)) {
                $skipped_count++;
                continue;
            }

            $canonical_uid = Helpers\hic_booking_uid($reservation);
            $aliases = is_array($reservation)
                ? Helpers\hic_collect_reservation_ids($reservation)
                : array();
            if (empty($aliases) && $canonical_uid !== '') {
                $sanitized_uid = \sanitize_text_field((string) $canonical_uid);
                $sanitized_uid = trim($sanitized_uid);
                if ($sanitized_uid !== '') {
                    $aliases[] = $sanitized_uid;
                }
            }

            $processed_alias = is_array($reservation)
                ? Helpers\hic_find_processed_reservation_alias($reservation)
                : null;
            $dedup_uid = $processed_alias ?? $canonical_uid;
            if ($dedup_uid === '' && !empty($aliases)) {
                $dedup_uid = $aliases[0];
            }

            // Check deduplication
            if (!empty($aliases) && Helpers\hic_is_reservation_already_processed($aliases)) {
                // Check if status update is allowed
                if (Helpers\hic_allow_status_updates()) {
                    $presence = $reservation['presence'] ?? '';
                    if (in_array($presence, ['arrived', 'departed'])) {
                        hic_log("Reservation $dedup_uid: processing status update for presence=$presence");

                        // Acquire lock for status update processing
                        if ($dedup_uid === '') {
                            $dedup_uid = $canonical_uid;
                        }
                        if (!Helpers\hic_acquire_reservation_lock($dedup_uid, 10)) {
                            hic_log("Reservation $dedup_uid: skipped status update due to concurrent processing");
                            continue;
                        }

                        try {
                            // Process as status update but don't count as new
                            $status_result = $normalize_result(hic_process_single_reservation($reservation), $dedup_uid);
                            $status_value = isset($status_result['status']) ? (string) $status_result['status'] : 'failed';

                            if ($status_value === 'failed') {
                                $error_count++;
                                hic_log("Reservation $dedup_uid: status update failed");
                            } else {
                                if ($status_value === 'partial') {
                                    $failed = isset($status_result['failed_integrations']) && is_array($status_result['failed_integrations'])
                                        ? $status_result['failed_integrations']
                                        : array();
                                    if (!empty($status_result['failed_details'])) {
                                        Helpers\hic_queue_integration_retry($dedup_uid, $status_result['failed_details'], array(
                                            'source' => 'polling',
                                            'type' => 'status_update',
                                        ));
                                    }
                                    $failed_message = !empty($failed) ? ' - Failed integrations: ' . implode(', ', $failed) : '';
                                    hic_log("Reservation $dedup_uid: status update processed with partial failures$failed_message", HIC_LOG_LEVEL_WARNING);
                                } else {
                                    hic_log("Reservation $dedup_uid: status update processed");
                                }

                                if ($status_value === 'success' || ($status_value === 'partial' && !empty($status_result['should_mark_processed']))) {
                                    hic_mark_reservation_processed($reservation);
                                }
                            }
                        } finally {
                            Helpers\hic_release_reservation_lock($dedup_uid);
                        }
                        continue;
                    }
                }
                Helpers\hic_mark_reservation_processed_by_id($aliases);
                $skipped_count++;
                hic_log("Reservation $dedup_uid: skipped (already processed)");
                continue;
            }

            // Acquire lock for new reservation processing
            if ($dedup_uid === '') {
                $dedup_uid = $canonical_uid;
            }
            if (!Helpers\hic_acquire_reservation_lock($dedup_uid)) {
                hic_log("Reservation $dedup_uid: skipped due to concurrent processing");
                $skipped_count++;
                continue;
            }

            try {
                // Process new reservation
                $process_result = $normalize_result(hic_process_single_reservation($reservation), $dedup_uid);
                $status_value = isset($process_result['status']) ? (string) $process_result['status'] : 'failed';

                if ($status_value === 'failed') {
                    $error_count++;
                    hic_log("Reservation $dedup_uid: dispatch failed, will retry");
                } else {
                    if (!empty($process_result['should_mark_processed'])) {
                        hic_mark_reservation_processed($reservation);
                    }

                    $new_count++;

                    if ($status_value === 'partial') {
                        $partial_count++;
                        if (!empty($process_result['failed_details'])) {
                            Helpers\hic_queue_integration_retry($dedup_uid, $process_result['failed_details'], array(
                                'source' => 'polling',
                                'type' => 'new_reservation',
                            ));
                        }
                        $failed = isset($process_result['failed_integrations']) && is_array($process_result['failed_integrations'])
                            ? $process_result['failed_integrations']
                            : array();
                        $failed_message = !empty($failed) ? ' - Failed integrations: ' . implode(', ', $failed) : '';
                        hic_log("Reservation $dedup_uid: processed with partial failures$failed_message", HIC_LOG_LEVEL_WARNING);
                    } else {
                        hic_log("Reservation $dedup_uid: processed");
                    }
                }
            } finally {
                Helpers\hic_release_reservation_lock($dedup_uid);
            }

        } catch (\Exception $e) {
            $error_count++;
            $log_uid = $dedup_uid !== '' ? $dedup_uid : $canonical_uid;
            if ($log_uid === '') {
                $log_uid = Helpers\hic_booking_uid($reservation);
            }
            hic_log("Reservation $log_uid: failed with error - " . $e->getMessage());
        }
    }

    hic_log("Batch summary: processed=$new_count, partial=$partial_count, skipped=$skipped_count, failed=$error_count, remaining=$remaining_count");
    return array('new' => $new_count, 'partial' => $partial_count, 'skipped' => $skipped_count, 'errors' => $error_count, 'remaining' => $remaining_count);
}

/**
 * Process a single reservation (transform and dispatch)
 *
 * @return bool True on success, false on transformation failure
 */
function hic_process_single_reservation($reservation) {
    $uid = Helpers\hic_booking_uid($reservation);
    $result = Helpers\hic_create_integration_result([
        'uid' => $uid,
    ]);

    $transformed = hic_transform_reservation($reservation);

    if ($transformed !== false && is_array($transformed)) {
        $dispatch_result = hic_dispatch_reservation($transformed, $reservation);

        if (is_array($dispatch_result)) {
            return $dispatch_result;
        }

        $result['status'] = 'failed';
        $result['messages'][] = 'dispatch_failure';

        if (!empty($uid)) {
            hic_log("Reservation $uid: dispatch failed, keeping reservation for retry");
        } else {
            hic_log('Reservation dispatch failed, keeping reservation for retry');
        }

        return Helpers\hic_finalize_integration_result($result);
    }

    $result['status'] = 'failed';
    $result['messages'][] = 'transformation_failed';

    if (!empty($uid)) {
        hic_log("Reservation $uid: transformation failed");
    } else {
        hic_log('Reservation transformation failed: missing UID');
    }

    return Helpers\hic_finalize_integration_result($result);
}

/**
 * New updates polling wrapper function
 */
function hic_api_poll_updates(){
    hic_log('Internal Scheduler: hic_api_poll_updates execution started');
    
    // Always update execution timestamp regardless of results
    update_option('hic_last_api_poll', time(), false);
    Helpers\hic_clear_option_cache('hic_last_api_poll');
    
    $prop = Helpers\hic_get_property_id();
    
    // Add safety overlap to prevent gaps between polling intervals
    $overlap_seconds = 300; // 5 minute overlap for safety
    
    // Ensure we never use a timestamp older than 6 days (API limit is 7 days)
    $current_time = time();
    $max_lookback_seconds = 6 * 86400; // 6 days for safety margin
    $earliest_allowed = $current_time - $max_lookback_seconds;
    $default_since = max($earliest_allowed, $current_time - 86400); // Default to 1 day ago or earliest allowed
    
    $last_since = get_option('hic_last_updates_since', $default_since);
    
    // Use centralized timestamp validation
    $validated_last_since = hic_validate_api_timestamp($last_since, 'Internal Scheduler');
    
    // Update stored timestamp if it was adjusted
    if ($validated_last_since !== $last_since) {
        update_option('hic_last_updates_since', $validated_last_since, false);
        Helpers\hic_clear_option_cache('hic_last_updates_since');
        hic_log("Internal Scheduler: Updated stored timestamp from " . wp_date('Y-m-d H:i:s', $last_since) . " to " . wp_date('Y-m-d H:i:s', $validated_last_since));
        $last_since = $validated_last_since;
    }
    
    $since = max(0, $last_since - $overlap_seconds);
    
    hic_log("Internal Scheduler: polling updates for property $prop");
    hic_log("Internal Scheduler: last timestamp: " . wp_date('Y-m-d H:i:s', $last_since) . " ($last_since)");
    hic_log("Internal Scheduler: requesting since: " . wp_date('Y-m-d H:i:s', $since) . " ($since) [overlap: {$overlap_seconds}s]");
    
    $out = hic_fetch_reservations_updates($prop, $since, 100); // limit opzionale se supportato
    if (!is_wp_error($out)) {
        $updates_count = is_array($out) ? count($out) : 0;
        hic_log("Internal Scheduler: Found $updates_count updates");
        
        // Calculate new timestamp based on actual updates
        $new_timestamp = $current_time;
        if ($updates_count > 0 && is_array($out)) {
            // Find the maximum updated_at timestamp from the actual updates
            $max_updated_at = 0;
            foreach ($out as $update) {
                if (isset($update['updated_at'])) {
                    $updated_at = is_numeric($update['updated_at']) ? (int)$update['updated_at'] : strtotime($update['updated_at']);
                    if ($updated_at > $max_updated_at) {
                        $max_updated_at = $updated_at;
                    }
                }
            }
            
              // Use the max updated_at if found, otherwise use current time
              if ($max_updated_at > 0) {
                  $new_timestamp = $max_updated_at;
                  hic_log("Internal Scheduler: Using max updated_at timestamp: " . wp_date('Y-m-d H:i:s', $new_timestamp) . " ($new_timestamp)");
              } else {
                  hic_log("Internal Scheduler: No updated_at field found, using current time: " . wp_date('Y-m-d H:i:s', $new_timestamp) . " ($new_timestamp)");
              }
        } else {
            // No updates found - advance timestamp only if enough time has passed to prevent infinite polling
              $time_since_last_poll = $current_time - $last_since;
            if ($time_since_last_poll > 3600) { // 1 hour
                hic_log("Internal Scheduler: No updates found but 1+ hour passed, advancing timestamp to prevent infinite polling");
            } else {
                // Keep previous timestamp for retry, but with small increment to avoid exact same request
                $new_timestamp = $last_since + 60; // Advance by 1 minute to make progress
                hic_log("Internal Scheduler: No updates found, advancing by 1 minute for next retry: " . wp_date('Y-m-d H:i:s', $new_timestamp) . " ($new_timestamp)");
            }
        }
        
        update_option('hic_last_updates_since', $new_timestamp, false);
        Helpers\hic_clear_option_cache('hic_last_updates_since');
        hic_log('Internal Scheduler: hic_api_poll_updates completed successfully');
    } else {
        $error_message = $out->get_error_message();
        hic_log('Internal Scheduler: hic_api_poll_updates failed: ' . $error_message);
        
        // Check if this is a timestamp too old error and reset if necessary
        if ($out->get_error_code() === 'hic_timestamp_too_old') {
            // Use a more conservative reset - go back 3 days to ensure we're well within limits
            $reset_timestamp = $current_time - (3 * 86400); // Reset to 3 days ago
            
            // Validate the reset timestamp before using it
            $validated_reset = hic_validate_api_timestamp($reset_timestamp, 'Internal Scheduler timestamp reset');
            
            update_option('hic_last_updates_since', $validated_reset, false);
            Helpers\hic_clear_option_cache('hic_last_updates_since');
            hic_log('Internal Scheduler: Timestamp error detected, reset timestamp to: ' . wp_date('Y-m-d H:i:s', $validated_reset) . " ($validated_reset)");
            
            // Also reset scheduler timestamps to restart polling immediately with safe values
            $recent_timestamp = $current_time - 300; // 5 minutes ago
            $validated_recent = hic_validate_api_timestamp($recent_timestamp, 'Internal Scheduler scheduler restart');
            
            update_option('hic_last_continuous_poll', $validated_recent, false);
            Helpers\hic_clear_option_cache('hic_last_continuous_poll');
            update_option('hic_last_deep_check', $validated_recent, false);
            Helpers\hic_clear_option_cache('hic_last_deep_check');
            hic_log('Internal Scheduler: Reset scheduler timestamps to restart polling: ' . wp_date('Y-m-d H:i:s', $validated_recent));
        }
    }
}

/**
 * Retry failed Brevo notifications
 */
function hic_retry_failed_brevo_notifications() {
    if (!Helpers\hic_realtime_brevo_sync_enabled()) {
        hic_log('Real-time Brevo sync disabled, skipping retry');
        return;
    }

    $max_attempts = 3;
    $retry_delay_minutes = 30;

    hic_log('Internal Scheduler: hic_retry_failed_brevo_notifications execution started');

    $failed_reservations = hic_get_failed_reservations_for_retry($max_attempts, $retry_delay_minutes);

    if (empty($failed_reservations)) {
        hic_log('No failed reservations to retry');
        return;
    }

    $attempted = 0;
    $success_count = 0;
    $requeued_count = 0;
    $permanent_count = 0;

    foreach ($failed_reservations as $failed) {
        $attempted++;
        $reservation_id = $failed->reservation_id;
        $current_attempt = isset($failed->attempt_count) ? (int) $failed->attempt_count : 0;
        $next_attempt = $current_attempt + 1;

        hic_log("Retrying failed notification for reservation $reservation_id (attempt $next_attempt)");

        if ($current_attempt >= $max_attempts) {
            hic_log("Reservation $reservation_id already reached max retry attempts ($current_attempt), marking as permanent failure");
            hic_mark_reservation_notification_permanent_failure($reservation_id, 'Max retry attempts reached');
            $permanent_count++;
            continue;
        }

        $payload_data = array();
        if (!empty($failed->payload_json)) {
            $decoded_payload = json_decode($failed->payload_json, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_payload)) {
                $payload_data = $decoded_payload;
            } else {
                $json_error = function_exists('json_last_error_msg') ? json_last_error_msg() : 'JSON decode error';
                hic_log("Reservation $reservation_id has invalid retry payload: $json_error");
            }
        }

        if (empty($payload_data) || empty($payload_data['data']) || !is_array($payload_data['data'])) {
            hic_log("Reservation $reservation_id missing retry payload, marking as permanent failure to avoid infinite loop");
            hic_mark_reservation_notification_permanent_failure($reservation_id, 'Missing retry payload');
            $permanent_count++;
            continue;
        }

        $transformed = $payload_data['data'];
        $gclid = isset($payload_data['gclid']) ? $payload_data['gclid'] : '';
        $fbclid = isset($payload_data['fbclid']) ? $payload_data['fbclid'] : '';
        $msclkid = isset($payload_data['msclkid']) ? $payload_data['msclkid'] : '';
        $ttclid = isset($payload_data['ttclid']) ? $payload_data['ttclid'] : '';
        $gbraid = isset($payload_data['gbraid']) ? $payload_data['gbraid'] : '';
        $wbraid = isset($payload_data['wbraid']) ? $payload_data['wbraid'] : '';

        $event_result = hic_send_brevo_reservation_created_event($transformed, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid);

        if (is_array($event_result) && $event_result['success']) {
            hic_mark_reservation_notified_to_brevo($reservation_id);
            hic_log("Reservation $reservation_id Brevo notification sent successfully on retry");
            $success_count++;
            continue;
        }

        $error_message = 'Unknown error during retry';
        $retryable = false;
        $skipped = false;

        if (is_array($event_result)) {
            $error_message = !empty($event_result['error']) ? $event_result['error'] : $error_message;
            $retryable = !empty($event_result['retryable']);
            $skipped = !empty($event_result['skipped']);
        } elseif (is_wp_error($event_result)) {
            $error_message = $event_result->get_error_message();
        }

        if ($skipped) {
            hic_log("Reservation $reservation_id Brevo retry skipped: $error_message");
            hic_mark_reservation_notification_permanent_failure($reservation_id, 'Event skipped during retry: ' . $error_message);
            $permanent_count++;
            continue;
        }

        if (!$retryable) {
            hic_log("Reservation $reservation_id Brevo notification failed with non-retryable error: $error_message");
            hic_mark_reservation_notification_permanent_failure($reservation_id, $error_message);
            $permanent_count++;
            continue;
        }

        hic_mark_reservation_notification_failed($reservation_id, $error_message, $payload_data);

        if ($next_attempt >= $max_attempts) {
            hic_log("Reservation $reservation_id reached max retry attempts ($max_attempts), marking as permanent failure");
            hic_mark_reservation_notification_permanent_failure($reservation_id, $error_message);
            $permanent_count++;
            continue;
        }

        $requeued_count++;
        hic_log("Reservation $reservation_id retry failed (attempt $next_attempt), will retry after {$retry_delay_minutes} minutes");
    }

    hic_log("Retry process completed: $attempted attempted, $success_count succeeded, $requeued_count queued for retry, $permanent_count marked as permanent failure");
}

/**
 * Fetch reservation updates from HIC API
 */
function hic_fetch_reservations_updates($prop_id, $since, $limit=null){
    $base = rtrim(Helpers\hic_get_api_url(), '/'); // .../api/partner
    $email = Helpers\hic_get_api_email(); 
    $pass = Helpers\hic_get_api_password();
    if (!$base || !$email || !$pass || !$prop_id) {
        return new WP_Error('hic_missing_conf', 'URL/credenziali/propId mancanti per updates');
    }
    
    // Use centralized timestamp validation
    $validated_since = hic_validate_api_timestamp($since, 'HIC updates fetch');
    
    if ($validated_since !== $since) {
        hic_log("HIC updates fetch: Timestamp adjusted from " . wp_date('Y-m-d H:i:s', $since) . " to " . wp_date('Y-m-d H:i:s', $validated_since));
    }
    
    // Additional safety check for reservations_updates endpoint
    $current_time = time();
    $max_lookback = $current_time - (6 * 86400); // 6 days max for safety
    if ($validated_since < $max_lookback) {
        hic_log("HIC updates fetch: Timestamp too old for updates endpoint (" . wp_date('Y-m-d H:i:s', $validated_since) . "), resetting to 6 days ago");
        $validated_since = $max_lookback;
    }
    
    $endpoint = $base.'/reservations_updates/'.rawurlencode($prop_id);
    $args = array('updated_after' => $validated_since);
    if ($limit) $args['limit'] = $limit;
    $url = add_query_arg($args, $endpoint);
    
    // Log the actual API call for debugging
    hic_log("Reservations Updates API Call: $url");
    
    $res = \FpHic\HIC_HTTP_Security::secure_get($url, array(
      'timeout'=>HIC_API_TIMEOUT,
      'headers'=>array(
        'Authorization'=>'Basic '.base64_encode("$email:$pass"), 
        'Accept'=>'application/json'
      )
    ));
    
    $data = hic_handle_api_response($res, 'HIC updates fetch');
    if (is_wp_error($data)) {
        return $data;
    }

    // Handle new API response format with success/error structure
    $updates = hic_extract_reservations_from_response($data);
    if (is_wp_error($updates)) {
        return $updates;
    }

    // Log di debug iniziale
    hic_log(array('hic_updates_count' => is_array($updates) ? count($updates) : 0));

    // Process each update
    if (is_array($updates)) {
        foreach ($updates as $u) {
            try {
                hic_process_update($u);
            } catch (\Exception $e) {
                hic_log('Process update error: '.$e->getMessage()); 
            }
        }
    }
    
    return $updates;
}

/**
 * Process a single reservation update
 */
function hic_process_update(array $u){
    // Validate input array
    if (!is_array($u) || empty($u)) {
        hic_log('hic_process_update: invalid or empty update array');
        return;
    }
    
    // Get reservation ID with proper validation
    $id = Helpers\hic_extract_reservation_id($u);
    if (empty($id)) {
        hic_log('hic_process_update: missing or invalid reservation id');
        return;
    }

    // Check if this is a new reservation for real-time sync
    $is_new_reservation = hic_is_reservation_new_for_realtime($id);
    if ($is_new_reservation) {
        hic_log("New reservation detected for real-time sync: $id");
        hic_mark_reservation_new_for_realtime($id);
        
        // Process new reservation for real-time Brevo notification
        hic_process_new_reservation_for_realtime($u);
    }

    // Validate and get email
    $email = $u['guest_email']
        ?? $u['email']
        ?? $u['client_email']
        ?? '';
    if (empty($email) || !is_string($email)) {
        hic_log("hic_process_update: no valid email in update for reservation $id");
        return;
    }
    
    $is_alias = Helpers\hic_is_ota_alias_email($email);

    // Se c'è un'email reale nuova che sostituisce un alias
    if (!$is_alias && Helpers\hic_is_valid_email($email)) {
        // upsert Brevo con vera email + liste by language
        $t = hic_transform_reservation($u); // riusa normalizzazioni
        if ($t !== false && is_array($t)) {
            $update_sid = !empty($t['sid']) && is_scalar($t['sid']) ? \sanitize_text_field((string) $t['sid']) : '';
            hic_dispatch_brevo_reservation($t, true, '', '', '', '', '', '', $update_sid); // aggiorna contatto with enrichment flag
            // aggiorna store locale per id -> true_email
            Helpers\hic_mark_email_enriched($id, $email);
            hic_log("Enriched email for reservation $id");
        } else {
            hic_log("hic_process_update: failed to transform reservation $id");
        }
    }

    // Se cambia presence e impostazione consente aggiornamenti:
    if (Helpers\hic_allow_status_updates() && !empty($u['presence'])) {
        hic_log("Reservation $id presence update: ".$u['presence']);
        // opzionale: dispatch evento custom (no purchase)
    }
}

/**
 * Process new reservation for real-time Brevo notification
 */
function hic_process_new_reservation_for_realtime($reservation_data) {
    if (!Helpers\hic_realtime_brevo_sync_enabled()) {
        hic_log('Real-time Brevo sync disabled, skipping new reservation notification');
        return;
    }

    $reservation_id = Helpers\hic_extract_reservation_id($reservation_data);
    if (!$reservation_id) {
        hic_log('Cannot process new reservation: missing reservation ID');
        return;
    }

    // Transform reservation data to standard format
    $transformed = hic_transform_reservation($reservation_data);
    if ($transformed === false || !is_array($transformed)) {
        hic_log("Failed to transform new reservation $reservation_id for real-time sync");
        hic_mark_reservation_notification_failed($reservation_id, 'Transformation failed');
        return;
    }

    $sid = !empty($transformed['sid']) && is_scalar($transformed['sid']) ? \sanitize_text_field((string) $transformed['sid']) : '';
    $transformed['sid'] = $sid;

    // Retrieve tracking IDs for tracking
    $lookup_id = $sid !== '' ? $sid : ($transformed['transaction_id'] ?? '');
    $gclid = '';
    $fbclid = '';
    $msclkid = '';
    $ttclid = '';
    $gbraid = '';
    $wbraid = '';
    if (!empty($lookup_id) && is_scalar($lookup_id)) {
        $tracking = Helpers\hic_get_tracking_ids_by_sid((string) $lookup_id);
        if (is_array($tracking)) {
            $gclid = $tracking['gclid'] ?? '';
            $fbclid = $tracking['fbclid'] ?? '';
            $msclkid = $tracking['msclkid'] ?? '';
            $ttclid = $tracking['ttclid'] ?? '';
            $gbraid = $tracking['gbraid'] ?? '';
            $wbraid = $tracking['wbraid'] ?? '';
        }
    }

    $retry_payload = array(
        'data' => $transformed,
        'gclid' => $gclid,
        'fbclid' => $fbclid,
        'msclkid' => $msclkid,
        'ttclid' => $ttclid,
        'gbraid' => $gbraid,
        'wbraid' => $wbraid,
    );

    // Send reservation_created event to Brevo
    $event_result = hic_send_brevo_reservation_created_event($transformed, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid);

    // Also send/update contact information regardless of event result
    if (Helpers\hic_is_valid_email($transformed['email']) && !Helpers\hic_is_ota_alias_email($transformed['email'])) {
        if (!hic_dispatch_brevo_reservation($transformed, false, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid, $sid)) {
            hic_log("Failed to update Brevo contact for reservation $reservation_id");
        }
    }

    if (is_array($event_result) && $event_result['success']) {
        // Mark as successfully notified
        hic_mark_reservation_notified_to_brevo($reservation_id);
        hic_log("Successfully sent reservation_created event to Brevo for reservation $reservation_id");
    } elseif (is_array($event_result)) {
        // Handle failure based on retryability
        if (!empty($event_result['skipped'])) {
            hic_log("Brevo reservation_created event skipped for reservation $reservation_id: " . $event_result['error']);
        } elseif ($event_result['retryable']) {
            // Mark as failed for retry
            hic_mark_reservation_notification_failed($reservation_id, 'Failed to send Brevo event: ' . $event_result['error'], $retry_payload);
            hic_log("Failed to send reservation_created event to Brevo for reservation $reservation_id (retryable error)");
        } else {
            // Already marked as permanent failure in hic_send_brevo_reservation_created_event
            hic_log("Failed to send reservation_created event to Brevo for reservation $reservation_id (permanent failure)");
        }
    } else {
        hic_log("Brevo reservation_created event returned unexpected result for reservation $reservation_id");
    }
}

/**
 * Test API connection with Basic Auth credentials
 */
if (!function_exists(__NAMESPACE__ . '\\hic_test_api_connection')) {
    function hic_test_api_connection($prop_id = null, $email = null, $password = null) {
        // Use provided credentials or fall back to settings
        $prop_id = $prop_id ?: Helpers\hic_get_property_id();
        $email = $email ?: Helpers\hic_get_api_email();
        $password = $password ?: Helpers\hic_get_api_password();
        $base_url = Helpers\hic_get_api_url();

        // Validate required parameters
        if (empty($base_url)) {
            return array(
                'success' => false,
                'message' => 'API URL mancante. Configura l\'URL delle API Hotel in Cloud.'
            );
        }

        if (empty($prop_id)) {
            return array(
                'success' => false,
                'message' => 'ID Struttura (propId) mancante. Inserisci l\'ID della tua struttura.'
            );
        }

        if (empty($email)) {
            return array(
                'success' => false,
                'message' => 'API Email mancante. Inserisci l\'email del tuo account Hotel in Cloud.'
            );
        }

        if (empty($password)) {
            return array(
                'success' => false,
                'message' => 'API Password mancante. Inserisci la password del tuo account Hotel in Cloud.'
            );
        }

        // Build test endpoint URL
        $base = rtrim($base_url, '/');
        $endpoint = $base . '/reservations/' . rawurlencode($prop_id);

        // Use a small date range to minimize data transfer
        $test_args = array(
            'date_type' => 'checkin',
            'from_date' => wp_date('Y-m-d', strtotime('-7 days', time())),
            'to_date' => wp_date('Y-m-d'),
            'limit' => 1
        );
        $test_url = add_query_arg($test_args, $endpoint);

        // Check for cached response first (for test connections, cache for 5 minutes)
        $cache_key = "api_test_$endpoint" . md5($email . $password);
        $cached_response = \FpHic\HIC_Cache_Manager::get($cache_key);

        if ($cached_response !== null) {
            hic_log('Using cached API test response');
            return $cached_response;
        }

        // Make the API request using secure HTTP
        $response = \FpHic\HIC_HTTP_Security::secure_get($test_url, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode("$email:$password"),
                'Accept' => 'application/json'
            )
        ));

        // Check for connection errors
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Errore di connessione: ' . $response->get_error_message()
            );
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        // Handle different HTTP response codes
        switch ($http_code) {
            case 200:
                // Validate JSON response
                $data = json_decode($response_body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $result = array(
                        'success' => false,
                        'message' => 'Risposta API non valida (JSON malformato)'
                    );
                } else {
                    $result = array(
                        'success' => true,
                        'message' => 'Connessione API riuscita! Credenziali valide.',
                        'data_count' => is_array($data) ? count($data) : 0
                    );
                }

                // Cache successful result for 5 minutes
                \FpHic\HIC_Cache_Manager::set($cache_key, $result, 300);
                return $result;

            case 401:
                return array(
                    'success' => false,
                    'message' => 'Credenziali non valide. Verifica email e password.'
                );

            case 403:
                return array(
                    'success' => false,
                    'message' => 'Accesso negato. L\'account potrebbe non avere permessi per questa struttura.'
                );

            case 404:
                return array(
                    'success' => false,
                    'message' => 'Struttura non trovata. Verifica l\'ID Struttura (propId).'
                );

            case 429:
                return array(
                    'success' => false,
                    'message' => 'Troppe richieste. Riprova tra qualche minuto.'
                );

            case 500:
            case 502:
            case 503:
                return array(
                    'success' => false,
                    'message' => 'Errore del server Hotel in Cloud. Riprova più tardi.'
                );

            default:
                return array(
                    'success' => false,
                    'message' => "Errore HTTP $http_code. Verifica la configurazione."
                );
        }
    }
}

/* ============ BACKFILL FUNCTIONALITY ============ */

/**
 * Backfill reservations for a specific date range
 * 
 * @param string $from_date Date in Y-m-d format
 * @param string $to_date Date in Y-m-d format 
 * @param string $date_type Either 'checkin', 'checkout', or 'presence'
 * @param int $limit Optional limit for number of reservations to fetch
 * @return array Result with success status, message, and statistics
 */
if (!function_exists(__NAMESPACE__ . '\\hic_backfill_reservations')) {
    function hic_backfill_reservations($from_date, $to_date, $date_type = 'checkin', $limit = null) {
        $start_time = microtime(true);
        
        hic_log("Backfill: Starting backfill from $from_date to $to_date (date_type: $date_type, limit: " . ($limit ?: 'none') . ")");
        
        // Validate date_type (based on API documentation: checkin, checkout, presence for /reservations)
        // Note: For recent updates, use hic_fetch_reservations_updates() instead
        if (!in_array($date_type, array('checkin', 'checkout', 'presence'))) {
            return array(
                'success' => false,
                'message' => 'Tipo di data non valido. Deve essere "checkin", "checkout" o "presence". Per aggiornamenti recenti usa la funzione updates.',
                'stats' => array()
            );
        }
        
        // Validate dates
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
            return array(
                'success' => false,
                'message' => 'Formato date non valido. Usa YYYY-MM-DD.',
                'stats' => array()
            );
        }
        
        // Validate date range
        if (strtotime($from_date) > strtotime($to_date)) {
            return array(
                'success' => false,
                'message' => 'La data di inizio deve essere precedente alla data di fine.',
                'stats' => array()
            );
        }
        
        // Check for reasonable date range (max 6 months)
        $date_diff = (strtotime($to_date) - strtotime($from_date)) / 86400;
        if ($date_diff > 180) {
            return array(
                'success' => false,
                'message' => 'Intervallo di date troppo ampio. Massimo 6 mesi.',
                'stats' => array()
            );
        }
        
        // Get API credentials
        $prop_id = Helpers\hic_get_property_id();
        $email = Helpers\hic_get_api_email();
        $password = Helpers\hic_get_api_password();
        
        if (!$prop_id || !$email || !$password) {
            return array(
                'success' => false,
                'message' => 'Credenziali API mancanti. Configura Property ID, Email e Password.',
                'stats' => array()
            );
        }
        
        // Initialize statistics
        $stats = array(
            'total_found' => 0,
            'total_processed' => 0,
            'total_skipped' => 0,
            'total_errors' => 0,
            'execution_time' => 0,
            'date_range' => "$from_date to $to_date",
            'date_type' => $date_type
        );
        
        try {
            // Fetch reservations from API using raw fetch to avoid double processing
            $reservations = hic_fetch_reservations_raw($prop_id, $date_type, $from_date, $to_date, $limit);
            
            if (is_wp_error($reservations)) {
                return array(
                    'success' => false,
                    'message' => 'Errore API: ' . $reservations->get_error_message(),
                    'stats' => $stats
                );
            }
            
            if (!is_array($reservations)) {
                return array(
                    'success' => false,
                    'message' => 'Risposta API non valida.',
                    'stats' => $stats
                );
            }
            
            $stats['total_found'] = count($reservations);
            hic_log("Backfill: Found {$stats['total_found']} reservations");
            
            // Process each reservation
            foreach ($reservations as $reservation) {
                try {
                    // Check if should process (deduplication, validation)
                    if (!hic_should_process_reservation($reservation)) {
                        $stats['total_skipped']++;
                        continue;
                    }
                    
                    // Transform and process the reservation
                    $transformed = hic_transform_reservation($reservation);
                    if ($transformed !== false) {
                        $dispatch_success = hic_dispatch_reservation($transformed, $reservation);
                        if ($dispatch_success) {
                            $stats['total_processed']++;
                        } else {
                            $stats['total_errors']++;
                            $uid = Helpers\hic_booking_uid($reservation);
                            if (!empty($uid)) {
                                hic_log("Backfill: Dispatch failed for reservation $uid");
                            } else {
                                hic_log('Backfill: Dispatch failed for reservation without UID');
                            }
                        }
                    } else {
                        $stats['total_errors']++;
                        hic_log("Backfill: Failed to transform reservation: " . json_encode($reservation));
                    }

                } catch (\Exception $e) {
                    $stats['total_errors']++;
                    hic_log("Backfill: Error processing reservation: " . $e->getMessage());
                }
            }
            
            $stats['execution_time'] = round(microtime(true) - $start_time, 2);
            
            $message = "Backfill completato: {$stats['total_found']} trovate, {$stats['total_processed']} processate, {$stats['total_skipped']} saltate, {$stats['total_errors']} errori in {$stats['execution_time']}s";
            hic_log("Backfill: $message");
            
            return array(
                'success' => true,
                'message' => $message,
                'stats' => $stats
            );
            
        } catch (\Exception $e) {
            $stats['execution_time'] = round(microtime(true) - $start_time, 2);
            $error_message = "Errore durante il backfill: " . $e->getMessage();
            hic_log("Backfill: $error_message");
            
            return array(
                'success' => false,
                'message' => $error_message,
                'stats' => $stats
            );
        }
    }
}

/**
 * Raw fetch function that doesn't process reservations (for backfill use)
 */
if (!function_exists(__NAMESPACE__ . '\\hic_fetch_reservations_raw')) {
    function hic_fetch_reservations_raw($prop_id, $date_type, $from_date, $to_date, $limit = null) {
        $base = rtrim(Helpers\hic_get_api_url(), '/');
        $email = Helpers\hic_get_api_email();
        $pass = Helpers\hic_get_api_password();

        if (!$base || !$email || !$pass || !$prop_id) {
            return new WP_Error('hic_missing_conf', 'URL/credenziali/propId mancanti');
        }

        // Use standard /reservations/ endpoint - only valid date_type values supported
        $endpoint = $base . '/reservations/' . rawurlencode($prop_id);
        $args = array('date_type' => $date_type, 'from_date' => $from_date, 'to_date' => $to_date);
        if ($limit) $args['limit'] = (int)$limit;
        $url = add_query_arg($args, $endpoint);

        hic_log("Backfill Raw API Call (Reservations endpoint): $url");

        $request_args = array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode("$email:$pass"),
            ),
        );

        $res = Helpers\hic_http_request($url, $request_args);

        $data = hic_handle_api_response($res, 'Backfill Raw API call');
        if (is_wp_error($data)) {
            return $data;
        }

        // Handle new API response format with success/error structure
        $reservations = hic_extract_reservations_from_response($data);
        if (is_wp_error($reservations)) {
            return $reservations;
        }

        return $reservations;
    }
}

/**
 * Continuous polling function - runs every 30 seconds
 * Focuses on recent reservations and manual bookings
 */
if (!function_exists(__NAMESPACE__ . '\\hic_api_poll_bookings_continuous')) {
function hic_api_poll_bookings_continuous() {
    $start_time = microtime(true);
    hic_log('Continuous Polling: Starting 30-second interval check');
    
    // Check circuit breaker
    $circuit_breaker_until = (int) get_option('hic_circuit_breaker_until', 0);
    if ($circuit_breaker_until > time()) {
        $wait_time = $circuit_breaker_until - time();
        hic_log("Continuous Polling: Circuit breaker active, waiting $wait_time more seconds");
        
        // If circuit breaker timeout has passed, try to restart with fresh timestamps
        if ($wait_time <= 0) {
            hic_log("Continuous Polling: Circuit breaker timeout expired, resetting for restart");
            delete_option('hic_circuit_breaker_until');
            hic_reset_all_polling_timestamps('Circuit breaker timeout reset');
        }
        
        return false;
    }
    
    // Try to acquire lock to prevent overlapping executions
    if (!Helpers\hic_acquire_polling_lock(120)) { // 2-minute timeout for continuous polling
        hic_log('Continuous Polling: Another polling process is running, skipping execution');
        return false;
    }

    try {
        $prop_id = Helpers\hic_get_property_id();
        if (empty($prop_id)) {
            hic_log('Continuous Polling: No property ID configured');
            return new WP_Error('hic_missing_prop_id', 'No property ID configured');
        }
        
        $current_time = time();
        
        // Check recent reservations (last 2 hours) to catch new bookings and updates
        $window_back_minutes = 120; // 2 hours back
        $window_forward_minutes = 5; // 5 minutes forward
        
        $from_time = $current_time - ($window_back_minutes * 60);
        $to_time = $current_time + ($window_forward_minutes * 60);
        
        $from_date = wp_date('Y-m-d H:i:s', $from_time);
        $to_date = wp_date('Y-m-d H:i:s', $to_time);
        
        hic_log("Continuous Polling: Checking window from $from_date to $to_date (property: $prop_id)");
        
        $total_new = 0;
        $total_skipped = 0;
        $total_errors = 0;
        $polling_errors = array();
        
        // Check for new and updated reservations using /reservations_updates endpoint
        // This is the most effective way to catch recently created/modified reservations
        $max_lookback_seconds = 6 * 86400; // 6 days for safety margin
        $earliest_allowed = $current_time - $max_lookback_seconds;
        $default_check = max($earliest_allowed, $current_time - 7200); // Default to 2 hours ago or earliest allowed
        
        $last_continuous_check = get_option('hic_last_continuous_check', $default_check);
        
        // Use centralized timestamp validation
        $validated_check = hic_validate_api_timestamp($last_continuous_check, 'Continuous Polling');
        
        // Update stored timestamp if it was adjusted
        if ($validated_check !== $last_continuous_check) {
            update_option('hic_last_continuous_check', $validated_check, false);
            Helpers\hic_clear_option_cache('hic_last_continuous_check');
            hic_log("Continuous Polling: Updated stored timestamp from " . wp_date('Y-m-d H:i:s', $last_continuous_check) . " to " . wp_date('Y-m-d H:i:s', $validated_check));
            $last_continuous_check = $validated_check;
        }
        
        hic_log("Continuous Polling: Checking for updates since " . wp_date('Y-m-d H:i:s', $last_continuous_check));
        $updated_reservations = hic_fetch_reservations_updates($prop_id, $last_continuous_check, 50);
        
        if (!is_wp_error($updated_reservations)) {
            $updated_count = is_array($updated_reservations) ? count($updated_reservations) : 0;
            if ($updated_count > 0) {
                hic_log("Continuous Polling: Found $updated_count updated/new reservations");
                $process_result = hic_process_reservations_batch($updated_reservations);
                $total_new += $process_result['new'];
                $total_skipped += $process_result['skipped'];
                $total_errors += $process_result['errors'];
            }
            
            // Update the last check timestamp
            update_option('hic_last_continuous_check', $current_time, false);
            Helpers\hic_clear_option_cache('hic_last_continuous_check');
            
            // Reset consecutive error counter on successful polling
            update_option('hic_consecutive_update_errors', 0, false);
        } else {
            $error_message = $updated_reservations->get_error_message();
            hic_log("Continuous Polling: Error checking for updates: " . $error_message);
            $polling_errors[] = 'updates polling: ' . $error_message;
            $total_errors++;
            
            // Track consecutive errors to prevent infinite loops
            $consecutive_errors = (int) get_option('hic_consecutive_update_errors', 0);
            $consecutive_errors++;
            update_option('hic_consecutive_update_errors', $consecutive_errors, false);
            
            // Check if this is a timestamp too old error and reset if necessary
            if ($updated_reservations->get_error_code() === 'hic_timestamp_too_old') {
                hic_log("Continuous Polling: Timestamp error detected (consecutive errors: $consecutive_errors)");
                
                // Use more conservative reset - go back 3 days to ensure we're well within limits
                $reset_timestamp = $current_time - (3 * 86400); // Reset to 3 days ago for continuous polling
                
                // Validate the reset timestamp before using it
                $validated_reset = hic_validate_api_timestamp($reset_timestamp, 'Continuous Polling timestamp reset');
                
                update_option('hic_last_continuous_check', $validated_reset, false);
                Helpers\hic_clear_option_cache('hic_last_continuous_check');
                hic_log('Continuous Polling: Timestamp error detected, reset timestamp to: ' . wp_date('Y-m-d H:i:s', $validated_reset) . " ($validated_reset)");
                
                // Also reset scheduler timestamps to restart polling immediately with safe values
                $recent_timestamp = $current_time - 300; // 5 minutes ago
                $validated_recent = hic_validate_api_timestamp($recent_timestamp, 'Continuous Polling scheduler restart');
                
                update_option('hic_last_continuous_poll', $validated_recent, false);
                Helpers\hic_clear_option_cache('hic_last_continuous_poll');
                update_option('hic_last_deep_check', $validated_recent, false);
                Helpers\hic_clear_option_cache('hic_last_deep_check');
                hic_log('Continuous Polling: Reset scheduler timestamps to restart polling: ' . wp_date('Y-m-d H:i:s', $validated_recent));
                
                // Reset error counter after successful timestamp reset
                update_option('hic_consecutive_update_errors', 0, false);
            } else if ($consecutive_errors >= 3) {
                // Circuit breaker: after 3 consecutive errors, force a more aggressive recovery
                hic_log("Continuous Polling: Circuit breaker triggered after $consecutive_errors consecutive errors - forcing full timestamp reset");
                
                // Use the new centralized reset function
                hic_reset_all_polling_timestamps("Circuit breaker after $consecutive_errors consecutive errors");
                
                // Set circuit breaker with 5 minute delay
                update_option('hic_circuit_breaker_until', $current_time + 300, false); // 5 minute delay
                
                hic_log("Continuous Polling: Circuit breaker activated - polling will resume after 5 minutes");
            }
        }
        
        // Also check by check-in date for today and tomorrow (catch modifications)
        $checkin_from = wp_date('Y-m-d', $current_time);
        $checkin_to = wp_date('Y-m-d', $current_time + 86400);
        
        $checkin_reservations = hic_fetch_reservations_raw($prop_id, 'checkin', $checkin_from, $checkin_to, 30);

        if (!is_wp_error($checkin_reservations)) {
            $checkin_count = is_array($checkin_reservations) ? count($checkin_reservations) : 0;
            if ($checkin_count > 0) {
                hic_log("Continuous Polling: Found $checkin_count reservations by checkin date");
                $process_result = hic_process_reservations_batch($checkin_reservations);
                $total_new += $process_result['new'];
                $total_skipped += $process_result['skipped'];
                $total_errors += $process_result['errors'];
            }
        } else {
            $error_message = $checkin_reservations->get_error_message();
            hic_log("Continuous Polling: Error fetching reservations by checkin date: " . $error_message);
            $polling_errors[] = 'checkin date polling: ' . $error_message;
            $total_errors++;
        }
        
        $execution_time = round((microtime(true) - $start_time) * 1000, 2);
        
        // Store metrics for diagnostics
        update_option('hic_last_continuous_poll_count', $total_new, false);
        Helpers\hic_clear_option_cache('hic_last_continuous_poll_count');
        update_option('hic_last_continuous_poll_duration', $execution_time, false);
        Helpers\hic_clear_option_cache('hic_last_continuous_poll_duration');

        // Update last successful poll timestamp if polling had no errors or found new bookings
        if ($total_errors === 0 || $total_new > 0) {
            update_option('hic_last_successful_poll', $current_time, false);
            Helpers\hic_clear_option_cache('hic_last_successful_poll');
        }

        hic_log("Continuous Polling: Completed in {$execution_time}ms - New: $total_new, Skipped: $total_skipped, Errors: $total_errors");

        if ($total_errors > 0) {
            $error_message = 'Errors occurred during continuous polling';

            if (!empty($polling_errors)) {
                $error_message .= ': ' . implode('; ', $polling_errors);
                
                // Log detailed recovery information to help with debugging
                hic_log("Continuous Polling: Recovery: Attempting recovery from errors");
                $circuit_breaker_active = get_option('hic_circuit_breaker_until', 0) > time();
                $consecutive_errors = (int) get_option('hic_consecutive_update_errors', 0);
                $last_successful = get_option('hic_last_successful_poll', 0);
                
                hic_log("Continuous Polling: Recovery: Circuit breaker active: " . ($circuit_breaker_active ? 'YES' : 'NO'));
                hic_log("Continuous Polling: Recovery: Consecutive errors: $consecutive_errors");
                hic_log("Continuous Polling: Recovery: Last successful poll: " . ($last_successful ? wp_date('Y-m-d H:i:s', $last_successful) : 'NEVER'));
                hic_log("Continuous Polling: Recovery: Completed recovery attempt for continuous polling");
                
                return new WP_Error('hic_polling_errors', $error_message, $polling_errors);
            }

            return new WP_Error('hic_polling_errors', $error_message);
        }

        return true;

    } catch (\Throwable $e) {
        hic_log('Continuous Polling exception: ' . $e->getMessage());
        return new WP_Error('hic_polling_exception', $e->getMessage());
    } finally {
        Helpers\hic_release_polling_lock();
    }
}
}

/**
 * Deep check polling function - runs every 30 minutes
 * Looks back 5 days to catch any missed reservations
 */
if (!function_exists(__NAMESPACE__ . '\\hic_api_poll_bookings_deep_check')) {
function hic_api_poll_bookings_deep_check() {
    $start_time = microtime(true);
    hic_log('Deep Check: Starting 30-minute interval deep check (5-day lookback)');
    
    // Try to acquire lock to prevent overlapping executions
    if (!Helpers\hic_acquire_polling_lock(1800)) { // 30-minute timeout for deep check
        hic_log('Deep Check: Another polling process is running, skipping execution');
        return;
    }
    
    try {
        $prop_id = Helpers\hic_get_property_id();
        if (empty($prop_id)) {
            hic_log('Deep Check: No property ID configured');
            return;
        }
        
        $current_time = time();
        $lookback_seconds = 5 * 86400; // 5 days
        
        $from_date = wp_date('Y-m-d', $current_time - $lookback_seconds);
        $to_date = wp_date('Y-m-d', $current_time);
        
        hic_log("Deep Check: Searching 5-day window from $from_date to $to_date (property: $prop_id)");
        
        $total_new = 0;
        $total_skipped = 0;
        $total_errors = 0;
        
        // Check for updates in the last 5 days using /reservations_updates endpoint
        // This catches any modifications or new reservations that might have been missed
        $max_lookback_seconds = 6 * 86400; // 6 days for safety margin
        $safe_lookback_seconds = min($lookback_seconds, $max_lookback_seconds); // Ensure we don't exceed API limits
        $lookback_timestamp = $current_time - $safe_lookback_seconds;
        
        if ($safe_lookback_seconds < $lookback_seconds) {
            hic_log("Deep Check: Reduced lookback from " . ($lookback_seconds / 86400) . " days to " . ($safe_lookback_seconds / 86400) . " days due to API limits");
        }
        
        hic_log("Deep Check: Checking for updates since " . wp_date('Y-m-d H:i:s', $lookback_timestamp));
        $updated_reservations = hic_fetch_reservations_updates($prop_id, $lookback_timestamp, 100);
        
        if (!is_wp_error($updated_reservations)) {
            $updated_count = is_array($updated_reservations) ? count($updated_reservations) : 0;
            hic_log("Deep Check: Found $updated_count updated/new reservations in 5-day window");
            
            if ($updated_count > 0) {
                $process_result = hic_process_reservations_batch($updated_reservations);
                $total_new += $process_result['new'];
                $total_skipped += $process_result['skipped'];
                $total_errors += $process_result['errors'];
            }
        } else {
            $error_message = $updated_reservations->get_error_message();
            hic_log("Deep Check: Error checking updates: " . $error_message);
            $total_errors++;
            
            // Handle timestamp errors properly to prevent polling from getting stuck
            if ($updated_reservations->get_error_code() === 'hic_timestamp_too_old') {
                hic_log('Deep Check: Timestamp error detected - resetting all relevant timestamps to recover');

                // Reset all relevant timestamps to a safe value
                $safe_timestamp   = $current_time - (3 * 86400); // 3 days ago
                $validated_safe   = hic_validate_api_timestamp($safe_timestamp, 'Deep Check timestamp recovery');

                $options_to_reset = [
                    'hic_last_updates_since',
                    'hic_last_update_check',
                    'hic_last_continuous_check',
                    'hic_last_continuous_poll',
                    'hic_last_deep_check',
                ];

                foreach ($options_to_reset as $option) {
                    update_option($option, $validated_safe, false);
                    Helpers\hic_clear_option_cache($option);
                }

                hic_log('Timestamp recovery completed – polling restarted');

                // Reduce error count since this is a recoverable error that's now handled
                $total_errors--;
            }
        }
        
        // Also check check-in dates for the next 7 days (catch future reservations that might have been missed)
        $checkin_from = wp_date('Y-m-d', $current_time);
        $checkin_to = wp_date('Y-m-d', $current_time + (7 * 86400));
        
        hic_log("Deep Check: Checking by checkin date from $checkin_from to $checkin_to");
        $checkin_reservations = hic_fetch_reservations_raw($prop_id, 'checkin', $checkin_from, $checkin_to, 100);
        
        if (!is_wp_error($checkin_reservations)) {
            $checkin_count = is_array($checkin_reservations) ? count($checkin_reservations) : 0;
            if ($checkin_count > 0) {
                hic_log("Deep Check: Found $checkin_count reservations by checkin date");
                $process_result = hic_process_reservations_batch($checkin_reservations);
                $total_new += $process_result['new'];
                $total_skipped += $process_result['skipped'];
                $total_errors += $process_result['errors'];
            }
        } else {
            hic_log("Deep Check: Error checking checkin reservations: " . $checkin_reservations->get_error_message());
        }
        
        $execution_time = round((microtime(true) - $start_time) * 1000, 2);
        
        // Store metrics for diagnostics
        update_option('hic_last_deep_check_count', $total_new, false);
        Helpers\hic_clear_option_cache('hic_last_deep_check_count');
        update_option('hic_last_deep_check_duration', $execution_time, false);
        Helpers\hic_clear_option_cache('hic_last_deep_check_duration');
        
        // Update last successful deep check if no errors
        if ($total_errors === 0) {
            update_option('hic_last_successful_deep_check', $current_time, false);
            Helpers\hic_clear_option_cache('hic_last_successful_deep_check');

            update_option('hic_last_successful_poll', $current_time, false);
            Helpers\hic_clear_option_cache('hic_last_successful_poll');
        }
        
        hic_log("Deep Check: Completed in {$execution_time}ms - Window: $from_date to $to_date, New: $total_new, Skipped: $total_skipped, Errors: $total_errors");
        
    } finally {
        Helpers\hic_release_polling_lock();
    }
}
}

