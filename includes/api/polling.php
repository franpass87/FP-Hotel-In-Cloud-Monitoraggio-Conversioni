<?php
/**
 * API Polling Handler - Core API Functions Only
 * Note: WP-Cron scheduling removed in favor of internal scheduler (booking-poller.php)
 */

if (!defined('ABSPATH')) exit;

// Define constants for better maintainability
define('HIC_POLL_INTERVAL_SECONDS', 300);  // 5 minutes
define('HIC_RETRY_INTERVAL_SECONDS', 900); // 15 minutes

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
        // Check for specific timestamp error
        if (strpos($body, 'timestamp can\'t be older than seven days') !== false || 
            strpos($body, 'the timestamp can\'t be older than seven days') !== false) {
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
// Note: WP-Cron scheduling has been removed in favor of the internal scheduler 
// in booking-poller.php. This file now contains only core API functions.

/**
 * Chiama HIC: GET /reservations/{propId} o /reservations_updates/{propId}
 */
function hic_fetch_reservations($prop_id, $date_type, $from_date, $to_date, $limit = null){
    $base = rtrim(hic_get_api_url(), '/'); // es: https://api.hotelincloud.com/api/partner
    $email = hic_get_api_email();
    $pass  = hic_get_api_password();
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

    $res = wp_remote_get($url, array(
        'timeout' => 30,
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode("$email:$pass"),
            'Accept'        => 'application/json',
            'User-Agent'    => 'WP/FP-HIC-Plugin'
        ),
    ));
    
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
    if (is_array($reservations)) {
        foreach ($reservations as $reservation) {
            try {
                if (hic_should_process_reservation($reservation)) {
                    $transformed = hic_transform_reservation($reservation);
                    if ($transformed !== false) {
                        hic_dispatch_reservation($transformed, $reservation);
                        hic_mark_reservation_processed($reservation);
                        $processed_count++;
                    }
                }
            } catch (Exception $e) { 
                hic_log('Process reservation error: '.$e->getMessage()); 
            }
        }
        if (count($reservations) > 0) {
            hic_log("Processed $processed_count out of " . count($reservations) . " reservations (duplicates/invalid skipped)");
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
    $critical_fields = ['from_date', 'to_date'];
    foreach ($critical_fields as $field) {
        if (empty($reservation[$field])) {
            hic_log("Reservation skipped: missing critical field '$field'");
            return false;
        }
    }
    
    // Check for any valid ID field (more flexible than requiring specific 'id' field)
    $uid = hic_booking_uid($reservation);
    if (empty($uid)) {
        hic_log("Reservation skipped: no valid ID field found (tried: id, reservation_id, booking_id, transaction_id)");
        return false;
    }
    
    // Log warnings for missing optional data but don't block processing
    $optional_fields = ['accommodation_id', 'accommodation_name'];
    foreach ($optional_fields as $field) {
        if (empty($reservation[$field])) {
            hic_log("Reservation $uid: Warning - missing optional field '$field', using defaults");
        }
    }
    
    // Check valid flag
    $valid = isset($reservation['valid']) ? intval($reservation['valid']) : 1;
    if ($valid === 0 && !hic_process_invalid()) {
        hic_log("Reservation $uid skipped: valid=0 and process_invalid=false");
        return false;
    }
    
    // Check deduplication
    if (hic_is_reservation_already_processed($uid)) {
        // Check if status update is allowed
        if (hic_allow_status_updates()) {
            $presence = $reservation['presence'] ?? '';
            if (in_array($presence, ['arrived', 'departed'])) {
                hic_log("Reservation $uid: status update allowed for presence=$presence");
                return true;
            }
        }
        hic_log("Reservation $uid already processed, skipping");
        return false;
    }
    
    return true;
}

/**
 * Transform reservation data to standardized format
 */
function hic_transform_reservation($reservation) {
    $currency = hic_get_currency();
    $price = hic_normalize_price(isset($reservation['price']) ? $reservation['price'] : 0);
    $unpaid_balance = hic_normalize_price(isset($reservation['unpaid_balance']) ? $reservation['unpaid_balance'] : 0);
    
    // Calculate value (use net value if configured)
    $value = $price;
    if (hic_use_net_value() && $unpaid_balance > 0) {
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
    $transaction_id = hic_booking_uid($reservation);
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
    
    return array(
        'transaction_id' => $transaction_id,
        'reservation_code' => isset($reservation['reservation_code']) ? $reservation['reservation_code'] : '',
        'value' => $value,
        'currency' => $currency,
        'accommodation_id' => $accommodation_id,
        'accommodation_name' => $accommodation_name,
        'room_name' => isset($reservation['room_name']) ? $reservation['room_name'] : '',
        'guests' => $guests,
        'from_date' => isset($reservation['from_date']) ? $reservation['from_date'] : '',
        'to_date' => isset($reservation['to_date']) ? $reservation['to_date'] : '',
        'presence' => isset($reservation['presence']) ? $reservation['presence'] : '',
        'unpaid_balance' => $unpaid_balance,
        'guest_first_name' => isset($reservation['guest_first_name']) ? $reservation['guest_first_name'] : '',
        'guest_last_name' => isset($reservation['guest_last_name']) ? $reservation['guest_last_name'] : '',
        'email' => isset($reservation['email']) ? $reservation['email'] : '',
        'phone' => isset($reservation['phone']) ? $reservation['phone'] : '',
        'language' => $language,
        'original_price' => $price
    );
}

/**
 * Dispatch transformed reservation to all services
 */
function hic_dispatch_reservation($transformed, $original) {
    $uid = hic_booking_uid($original);
    $is_status_update = hic_is_reservation_already_processed($uid);
    
    // Debug log to verify fixes are in place
    $realtime_enabled = hic_realtime_brevo_sync_enabled();
    $connection_type = hic_get_connection_type();
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
        // GA4 - only send once unless it's a status update we want to track
        if (!$is_status_update) {
            hic_dispatch_ga4_reservation($transformed);
        }
        
        // Meta Pixel - only send once unless it's a status update we want to track  
        if (!$is_status_update) {
            hic_dispatch_pixel_reservation($transformed);
        }
        
        // Brevo - handle differently based on connection type to prevent duplication
        if ($connection_type === 'webhook') {
            // In webhook mode, only update contact info but don't send events 
            // (events are handled by webhook processor)
            hic_dispatch_brevo_reservation($transformed);
        } else {
            // In polling mode, handle both contact and events
            hic_dispatch_brevo_reservation($transformed);
            
            // Brevo real-time events - send reservation_created event for new reservations
            if (!$is_status_update && hic_realtime_brevo_sync_enabled()) {
                hic_send_brevo_reservation_created_event($transformed);
            }
        }
        
        hic_log("Reservation $uid dispatched successfully (mode: $connection_type)");
    } catch (Exception $e) {
        hic_log("Error dispatching reservation $uid: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Deduplication functions
 */
function hic_is_reservation_already_processed($uid) {
    if (empty($uid)) return false;
    $synced = get_option('hic_synced_res_ids', array());
    return in_array($uid, $synced);
}

function hic_mark_reservation_processed($reservation) {
    $uid = hic_booking_uid($reservation);
    if (empty($uid)) return;
    
    $synced = get_option('hic_synced_res_ids', array());
    if (!in_array($uid, $synced)) {
        $synced[] = $uid;
        
        // Keep only last 10k entries (FIFO)
        if (count($synced) > 10000) {
            $synced = array_slice($synced, -10000);
        }
        
        update_option('hic_synced_res_ids', $synced, false); // autoload=false
    }
}

// Wrapper function - now simplified to use continuous polling by default
function hic_api_poll_bookings(){
    $start_time = microtime(true);
    hic_log('Internal Scheduler: hic_api_poll_bookings execution started');
    
    // Rotate log if needed
    hic_rotate_log_if_needed();
    
    // Use the new simplified continuous polling
    hic_api_poll_bookings_continuous();
    
    $execution_time = round((microtime(true) - $start_time) * 1000, 2);
    hic_log("Internal Scheduler: hic_api_poll_bookings completed in {$execution_time}ms");
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
    
    $from_date = date('Y-m-d H:i:s', $from_time);
    $to_date = date('Y-m-d H:i:s', $to_time);
    
    hic_log("Internal Scheduler: Moving window polling from $from_date to $to_date (property: $prop_id)");
    
    $total_new = 0;
    $total_skipped = 0;
    $total_errors = 0;
    $polling_errors = array();
    
        // Check for new and updated reservations using /reservations_updates endpoint
        // This catches all recently created or modified reservations
        $max_lookback_seconds = 6 * DAY_IN_SECONDS; // 6 days for safety margin
        $earliest_allowed = $current_time - $max_lookback_seconds;
        $default_check = max($earliest_allowed, $current_time - 7200); // Default to 2 hours ago or earliest allowed
        
        $last_update_check = get_option('hic_last_update_check', $default_check);
        
        // Validate that the stored timestamp is not too old
        if ($last_update_check < $earliest_allowed) {
            hic_log("Quasi-realtime Poll: Stored timestamp too old (" . date('Y-m-d H:i:s', $last_update_check) . "), resetting to earliest allowed: " . date('Y-m-d H:i:s', $earliest_allowed));
            $last_update_check = $earliest_allowed;
            update_option('hic_last_update_check', $last_update_check);
        }
        
        hic_log("Internal Scheduler: Checking for updates since " . date('Y-m-d H:i:s', $last_update_check));
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
            update_option('hic_last_update_check', $current_time);
        } else {
            $error_message = $updated_reservations->get_error_message();
            $polling_errors[] = "updates polling: " . $error_message;
            $total_errors++;
            
            // Check if this is a timestamp too old error and reset if necessary
            if ($updated_reservations->get_error_code() === 'hic_timestamp_too_old') {
                $reset_timestamp = $current_time - (2 * 60 * 60); // Reset to 2 hours ago
                update_option('hic_last_update_check', $reset_timestamp);
                hic_log('Quasi-realtime Poll: Timestamp error detected, reset timestamp to: ' . date('Y-m-d H:i:s', $reset_timestamp) . " ($reset_timestamp)");
                
                // Also reset scheduler timestamps to restart polling immediately
                update_option('hic_last_continuous_poll', 0);
                update_option('hic_last_deep_check', 0);
                hic_log('Quasi-realtime Poll: Reset scheduler timestamps to restart polling');
            }
        }
    
    // Also poll by checkin date to catch any updates to existing bookings
    $checkin_from = date('Y-m-d', $from_time);
    $checkin_to = date('Y-m-d', $to_time + (7 * DAY_IN_SECONDS)); // Extend checkin window
    
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
    update_option('hic_last_poll_count', $total_new);
    update_option('hic_last_poll_skipped', $total_skipped);
    update_option('hic_last_poll_duration', $execution_time);
    
    // Update last successful run only if polling was successful
    $polling_successful = empty($polling_errors) && $total_errors === 0;
    if ($polling_successful) {
        update_option('hic_last_successful_poll', $current_time);
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
    $new_count = 0;
    $skipped_count = 0;
    $error_count = 0;
    
    if (!is_array($reservations)) {
        return array('new' => 0, 'skipped' => 0, 'errors' => 1);
    }
    
    foreach ($reservations as $reservation) {
        try {
            // Apply minimal filters first
            if (!hic_should_process_reservation_with_email($reservation)) {
                $skipped_count++;
                continue;
            }
            
            // Check deduplication
            $uid = hic_booking_uid($reservation);
            if (hic_is_reservation_already_processed($uid)) {
                // Check if status update is allowed
                if (hic_allow_status_updates()) {
                    $presence = $reservation['presence'] ?? '';
                    if (in_array($presence, ['arrived', 'departed'])) {
                        hic_log("Reservation $uid: processing status update for presence=$presence");
                        // Process as status update but don't count as new
                        hic_process_single_reservation($reservation);
                        continue;
                    }
                }
                $skipped_count++;
                hic_log("Reservation $uid: skipped (already processed)");
                continue;
            }
            
            // Process new reservation
            hic_process_single_reservation($reservation);
            hic_mark_reservation_processed($reservation);
            $new_count++;
            
        } catch (Exception $e) {
            $error_count++;
            hic_log("Error processing reservation: " . $e->getMessage());
        }
    }
    
    return array('new' => $new_count, 'skipped' => $skipped_count, 'errors' => $error_count);
}

/**
 * Enhanced filtering for reservations (includes email check)
 */
function hic_should_process_reservation_with_email($reservation) {
    // Use existing validation logic
    if (!hic_should_process_reservation($reservation)) {
        return false;
    }
    
    // Additional check: Skip reservations without email (minimal filter)
    if (empty($reservation['email']) || !is_string($reservation['email'])) {
        hic_log("Reservation skipped: missing or invalid email");
        return false;
    }
    
    return true;
}

/**
 * Process a single reservation (transform and dispatch)
 */
function hic_process_single_reservation($reservation) {
    $transformed = hic_transform_reservation($reservation);
    if ($transformed !== false && is_array($transformed)) {
        hic_dispatch_reservation($transformed, $reservation);
    } else {
        throw new Exception("Failed to transform reservation " . ($reservation['id'] ?? 'unknown'));
    }
}

/**
 * New updates polling wrapper function
 */
function hic_api_poll_updates(){
    hic_log('Internal Scheduler: hic_api_poll_updates execution started');
    
    // Always update execution timestamp regardless of results
    update_option('hic_last_api_poll', time());
    
    $prop = hic_get_property_id();
    
    // Add safety overlap to prevent gaps between polling intervals
    $overlap_seconds = 300; // 5 minute overlap for safety
    
    // Ensure we never use a timestamp older than 6 days (API limit is 7 days)
    $current_time = time();
    $max_lookback_seconds = 6 * DAY_IN_SECONDS; // 6 days for safety margin
    $earliest_allowed = $current_time - $max_lookback_seconds;
    $default_since = max($earliest_allowed, $current_time - DAY_IN_SECONDS); // Default to 1 day ago or earliest allowed
    
    $last_since = get_option('hic_last_updates_since', $default_since);
    
    // Validate that the stored timestamp is not too old
    if ($last_since < $earliest_allowed) {
        hic_log("Internal Scheduler: Stored timestamp too old (" . date('Y-m-d H:i:s', $last_since) . "), resetting to earliest allowed: " . date('Y-m-d H:i:s', $earliest_allowed));
        $last_since = $earliest_allowed;
        update_option('hic_last_updates_since', $last_since);
    }
    
    $since = max(0, $last_since - $overlap_seconds);
    
    hic_log("Internal Scheduler: polling updates for property $prop");
    hic_log("Internal Scheduler: last timestamp: " . date('Y-m-d H:i:s', $last_since) . " ($last_since)");
    hic_log("Internal Scheduler: requesting since: " . date('Y-m-d H:i:s', $since) . " ($since) [overlap: {$overlap_seconds}s]");
    
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
                hic_log("Internal Scheduler: Using max updated_at timestamp: " . date('Y-m-d H:i:s', $new_timestamp) . " ($new_timestamp)");
            } else {
                hic_log("Internal Scheduler: No updated_at field found, using current time: " . date('Y-m-d H:i:s', $new_timestamp) . " ($new_timestamp)");
            }
        } else {
            // No updates found - advance timestamp only if enough time has passed to prevent infinite polling
            $time_since_last_poll = $current_time - $last_since;
            if ($time_since_last_poll > 3600) { // 1 hour
                hic_log("Internal Scheduler: No updates found but 1+ hour passed, advancing timestamp to prevent infinite polling");
            } else {
                // Keep previous timestamp for retry, but with small increment to avoid exact same request
                $new_timestamp = $last_since + 60; // Advance by 1 minute to make progress
                hic_log("Internal Scheduler: No updates found, advancing by 1 minute for next retry: " . date('Y-m-d H:i:s', $new_timestamp) . " ($new_timestamp)");
            }
        }
        
        update_option('hic_last_updates_since', $new_timestamp);
        hic_log('Internal Scheduler: hic_api_poll_updates completed successfully');
    } else {
        $error_message = $out->get_error_message();
        hic_log('Internal Scheduler: hic_api_poll_updates failed: ' . $error_message);
        
        // Check if this is a timestamp too old error and reset if necessary
        if ($out->get_error_code() === 'hic_timestamp_too_old') {
            $reset_timestamp = $current_time - (3 * DAY_IN_SECONDS); // Reset to 3 days ago
            update_option('hic_last_updates_since', $reset_timestamp);
            hic_log('Internal Scheduler: Timestamp error detected, reset timestamp to: ' . date('Y-m-d H:i:s', $reset_timestamp) . " ($reset_timestamp)");
            
            // Also reset scheduler timestamps to restart polling immediately
            update_option('hic_last_continuous_poll', 0);
            update_option('hic_last_deep_check', 0);
            hic_log('Internal Scheduler: Reset scheduler timestamps to restart polling');
        }
    }
}

/**
 * Retry failed Brevo notifications
 */
function hic_retry_failed_brevo_notifications() {
    if (!hic_realtime_brevo_sync_enabled()) {
        hic_log('Real-time Brevo sync disabled, skipping retry');
        return;
    }

    hic_log('Internal Scheduler: hic_retry_failed_brevo_notifications execution started');
    
    // Get failed reservations that need retry
    $failed_reservations = hic_get_failed_reservations_for_retry(3, 30); // max 3 attempts, 30 min delay
    
    if (empty($failed_reservations)) {
        hic_log('No failed reservations to retry');
        return;
    }
    
    $retry_count = 0;
    $success_count = 0;
    
    foreach ($failed_reservations as $failed) {
        $reservation_id = $failed->reservation_id;
        $retry_count++;
        
        hic_log("Retrying failed notification for reservation $reservation_id (attempt " . ($failed->attempt_count + 1) . ")");
        
        // Try to get reservation data from API for retry
        // For now, we'll use a simplified approach and just mark as failed after max attempts
        // In a real implementation, you might want to store the original reservation data
        
        // For this implementation, we'll mark as permanently failed after max attempts
        if ($failed->attempt_count >= 2) { // 3rd attempt
            hic_mark_reservation_notification_failed($reservation_id, 'Max retry attempts reached');
            hic_log("Reservation $reservation_id marked as permanently failed after max retry attempts");
        } else {
            // Increment attempt count but keep in failed state for next retry
            hic_mark_reservation_notification_failed($reservation_id, 'Retry attempt failed');
            hic_log("Reservation $reservation_id retry failed, will try again later");
        }
    }
    
    hic_log("Retry process completed: $retry_count attempted, $success_count succeeded");
}

/**
 * Fetch reservation updates from HIC API
 */
function hic_fetch_reservations_updates($prop_id, $since, $limit=null){
    $base = rtrim(hic_get_api_url(), '/'); // .../api/partner
    $email = hic_get_api_email(); 
    $pass = hic_get_api_password();
    if (!$base || !$email || !$pass || !$prop_id) {
        return new WP_Error('hic_missing_conf', 'URL/credenziali/propId mancanti per updates');
    }
    
    // Validate timestamp - API rejects timestamps older than 7 days
    $current_time = time();
    $max_lookback_seconds = 6 * DAY_IN_SECONDS; // 6 days for safety margin
    $earliest_allowed = $current_time - $max_lookback_seconds;
    
    if ($since < $earliest_allowed) {
        $original_since = $since;
        $since = $earliest_allowed;
        hic_log("HIC API timestamp validation: Capped timestamp from " . 
                date('Y-m-d H:i:s', $original_since) . " to " . 
                date('Y-m-d H:i:s', $since) . " (6-day limit)");
    }
    
    $endpoint = $base.'/reservations_updates/'.rawurlencode($prop_id);
    $args = array('updated_after' => $since);
    if ($limit) $args['limit'] = $limit;
    $url = add_query_arg($args, $endpoint);
    
    $res = wp_remote_get($url, array(
      'timeout'=>30,
      'headers'=>array(
        'Authorization'=>'Basic '.base64_encode("$email:$pass"), 
        'Accept'=>'application/json',
        'User-Agent'=>'WP/FP-HIC-Plugin'
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
            } catch (Exception $e) { 
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
    $id = isset($u['id']) ? $u['id'] : null;
    if (empty($id) || !is_scalar($id)) {
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
    $email = isset($u['email']) ? $u['email'] : null;
    if (empty($email) || !is_string($email)) {
        hic_log("hic_process_update: no valid email in update for reservation $id");
        return;
    }
    
    $is_alias = hic_is_ota_alias_email($email);

    // Se c'è un'email reale nuova che sostituisce un alias
    if (!$is_alias && hic_is_valid_email($email)) {
        // upsert Brevo con vera email + liste by language
        $t = hic_transform_reservation($u); // riusa normalizzazioni
        if ($t !== false && is_array($t)) {
            hic_dispatch_brevo_reservation($t, true); // aggiorna contatto with enrichment flag
            // aggiorna store locale per id -> true_email
            hic_mark_email_enriched($id, $email);
            hic_log("Enriched email for reservation $id");
        } else {
            hic_log("hic_process_update: failed to transform reservation $id");
        }
    }

    // Se cambia presence e impostazione consente aggiornamenti:
    if (hic_allow_status_updates() && !empty($u['presence'])) {
        hic_log("Reservation $id presence update: ".$u['presence']);
        // opzionale: dispatch evento custom (no purchase)
    }
}

/**
 * Process new reservation for real-time Brevo notification
 */
function hic_process_new_reservation_for_realtime($reservation_data) {
    if (!hic_realtime_brevo_sync_enabled()) {
        hic_log('Real-time Brevo sync disabled, skipping new reservation notification');
        return;
    }

    $reservation_id = isset($reservation_data['id']) ? $reservation_data['id'] : null;
    if (empty($reservation_id)) {
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

    // Send reservation_created event to Brevo
    $event_sent = hic_send_brevo_reservation_created_event($transformed);
    
    if ($event_sent) {
        // Mark as successfully notified
        hic_mark_reservation_notified_to_brevo($reservation_id);
        hic_log("Successfully sent reservation_created event to Brevo for reservation $reservation_id");
        
        // Also send/update contact information
        if (hic_is_valid_email($transformed['email']) && !hic_is_ota_alias_email($transformed['email'])) {
            hic_dispatch_brevo_reservation($transformed, false);
        }
    } else {
        // Mark as failed for retry
        hic_mark_reservation_notification_failed($reservation_id, 'Failed to send Brevo event');
        hic_log("Failed to send reservation_created event to Brevo for reservation $reservation_id");
    }
}

/**
 * Test API connection with Basic Auth credentials
 */
function hic_test_api_connection($prop_id = null, $email = null, $password = null) {
    // Use provided credentials or fall back to settings
    $prop_id = $prop_id ?: hic_get_property_id();
    $email = $email ?: hic_get_api_email();
    $password = $password ?: hic_get_api_password();
    $base_url = hic_get_api_url();
    
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
        'from_date' => date('Y-m-d', strtotime('-7 days')),
        'to_date' => date('Y-m-d'),
        'limit' => 1
    );
    $test_url = add_query_arg($test_args, $endpoint);
    
    // Make the API request
    $response = wp_remote_get($test_url, array(
        'timeout' => 15,
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode("$email:$password"),
            'Accept' => 'application/json',
            'User-Agent' => 'WP/FP-HIC-Plugin-Test'
        ),
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
                return array(
                    'success' => false,
                    'message' => 'Risposta API non valida (JSON malformato)'
                );
            }
            
            return array(
                'success' => true,
                'message' => 'Connessione API riuscita! Credenziali valide.',
                'data_count' => is_array($data) ? count($data) : 0
            );
            
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
    $date_diff = (strtotime($to_date) - strtotime($from_date)) / DAY_IN_SECONDS;
    if ($date_diff > 180) {
        return array(
            'success' => false,
            'message' => 'Intervallo di date troppo ampio. Massimo 6 mesi.',
            'stats' => array()
        );
    }
    
    // Get API credentials
    $prop_id = hic_get_property_id();
    $email = hic_get_api_email();
    $password = hic_get_api_password();
    
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
                    hic_dispatch_reservation($transformed, $reservation);
                    $stats['total_processed']++;
                } else {
                    $stats['total_errors']++;
                    hic_log("Backfill: Failed to transform reservation: " . json_encode($reservation));
                }
                
            } catch (Exception $e) {
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
        
    } catch (Exception $e) {
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

/**
 * Raw fetch function that doesn't process reservations (for backfill use)
 */
function hic_fetch_reservations_raw($prop_id, $date_type, $from_date, $to_date, $limit = null) {
    $base = rtrim(hic_get_api_url(), '/');
    $email = hic_get_api_email();
    $pass = hic_get_api_password();
    
    if (!$base || !$email || !$pass || !$prop_id) {
        return new WP_Error('hic_missing_conf', 'URL/credenziali/propId mancanti');
    }
    
    // Use standard /reservations/ endpoint - only valid date_type values supported
    $endpoint = $base . '/reservations/' . rawurlencode($prop_id);
    $args = array('date_type' => $date_type, 'from_date' => $from_date, 'to_date' => $to_date);
    if ($limit) $args['limit'] = (int)$limit;
    $url = add_query_arg($args, $endpoint);
    
    hic_log("Backfill Raw API Call (Reservations endpoint): $url");

    $res = wp_remote_get($url, array(
        'timeout' => 30,
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode("$email:$pass"),
            'Accept' => 'application/json',
            'User-Agent' => 'WP/FP-HIC-Plugin-Backfill'
        ),
    ));
    
    if (is_wp_error($res)) {
        hic_log("Backfill Raw API call failed: " . $res->get_error_message());
        return $res;
    }
    
    $code = wp_remote_retrieve_response_code($res);
    if ($code !== 200) {
        $body = wp_remote_retrieve_body($res);
        hic_log("Backfill Raw API HTTP $code - Response body: " . substr($body, 0, 500));
        
        // Provide specific error messages for common HTTP codes in backfill context
        switch ($code) {
            case 400:
                return new WP_Error('hic_http', "HTTP 400 - Richiesta backfill non valida. Verifica i parametri: date_type deve essere checkin, checkout o presence.");
            case 401:
                return new WP_Error('hic_http', "HTTP 401 - Credenziali non valide per backfill. Verifica email e password API.");
            case 403:
                return new WP_Error('hic_http', "HTTP 403 - Accesso negato per backfill. L'account potrebbe non avere permessi per questa struttura.");
            case 404:
                return new WP_Error('hic_http', "HTTP 404 - Struttura non trovata per backfill. Verifica l'ID Struttura (propId).");
            case 429:
                return new WP_Error('hic_http', "HTTP 429 - Troppe richieste per backfill. Riprova tra qualche minuto.");
            default:
                return new WP_Error('hic_http', "HTTP $code - Errore backfill API");
        }
    }
    
    $body = wp_remote_retrieve_body($res);
    if (empty($body)) {
        return new WP_Error('hic_empty_response', 'Empty response body from backfill API');
    }
    
    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        hic_log("Backfill JSON decode error: " . json_last_error_msg());
        return new WP_Error('hic_json_error', 'Invalid JSON response from backfill API');
    }
    
    // Handle new API response format with success/error structure
    $reservations = hic_extract_reservations_from_response($data);
    if (is_wp_error($reservations)) {
        return $reservations;
    }
    
    return $reservations;
}

/**
 * Continuous polling function - runs every minute
 * Focuses on recent reservations and manual bookings
 */
function hic_api_poll_bookings_continuous() {
    $start_time = microtime(true);
    hic_log('Continuous Polling: Starting 1-minute interval check');
    
    // Try to acquire lock to prevent overlapping executions
    if (!hic_acquire_polling_lock(120)) { // 2-minute timeout for continuous polling
        hic_log('Continuous Polling: Another polling process is running, skipping execution');
        return;
    }
    
    try {
        $prop_id = hic_get_property_id();
        if (empty($prop_id)) {
            hic_log('Continuous Polling: No property ID configured');
            return;
        }
        
        $current_time = time();
        
        // Check recent reservations (last 2 hours) to catch new bookings and updates
        $window_back_minutes = 120; // 2 hours back
        $window_forward_minutes = 5; // 5 minutes forward
        
        $from_time = $current_time - ($window_back_minutes * 60);
        $to_time = $current_time + ($window_forward_minutes * 60);
        
        $from_date = date('Y-m-d H:i:s', $from_time);
        $to_date = date('Y-m-d H:i:s', $to_time);
        
        hic_log("Continuous Polling: Checking window from $from_date to $to_date (property: $prop_id)");
        
        $total_new = 0;
        $total_skipped = 0;
        $total_errors = 0;
        
        // Check for new and updated reservations using /reservations_updates endpoint
        // This is the most effective way to catch recently created/modified reservations
        $max_lookback_seconds = 6 * DAY_IN_SECONDS; // 6 days for safety margin
        $earliest_allowed = $current_time - $max_lookback_seconds;
        $default_check = max($earliest_allowed, $current_time - 7200); // Default to 2 hours ago or earliest allowed
        
        $last_continuous_check = get_option('hic_last_continuous_check', $default_check);
        
        // Validate that the stored timestamp is not too old
        if ($last_continuous_check < $earliest_allowed) {
            hic_log("Continuous Polling: Stored timestamp too old (" . date('Y-m-d H:i:s', $last_continuous_check) . "), resetting to earliest allowed: " . date('Y-m-d H:i:s', $earliest_allowed));
            $last_continuous_check = $earliest_allowed;
            update_option('hic_last_continuous_check', $last_continuous_check);
        }
        
        hic_log("Continuous Polling: Checking for updates since " . date('Y-m-d H:i:s', $last_continuous_check));
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
            update_option('hic_last_continuous_check', $current_time);
        } else {
            $error_message = $updated_reservations->get_error_message();
            hic_log("Continuous Polling: Error checking for updates: " . $error_message);
            $total_errors++;
            
            // Check if this is a timestamp too old error and reset if necessary
            if ($updated_reservations->get_error_code() === 'hic_timestamp_too_old') {
                $reset_timestamp = $current_time - (2 * 60 * 60); // Reset to 2 hours ago for continuous polling
                update_option('hic_last_continuous_check', $reset_timestamp);
                hic_log('Continuous Polling: Timestamp error detected, reset timestamp to: ' . date('Y-m-d H:i:s', $reset_timestamp) . " ($reset_timestamp)");
                
                // Also reset scheduler timestamps to restart polling immediately
                update_option('hic_last_continuous_poll', 0);
                update_option('hic_last_deep_check', 0);
                hic_log('Continuous Polling: Reset scheduler timestamps to restart polling');
            }
        }
        
        // Also check by check-in date for today and tomorrow (catch modifications)
        $checkin_from = date('Y-m-d', $current_time);
        $checkin_to = date('Y-m-d', $current_time + DAY_IN_SECONDS);
        
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
        }
        
        $execution_time = round((microtime(true) - $start_time) * 1000, 2);
        
        // Store metrics for diagnostics
        update_option('hic_last_continuous_poll_count', $total_new);
        update_option('hic_last_continuous_poll_duration', $execution_time);
        
        hic_log("Continuous Polling: Completed in {$execution_time}ms - New: $total_new, Skipped: $total_skipped, Errors: $total_errors");
        
    } finally {
        hic_release_polling_lock();
    }
}

/**
 * Deep check polling function - runs every 10 minutes
 * Looks back 5 days to catch any missed reservations
 */
function hic_api_poll_bookings_deep_check() {
    $start_time = microtime(true);
    hic_log('Deep Check: Starting 10-minute interval deep check (5-day lookback)');
    
    // Try to acquire lock to prevent overlapping executions
    if (!hic_acquire_polling_lock(600)) { // 10-minute timeout for deep check
        hic_log('Deep Check: Another polling process is running, skipping execution');
        return;
    }
    
    try {
        $prop_id = hic_get_property_id();
        if (empty($prop_id)) {
            hic_log('Deep Check: No property ID configured');
            return;
        }
        
        $current_time = time();
        $lookback_seconds = 5 * DAY_IN_SECONDS; // 5 days
        
        $from_date = date('Y-m-d', $current_time - $lookback_seconds);
        $to_date = date('Y-m-d', $current_time);
        
        hic_log("Deep Check: Searching 5-day window from $from_date to $to_date (property: $prop_id)");
        
        $total_new = 0;
        $total_skipped = 0;
        $total_errors = 0;
        
        // Check for updates in the last 5 days using /reservations_updates endpoint
        // This catches any modifications or new reservations that might have been missed
        $max_lookback_seconds = 6 * DAY_IN_SECONDS; // 6 days for safety margin
        $safe_lookback_seconds = min($lookback_seconds, $max_lookback_seconds); // Ensure we don't exceed API limits
        $lookback_timestamp = $current_time - $safe_lookback_seconds;
        
        if ($safe_lookback_seconds < $lookback_seconds) {
            hic_log("Deep Check: Reduced lookback from " . ($lookback_seconds / DAY_IN_SECONDS) . " days to " . ($safe_lookback_seconds / DAY_IN_SECONDS) . " days due to API limits");
        }
        
        hic_log("Deep Check: Checking for updates since " . date('Y-m-d H:i:s', $lookback_timestamp));
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
                
                // Reset all relevant timestamps to safe values to ensure recovery
                $safe_timestamp = $current_time - (3 * DAY_IN_SECONDS); // Reset to 3 days ago
                update_option('hic_last_updates_since', $safe_timestamp);
                update_option('hic_last_update_check', $safe_timestamp);
                update_option('hic_last_continuous_check', $safe_timestamp);
                
                // Reset scheduler timestamps to restart polling immediately
                update_option('hic_last_continuous_poll', 0);
                update_option('hic_last_deep_check', 0);
                
                hic_log('Deep Check: Reset all timestamps to: ' . date('Y-m-d H:i:s', $safe_timestamp) . " for recovery");
                hic_log('Deep Check: Reset scheduler timestamps to restart polling immediately');
                
                // Reduce error count since this is a recoverable error that's now handled
                $total_errors--;
            }
        }
        
        // Also check check-in dates for the next 7 days (catch future reservations that might have been missed)
        $checkin_from = date('Y-m-d', $current_time);
        $checkin_to = date('Y-m-d', $current_time + (7 * DAY_IN_SECONDS));
        
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
        update_option('hic_last_deep_check_count', $total_new);
        update_option('hic_last_deep_check_duration', $execution_time);
        
        // Update last successful deep check if no errors
        if ($total_errors === 0) {
            update_option('hic_last_successful_deep_check', $current_time);
        }
        
        hic_log("Deep Check: Completed in {$execution_time}ms - Window: $from_date to $to_date, New: $total_new, Skipped: $total_skipped, Errors: $total_errors");
        
    } finally {
        hic_release_polling_lock();
    }
}

