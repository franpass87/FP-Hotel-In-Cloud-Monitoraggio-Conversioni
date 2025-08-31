<?php
/**
 * API Polling Handler
 */

if (!defined('ABSPATH')) exit;

// Define constants for better maintainability
define('HIC_POLL_INTERVAL_SECONDS', 300);  // 5 minutes
define('HIC_RETRY_INTERVAL_SECONDS', 900); // 15 minutes

// Aggiungi intervallo personalizzato per il polling PRIMA di usarlo
// Use higher priority to ensure it's registered early
add_filter('cron_schedules', function($schedules) {
  if (!isset($schedules['hic_poll_interval'])) {
    $schedules['hic_poll_interval'] = array(
      'interval' => HIC_POLL_INTERVAL_SECONDS,
      'display' => 'Ogni 5 minuti (HIC Polling)'
    );
  }
  
  if (!isset($schedules['hic_retry_interval'])) {
    $schedules['hic_retry_interval'] = array(
      'interval' => HIC_RETRY_INTERVAL_SECONDS,
      'display' => 'Ogni 15 minuti (HIC Retry)'
    );
  }
  
  // Add quasi-realtime polling schedules
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
  
  // Add reliable polling schedule
  if (!isset($schedules['hic_reliable_interval'])) {
    $schedules['hic_reliable_interval'] = array(
      'interval' => 300, // 5 minutes default
      'display' => 'Every 5 Minutes (HIC Reliable)'
    );
  }
  
  return $schedules;
}, 5);

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
        return new WP_Error('hic_http', "HTTP 400 - Richiesta non valida. Verifica i parametri inviati (date_type deve essere checkin, checkout o presence).");
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

/* ============ API Polling HIC ============ */
// Se selezionato API Polling, configura il cron
add_action('init', function() {
  $should_schedule = false;
  
  if (hic_get_connection_type() === 'api' && hic_get_api_url()) {
    // Check if we have Basic Auth credentials or legacy API key
    $has_basic_auth = hic_has_basic_auth_credentials();
    $has_legacy_key = hic_get_api_key(); // backward compatibility
    
    $should_schedule = $has_basic_auth || $has_legacy_key;
    
    // Log scheduling conditions for debugging
    hic_log("Cron scheduling conditions - Connection type: " . hic_get_connection_type() . 
            ", API URL: " . (hic_get_api_url() ? 'configured' : 'missing') .
            ", Basic Auth: " . ($has_basic_auth ? 'yes' : 'no') .
            ", Legacy Key: " . ($has_legacy_key ? 'yes' : 'no') .
            ", Should schedule: " . ($should_schedule ? 'yes' : 'no'));
  }
  
  if ($should_schedule) {
    if (!wp_next_scheduled('hic_api_poll_event')) {
      // Use configured polling interval for quasi-realtime polling
      $polling_interval = hic_get_polling_interval();
      $result = wp_schedule_event(time() + 60, $polling_interval, 'hic_api_poll_event');
      if (!$result) {
        hic_log("ERROR: Failed to schedule hic_api_poll_event with interval '$polling_interval'. Check if interval is registered.");
      } else {
        hic_log("hic_api_poll_event scheduled successfully with interval '$polling_interval'");
        // Verify the scheduled interval
        $schedules = wp_get_schedules();
        if (isset($schedules[$polling_interval])) {
          hic_log('Confirmed: ' . $polling_interval . ' = ' . $schedules[$polling_interval]['interval'] . ' seconds');
        }
      }
    } else {
      // Log that event is already scheduled and verify its interval
      $next_run = wp_next_scheduled('hic_api_poll_event');
      hic_log('hic_api_poll_event already scheduled for: ' . date('Y-m-d H:i:s', $next_run));
    }
  } else {
    // Rimuovi il cron se non è più necessario
    $timestamp = wp_next_scheduled('hic_api_poll_event');
    if ($timestamp) {
      wp_unschedule_event($timestamp, 'hic_api_poll_event');
      hic_log('hic_api_poll_event unscheduled (conditions not met)');
    }
  }
});

// Funzione di polling API
add_action('hic_api_poll_event', 'hic_api_poll_bookings');

