<?php declare(strict_types=1);
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
  if (Helpers\hic_get_connection_type() === 'webhook') {
    register_rest_route('hic/v1', '/conversion', [
      'methods'             => 'POST',
      'callback'            => 'hic_webhook_handler',
      'permission_callback' => '__return_true',
      'args'                => [
        'token' => [
          'required'          => true,
          'sanitize_callback' => 'sanitize_text_field',
          'description'       => 'Token di sicurezza per autenticare la richiesta',
        ],
        'email' => [
          'required'          => true,
          'sanitize_callback' => 'sanitize_email',
          'description'       => 'Email del cliente associata alla prenotazione',
        ],
      ],
    ]);
  }
});

function hic_webhook_handler(WP_REST_Request $request) {
  // Validate token
  $token = $request->get_param('token');
  $expected_token = Helpers\hic_get_webhook_token();
  
  if (empty($expected_token)) {
    hic_log('Webhook rifiutato: token webhook non configurato');
    return new WP_Error('missing_token','Token webhook non configurato',['status'=>500]);
  }
  
  if ($token !== $expected_token) {
    hic_log('Webhook rifiutato: token invalido');
    return new WP_Error('invalid_token','Token non valido',['status'=>403]);
  }

  // Validate Content-Type header
  $content_type = $request->get_header('content-type');
  if ( stripos($content_type, 'application/json') === false ) {
    return new WP_Error('invalid_content_type', 'Content-Type non supportato', ['status' => 415]);
  }

  // Get raw body and validate size
  $raw = file_get_contents('php://input');
  if (strlen($raw) > HIC_WEBHOOK_MAX_PAYLOAD_SIZE) {
    hic_log('Webhook payload troppo grande');
    return new WP_Error('payload_too_large', 'Payload troppo grande', ['status' => 413]);
  }

  // Decode JSON data
  $data = json_decode($raw, true);
  if (!$data || !is_array($data)) {
    hic_log('Webhook senza payload valido');
    return new WP_REST_Response(['error' => 'no valid data'], 400);
  }

  // Use sanitized email from request params
  $data['email'] = $request->get_param('email');

  // Validate payload structure and required fields
  $payload_validation = hic_validate_webhook_payload($data);
  if (is_wp_error($payload_validation)) {
    return $payload_validation;
  }
  
  // Log received data (be careful with sensitive information)
  hic_log(['Webhook ricevuto' => array_merge($data, ['email' => !empty($data['email']) ? '***HIDDEN***' : 'missing'])]);

  // Generate unique identifier for deduplication
  $reservation_id = Helpers\hic_extract_reservation_id($data);
  
  // Check for duplication to prevent double processing
  if (!empty($reservation_id) && Helpers\hic_is_reservation_already_processed($reservation_id)) {
    hic_log("Webhook skipped: reservation $reservation_id already processed");
    return ['status'=>'ok', 'processed' => false, 'reason' => 'already_processed'];
  }

  // Acquire processing lock to prevent concurrent processing
  if (!empty($reservation_id) && !Helpers\hic_acquire_reservation_lock($reservation_id)) {
    hic_log("Webhook skipped: reservation $reservation_id is being processed by another request");
    return ['status'=>'ok', 'processed' => false, 'reason' => 'concurrent_processing'];
  }

  try {
    // Process booking data with error handling
    $result = hic_process_booking_data($data);
    
    // Mark reservation as processed if successful
    if ($result !== false && !empty($reservation_id)) {
      Helpers\hic_mark_reservation_processed_by_id($reservation_id);
    }
    
    // Update last webhook processing time for diagnostics
    update_option('hic_last_webhook_processing', current_time('mysql'), false);
    Helpers\hic_clear_option_cache('hic_last_webhook_processing');
    
    if ($result === false) {
      hic_log('Webhook: elaborazione fallita per dati ricevuti');
      return new WP_REST_Response(['error'=>'processing failed'], 500);
    }

    return ['status'=>'ok', 'processed' => true];
    
  } finally {
    // Always release the lock
    if (!empty($reservation_id)) {
      Helpers\hic_release_reservation_lock($reservation_id);
    }
  }
}

/**
 * Validate webhook payload structure
 *
 * Ensures required fields are present and correctly formatted.
 *
 * @param array|null $payload Decoded JSON payload
 * @return true|WP_Error
 */
function hic_validate_webhook_payload($payload) {
  if (!is_array($payload)) {
    return new WP_Error('invalid_payload', 'Payload non valido', ['status' => 400]);
  }

  // Required field: email
  if (empty($payload['email']) || !Helpers\hic_is_valid_email($payload['email'])) {
    hic_log('Webhook payload: email mancante o non valida');
    return new WP_Error('invalid_email', 'Campo email mancante o non valido', ['status' => 400]);
  }

  return true;
}