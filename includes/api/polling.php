<?php
/**
 * API Polling Handler
 */

if (!defined('ABSPATH')) exit;

/* ============ API Polling HIC ============ */
// Se selezionato API Polling, configura il cron
add_action('init', function() {
  if (hic_get_connection_type() === 'api' && hic_get_api_url() && hic_get_api_key()) {
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

function hic_api_poll_bookings() {
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