// New updates polling system
add_action('init', function() {
  $should_schedule_updates = false;
  
  if (hic_get_connection_type() === 'api' && hic_get_api_url() && hic_updates_enrich_contacts()) {
    $has_basic_auth = hic_has_basic_auth_credentials();
    $should_schedule_updates = $has_basic_auth;
  }
  
  if ($should_schedule_updates) {
    if (!wp_next_scheduled('hic_api_updates_event')) {
      $result = wp_schedule_event(time(), 'hic_poll_interval', 'hic_api_updates_event');
      if (!$result) {
        hic_log('ERROR: Failed to schedule hic_api_updates_event. Check if hic_poll_interval is registered.');
      } else {
        hic_log('hic_api_updates_event scheduled successfully with hic_poll_interval');
        // Verify the scheduled interval
        $schedules = wp_get_schedules();
        if (isset($schedules['hic_poll_interval'])) {
          hic_log('Confirmed: hic_poll_interval = ' . $schedules['hic_poll_interval']['interval'] . ' seconds');
        }
      }
    } else {
      // Log that event is already scheduled
      $next_run = wp_next_scheduled('hic_api_updates_event');
      hic_log('hic_api_updates_event already scheduled for: ' . date('Y-m-d H:i:s', $next_run));
    }
  } else {
    $timestamp = wp_next_scheduled('hic_api_updates_event');
    if ($timestamp) {
      wp_unschedule_event($timestamp, 'hic_api_updates_event');
      hic_log('hic_api_updates_event unscheduled (conditions not met)');
    }
  }
});

// Retry mechanism for failed real-time notifications
add_action('init', function() {
  $should_schedule_retry = hic_should_schedule_retry_event();
  
  if ($should_schedule_retry) {
    if (!wp_next_scheduled('hic_retry_failed_notifications_event')) {
      $result = wp_schedule_event(time(), 'hic_retry_interval', 'hic_retry_failed_notifications_event');
      if (!$result) {
        hic_log('ERROR: Failed to schedule hic_retry_failed_notifications_event');
      } else {
        hic_log('hic_retry_failed_notifications_event scheduled successfully');
      }
    }
  } else {
    $timestamp = wp_next_scheduled('hic_retry_failed_notifications_event');
    if ($timestamp) {
      wp_unschedule_event($timestamp, 'hic_retry_failed_notifications_event');
      hic_log('hic_retry_failed_notifications_event unscheduled (conditions not met)');
    }
  }
});

// Updates polling function
add_action('hic_api_updates_event', 'hic_api_poll_updates');

// Retry failed notifications function
add_action('hic_retry_failed_notifications_event', 'hic_retry_failed_brevo_notifications');

/**
 * Chiama HIC: GET /reservations/{propId}
 */
