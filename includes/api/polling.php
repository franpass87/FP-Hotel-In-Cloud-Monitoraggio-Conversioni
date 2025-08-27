<?php
/**
 * API Polling Handler
 */

if (!defined('ABSPATH')) exit;

/* ============ API Polling HIC ============ */
// Se selezionato API Polling, configura il cron
add_action('init', function() {
  $should_schedule = false;
  
  if (hic_get_connection_type() === 'api' && hic_get_api_url()) {
    // Check if we have Basic Auth credentials or legacy API key
    $has_basic_auth = hic_get_property_id() && hic_get_api_email() && hic_get_api_password();
    $has_legacy_key = hic_get_api_key(); // backward compatibility
    
    $should_schedule = $has_basic_auth || $has_legacy_key;
  }
  
  if ($should_schedule) {
    if (!wp_next_scheduled('hic_api_poll_event')) {
      wp_schedule_event(time(), 'hic_poll_interval', 'hic_api_poll_event');
    }
  } else {
    // Rimuovi il cron se non è più necessario
    $timestamp = wp_next_scheduled('hic_api_poll_event');
    if ($timestamp) {
      wp_unschedule_event($timestamp, 'hic_api_poll_event');
    }
  }
});

// Aggiungi intervallo personalizzato per il polling
add_filter('cron_schedules', function($schedules) {
  $schedules['hic_poll_interval'] = array(
    'interval' => 300, // 5 minuti
    'display' => 'Ogni 5 minuti (HIC Polling)'
  );
  return $schedules;
});

// Funzione di polling API
add_action('hic_api_poll_event', 'hic_api_poll_bookings');

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

    $res = wp_remote_get($url, array(
        'timeout' => 30,
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode("$email:$pass"),
            'Accept'        => 'application/json',
            'User-Agent'    => 'WP/FP-HIC-Plugin'
        ),
    ));
    if (is_wp_error($res)) { hic_log('HIC connessione fallita: '.$res->get_error_message()); return $res; }
    $code = wp_remote_retrieve_response_code($res);
    if ($code !== 200) { hic_log("HIC HTTP $code"); return new WP_Error('hic_http', "HTTP $code"); }

    $body = wp_remote_retrieve_body($res);
    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        hic_log('HIC JSON error: '.json_last_error_msg());
        return new WP_Error('hic_json', 'JSON malformato');
    }

    // Log di debug iniziale (ridotto)
    hic_log(['hic_reservations_count' => is_array($data)? count($data): 0]);

    // Processa singole prenotazioni con la pipeline esistente
    if (is_array($data)) {
        foreach ($data as $booking) {
            try { hic_process_booking_data($booking); }
            catch (Exception $e) { hic_log('Process booking error: '.$e->getMessage()); }
        }
    }
    return $data;
}

// Hook pubblico per esecuzione manuale
add_action('hic_fetch_reservations', function($prop_id, $date_type, $from_date, $to_date, $limit = null){
    return hic_fetch_reservations($prop_id, $date_type, $from_date, $to_date, $limit);
}, 10, 5);

// Wrapper cron function
function hic_api_poll_bookings(){
    $prop = hic_get_property_id();
    $email = hic_get_api_email();
    $password = hic_get_api_password();
    
    // Try new Basic Auth method first
    if ($prop && $email && $password) {
        $last = get_option('hic_last_api_poll', strtotime('-1 day'));
        $now  = time();
        $from = date('Y-m-d', $last);
        $to   = date('Y-m-d', $now);
        $date_type = 'checkin'; // default; in futuro rendere configurabile
        $out = hic_fetch_reservations($prop, $date_type, $from, $to, 100);
        if (!is_wp_error($out)) {
            update_option('hic_last_api_poll', $now);
        }
        return;
    }
    
    // Fall back to legacy API key method for backward compatibility
    $api_url = hic_get_api_url();
    $api_key = hic_get_api_key();
    
    if (!$api_url || !$api_key) {
        hic_log('Cron: propId mancante per Basic Auth e URL/API key mancanti per legacy');
        return;
    }

    // Legacy polling logic (unchanged)
    hic_legacy_api_poll_bookings();
}

// Legacy API polling function for backward compatibility
function hic_legacy_api_poll_bookings() {
  $api_url = hic_get_api_url();
  $api_key = hic_get_api_key();
  
  if (!$api_url || !$api_key) {
    hic_log('API polling: URL o API key mancanti');
    return;
  }

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

  // Aggiorna il timestamp dell'ultimo polling
  update_option('hic_last_api_poll', $current_time);
}