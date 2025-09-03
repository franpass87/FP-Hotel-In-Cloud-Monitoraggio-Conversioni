<?php
/**
 * Webhook API Handler
 */

if (!defined('ABSPATH')) exit;

/* ============ REST: webhook HIC ============ */
/* Configura in Hotel in Cloud:
   https://www.villadianella.it/wp-json/hic/v1/conversion?token=hic2025ga4
*/
add_action('rest_api_init', function () {
  // Solo se siamo in modalità webhook
  if (hic_get_connection_type() === 'webhook') {
    register_rest_route('hic/v1', '/conversion', [
      'methods'             => 'POST',
      'callback'            => 'hic_webhook_handler',
      'permission_callback' => '__return_true',
    ]);
  }
});

function hic_webhook_handler(WP_REST_Request $request) {
  // Validate token
  $token = $request->get_param('token');
  $expected_token = hic_get_webhook_token();
  
  if (empty($expected_token)) {
    hic_log('Webhook rifiutato: token webhook non configurato');
    return new WP_Error('missing_token','Token webhook non configurato',['status'=>500]);
  }
  
  if ($token !== $expected_token) {
    hic_log('Webhook rifiutato: token invalido');
    return new WP_Error('invalid_token','Token non valido',['status'=>403]);
  }

  // Get and validate JSON data
  $data = $request->get_json_params();
  if (!$data || !is_array($data)) {
    hic_log('Webhook senza payload valido');
    return new WP_REST_Response(['error'=>'no valid data'], 400);
  }
  
  // Log received data (be careful with sensitive information)
  hic_log(['Webhook ricevuto' => array_merge($data, ['email' => !empty($data['email']) ? '***HIDDEN***' : 'missing'])]);

  // Generate unique identifier for deduplication
  $reservation_id = hic_extract_reservation_id($data);
  
  // Check for duplication to prevent double processing
  if (!empty($reservation_id) && hic_is_reservation_already_processed($reservation_id)) {
    hic_log("Webhook skipped: reservation $reservation_id already processed");
    return ['status'=>'ok', 'processed' => false, 'reason' => 'already_processed'];
  }

  // Process booking data with error handling
  $result = hic_process_booking_data($data);
  
  // Mark reservation as processed if successful
  if ($result !== false && !empty($reservation_id)) {
    hic_mark_reservation_processed_by_id($reservation_id);
  }
  
  if ($result === false) {
    hic_log('Webhook: elaborazione fallita per dati ricevuti');
    return new WP_REST_Response(['error'=>'processing failed'], 500);
  }

  return ['status'=>'ok', 'processed' => true];
}