function hic_fetch_reservations($prop_id, $date_type, $from_date, $to_date, $limit = null){
    $base = rtrim(hic_get_api_url(), '/'); // es: https://api.hotelincloud.com/api/partner
    $email = hic_get_api_email();
    $pass  = hic_get_api_password();
    if (!$base || !$email || !$pass || !$prop_id) {
        return new WP_Error('hic_missing_conf', 'URL/credenziali/propId mancanti');
    }
    $endpoint = $base . '/reservations/' . rawurlencode($prop_id);
    $args = array('date_type'=>$date_type,'from_date'=>$from_date,'to_date'=>$to_date);
    if ($limit) $args['limit'] = (int)$limit;
    $url = add_query_arg($args, $endpoint);
    
    // Log API call details for debugging
    hic_log("API Call: $url with params: " . json_encode($args));

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

    // Log di debug iniziale (ridotto)
    hic_log(array('hic_reservations_count' => is_array($data) ? count($data) : 0));

    // Processa singole prenotazioni con la nuova pipeline
    $processed_count = 0;
    if (is_array($data)) {
        foreach ($data as $reservation) {
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
        if (count($data) > 0) {
            hic_log("Processed $processed_count out of " . count($data) . " reservations (duplicates/invalid skipped)");
        }
    }
    return $data;
}

// Hook pubblico per esecuzione manuale
add_action('hic_fetch_reservations', function($prop_id, $date_type, $from_date, $to_date, $limit = null){
    return hic_fetch_reservations($prop_id, $date_type, $from_date, $to_date, $limit);
}, 10, 5);

/**
 * Validates if a reservation should be processed
 */
function hic_should_process_reservation($reservation) {
    // Check required fields
    $required = ['id', 'from_date', 'to_date', 'accommodation_id', 'accommodation_name'];
    foreach ($required as $field) {
        if (empty($reservation[$field])) {
            hic_log("Reservation skipped: missing required field '$field'");
            return false;
        }
    }
    
    // Check valid flag
    $valid = isset($reservation['valid']) ? intval($reservation['valid']) : 1;
    if ($valid === 0 && !hic_process_invalid()) {
        hic_log("Reservation skipped: valid=0 and process_invalid=false");
        return false;
    }
    
    // Check deduplication
    $uid = hic_booking_uid($reservation);
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
    
    return array(
        'transaction_id' => $reservation['id'],
        'reservation_code' => isset($reservation['reservation_code']) ? $reservation['reservation_code'] : '',
        'value' => $value,
        'currency' => $currency,
        'accommodation_id' => isset($reservation['accommodation_id']) ? $reservation['accommodation_id'] : '',
        'accommodation_name' => isset($reservation['accommodation_name']) ? $reservation['accommodation_name'] : '',
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
    
    try {
        // GA4 - only send once unless it's a status update we want to track
        if (!$is_status_update) {
            hic_dispatch_ga4_reservation($transformed);
        }
        
        // Meta Pixel - only send once unless it's a status update we want to track  
        if (!$is_status_update) {
            hic_dispatch_pixel_reservation($transformed);
        }
        
        // Brevo - always update contact info
        hic_dispatch_brevo_reservation($transformed);
        
        hic_log("Reservation $uid dispatched successfully");
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

// Wrapper cron function
function hic_api_poll_bookings(){
    $start_time = microtime(true);
    hic_log('Cron: hic_api_poll_bookings execution started');
    
    // Rotate log if needed
    hic_rotate_log_if_needed();
    
    // Try to acquire lock to prevent overlapping executions
    if (!hic_acquire_polling_lock(300)) {
        hic_log('Cron: Another polling process is running, skipping execution');
        return;
    }
    
    try {
        // Always update execution timestamp regardless of results
        update_option('hic_last_cron_execution', time());
        
        $prop = hic_get_property_id();
        $email = hic_get_api_email();
        $password = hic_get_api_password();
        $connection_type = hic_get_connection_type();
        
        hic_log("Cron: Current config - Connection: $connection_type, PropID: $prop, Email: " . ($email ? 'configured' : 'missing'));
        
        // Use quasi-realtime approach with Basic Auth
        if ($prop && $email && $password) {
            hic_quasi_realtime_poll($prop, $start_time);
        } else {
            // Fall back to legacy API key method for backward compatibility
            $api_url = hic_get_api_url();
            $api_key = hic_get_api_key();
            
            if (!$api_url || !$api_key) {
                hic_log('Cron: No valid credentials found (neither Basic Auth nor legacy API key)');
                return;
            }
            
            hic_log('Cron: using legacy API key method');
            hic_legacy_api_poll_bookings();
        }
    } finally {
        // Always release the lock
        hic_release_polling_lock();
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
    
    $from_date = date('Y-m-d H:i:s', $from_time);
    $to_date = date('Y-m-d H:i:s', $to_time);
    
    hic_log("Cron: Moving window polling from $from_date to $to_date (property: $prop_id)");
    
    $total_new = 0;
    $total_skipped = 0;
    $total_errors = 0;
    $polling_errors = array();
    
    // Poll by created date for recent bookings (most important for real-time)
    $created_from = date('Y-m-d', $from_time);
    $created_to = date('Y-m-d', $to_time);
    
    hic_log("Cron: Polling by created date from $created_from to $created_to");
    $created_reservations = hic_fetch_reservations($prop_id, 'created', $created_from, $created_to, 100);
    
    if (!is_wp_error($created_reservations)) {
        $created_count = is_array($created_reservations) ? count($created_reservations) : 0;
        hic_log("Cron: Found $created_count reservations by created date");
        
        if ($created_count > 0) {
            $process_result = hic_process_reservations_batch($created_reservations);
            $total_new += $process_result['new'];
            $total_skipped += $process_result['skipped'];
            $total_errors += $process_result['errors'];
        }
    } else {
        $polling_errors[] = "created date polling: " . $created_reservations->get_error_message();
        $total_errors++;
    }
    
    // Also poll by checkin date to catch any updates to existing bookings
    $checkin_from = date('Y-m-d', $from_time);
    $checkin_to = date('Y-m-d', $to_time + (7 * DAY_IN_SECONDS)); // Extend checkin window
    
    hic_log("Cron: Polling by checkin date from $checkin_from to $checkin_to");
    $checkin_reservations = hic_fetch_reservations($prop_id, 'checkin', $checkin_from, $checkin_to, 100);
    
    if (!is_wp_error($checkin_reservations)) {
        $checkin_count = is_array($checkin_reservations) ? count($checkin_reservations) : 0;
        hic_log("Cron: Found $checkin_count reservations by checkin date");
        
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
        hic_log("Cron: Updated last successful poll timestamp");
    }
    
    // Comprehensive logging
    $log_msg = sprintf(
        "Cron: Completed in %sms - Window: %s to %s, New: %d, Skipped: %d, Errors: %d",
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

// Legacy API polling function for backward compatibility
function hic_legacy_api_poll_bookings() {
  $api_url = hic_get_api_url();
  $api_key = hic_get_api_key();
  
  if (!$api_url || !$api_key) {
    hic_log('API polling: URL o API key mancanti');
    return;
  }

  // Always update execution timestamp regardless of results
  update_option('hic_last_cron_execution', time());

  // Ottieni l'ultimo timestamp processato
  $last_poll = get_option('hic_last_api_poll', strtotime('-1 hour'));
  $current_time = time();

  // Costruisci l'URL API con filtri temporali
  $poll_url = add_query_arg([
    'api_key' => $api_key,
    'from' => date('Y-m-d H:i:s', $last_poll),
    'to' => date('Y-m-d H:i:s', $current_time)
  ], rtrim($api_url, '/') . '/bookings');

  $response = wp_remote_get($poll_url, [
    'timeout' => 30,
    'headers' => [
      'Accept' => 'application/json',
      'User-Agent' => 'WordPress/HIC-Plugin'
    ]
  ]);

  if (is_wp_error($response)) {
    hic_log('API polling errore: ' . $response->get_error_message());
    return;
  }

  $code = wp_remote_retrieve_response_code($response);
  if ($code !== 200) {
    hic_log("API polling HTTP error: $code");
    return;
  }

  $body = wp_remote_retrieve_body($response);
  $data = json_decode($body, true);

  if (json_last_error() !== JSON_ERROR_NONE) {
    hic_log('API polling: errore JSON - ' . json_last_error_msg());
    return;
  }

  if (!$data || !is_array($data)) {
    hic_log('API polling: risposta non valida - ' . substr($body, 0, 200));
    return;
  }

  hic_log("API polling: trovate " . count($data) . " prenotazioni");

  // Processa ogni prenotazione con error handling
  $processed = 0;
  $errors = 0;
  foreach ($data as $booking) {
    try {
      hic_process_booking_data($booking);
      $processed++;
    } catch (Exception $e) {
      $errors++;
      hic_log('Errore processando prenotazione: ' . $e->getMessage());
    }
  }

  hic_log("API polling completato: $processed processate, $errors errori");

  // Store count for diagnostics
  update_option('hic_last_poll_count', count($data));

  // Only update timestamp if we found reservations OR if enough time has passed
  $time_since_last_poll = $current_time - $last_poll;
  if (count($data) > 0) {
    update_option('hic_last_api_poll', $current_time);
    hic_log("Legacy polling: Updated timestamp (found " . count($data) . " reservations)");
  } elseif ($time_since_last_poll > DAY_IN_SECONDS) {
    update_option('hic_last_api_poll', $current_time);
    hic_log('Legacy polling: Advanced timestamp after 24+ hours with no reservations');
  } else {
    hic_log('Legacy polling: No reservations found, keeping previous timestamp for retry');
  }
}

/**
 * New updates polling wrapper function
 */
function hic_api_poll_updates(){
    hic_log('Cron: hic_api_poll_updates execution started');
    
    // Always update execution timestamp regardless of results
    update_option('hic_last_cron_execution', time());
    
    $prop = hic_get_property_id();
    
    // Add safety overlap to prevent gaps between polling intervals
    $overlap_seconds = 300; // 5 minute overlap for safety
    $last_since = get_option('hic_last_updates_since', time() - DAY_IN_SECONDS);
    $since = max(0, $last_since - $overlap_seconds);
    
    $current_time = time();
    hic_log("Cron: polling updates for property $prop");
    hic_log("Cron: last timestamp: " . date('Y-m-d H:i:s', $last_since) . " ($last_since)");
    hic_log("Cron: requesting since: " . date('Y-m-d H:i:s', $since) . " ($since) [overlap: {$overlap_seconds}s]");
    
    $out = hic_fetch_reservations_updates($prop, $since, 200); // limit opzionale se supportato
    if (!is_wp_error($out)) {
        $updates_count = is_array($out) ? count($out) : 0;
        hic_log("Cron: Found $updates_count updates");
        
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
                hic_log("Cron: Using max updated_at timestamp: " . date('Y-m-d H:i:s', $new_timestamp) . " ($new_timestamp)");
            } else {
                hic_log("Cron: No updated_at field found, using current time: " . date('Y-m-d H:i:s', $new_timestamp) . " ($new_timestamp)");
            }
        } else {
            // No updates found - advance timestamp only if enough time has passed to prevent infinite polling
            $time_since_last_poll = $current_time - $last_since;
            if ($time_since_last_poll > 3600) { // 1 hour
                hic_log("Cron: No updates found but 1+ hour passed, advancing timestamp to prevent infinite polling");
            } else {
                // Keep previous timestamp for retry, but with small increment to avoid exact same request
                $new_timestamp = $last_since + 60; // Advance by 1 minute to make progress
                hic_log("Cron: No updates found, advancing by 1 minute for next retry: " . date('Y-m-d H:i:s', $new_timestamp) . " ($new_timestamp)");
            }
        }
        
        update_option('hic_last_updates_since', $new_timestamp);
        hic_log('Cron: hic_api_poll_updates completed successfully');
    } else {
        hic_log('Cron: hic_api_poll_updates failed: ' . $out->get_error_message());
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

    hic_log('Cron: hic_retry_failed_brevo_notifications execution started');
    
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
    
    $endpoint = $base.'/reservations_updates/'.rawurlencode($prop_id);
    $args = array('since' => $since);
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

    // Log di debug iniziale
    hic_log(array('hic_updates_count' => is_array($data) ? count($data) : 0));

    // Process each update
    if (is_array($data)) {
        foreach ($data as $u) {
            try {
                hic_process_update($u);
            } catch (Exception $e) { 
                hic_log('Process update error: '.$e->getMessage()); 
            }
        }
    }
    
    return $data;
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
    
    // Validate date type (based on API documentation: only checkin, checkout, presence are supported)
    // Note: For date_type='created' functionality, use /reservations_updates endpoint with updated_after parameter
    // (limited to 7 days back due to API constraints)
    if (!in_array($date_type, array('checkin', 'checkout', 'presence'))) {
        return array(
            'success' => false,
            'message' => 'Tipo di data non valido. Deve essere "checkin", "checkout" o "presence". Per recuperare prenotazioni per data di creazione, utilizza l\'endpoint updates (limitato a 7 giorni).',
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
        // Fetch reservations from API
        $reservations = hic_fetch_reservations($prop_id, $date_type, $from_date, $to_date, $limit);
        
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
 * Force reschedule cron events to ensure correct interval
 */
function hic_force_reschedule_cron_events() {
    $results = array();
    
    // Clear existing events first
    $poll_timestamp = wp_next_scheduled('hic_api_poll_event');
    if ($poll_timestamp) {
        wp_unschedule_event($poll_timestamp, 'hic_api_poll_event');
        $results['hic_api_poll_event_cleared'] = 'Event cleared';
    }
    
    $updates_timestamp = wp_next_scheduled('hic_api_updates_event');
    if ($updates_timestamp) {
        wp_unschedule_event($updates_timestamp, 'hic_api_updates_event');
        $results['hic_api_updates_event_cleared'] = 'Event cleared';
    }
    
    // Check if we should reschedule based on current configuration
    $should_schedule_poll = hic_get_connection_type() === 'api' && hic_get_api_url() && 
                           (hic_has_basic_auth_credentials() || hic_get_api_key());
    
    $should_schedule_updates = hic_get_connection_type() === 'api' && hic_get_api_url() && 
                              hic_updates_enrich_contacts() && hic_has_basic_auth_credentials();
    
    // Reschedule with correct interval
    if ($should_schedule_poll) {
        $poll_result = wp_schedule_event(time(), 'hic_poll_interval', 'hic_api_poll_event');
        $results['hic_api_poll_event_scheduled'] = $poll_result ? 'Successfully scheduled' : 'Failed to schedule';
        if ($poll_result) {
            hic_log('Force reschedule: hic_api_poll_event rescheduled successfully');
        } else {
            hic_log('Force reschedule: Failed to reschedule hic_api_poll_event');
        }
    } else {
        $results['hic_api_poll_event_scheduled'] = 'Conditions not met, not scheduled';
    }
    
    if ($should_schedule_updates) {
        $updates_result = wp_schedule_event(time(), 'hic_poll_interval', 'hic_api_updates_event');
        $results['hic_api_updates_event_scheduled'] = $updates_result ? 'Successfully scheduled' : 'Failed to schedule';
        if ($updates_result) {
            hic_log('Force reschedule: hic_api_updates_event rescheduled successfully');
        } else {
            hic_log('Force reschedule: Failed to reschedule hic_api_updates_event');
        }
    } else {
        $results['hic_api_updates_event_scheduled'] = 'Conditions not met, not scheduled';
    }
    
    return $results;
}