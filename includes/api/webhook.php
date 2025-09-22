<?php declare(strict_types=1);

use function FpHic\hic_process_booking_data;

/**
 * Webhook API Handler
 */

if (!defined('ABSPATH')) exit;

/* ============ REST: webhook HIC ============ */
/* Configura in Hotel in Cloud:
   https://www.villadianella.it/wp-json/hic/v1/conversion?token=hic2025ga4
*/
add_action('rest_api_init', function () {
  // Registra webhook se siamo in modalità webhook O hybrid
  if (in_array(hic_get_connection_type(), ['webhook', 'hybrid'])) {
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
          'required'          => false,
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
  $expected_token = hic_get_webhook_token();
  
  if (empty($expected_token)) {
    hic_log('Webhook rifiutato: token webhook non configurato');
    return new \WP_Error('missing_token','Token webhook non configurato',['status'=>500]);
  }
  
  if ($token !== $expected_token) {
    hic_log('Webhook rifiutato: token invalido');
    return new \WP_Error('invalid_token','Token non valido',['status'=>403]);
  }

  // Validate Content-Type header
  $content_type = $request->get_header('content-type');
  if ( stripos($content_type, 'application/json') === false ) {
    return new \WP_Error('invalid_content_type', 'Content-Type non supportato', ['status' => 415]);
  }

  // Get raw body and validate size
  $raw = file_get_contents('php://input');

  if (!is_string($raw)) {
    hic_log('Webhook body read failed: unable to access php://input');
    return new \WP_Error('invalid_body', 'Corpo della richiesta non leggibile', ['status' => 400]);
  }

  if (strlen($raw) > HIC_WEBHOOK_MAX_PAYLOAD_SIZE) {
    hic_log('Webhook payload troppo grande');
    return new \WP_Error('payload_too_large', 'Payload troppo grande', ['status' => 413]);
  }

  // Decode JSON data
  $data = json_decode($raw, true);

  if (json_last_error() !== JSON_ERROR_NONE) {
    $error_message = json_last_error_msg();
    hic_log('Webhook JSON non valido: ' . $error_message);
    return new \WP_Error('invalid_json', 'JSON non valido: ' . $error_message, ['status' => 400]);
  }

  if (!$data || !is_array($data)) {
    hic_log('Webhook senza payload valido');
    return new WP_REST_Response(['error' => 'no valid data'], 400);
  }

  // Use sanitized email from request params when valid
  $request_email = $request->get_param('email');
  if (is_string($request_email)) {
    $request_email = trim($request_email);
  }

  if (is_string($request_email) && $request_email !== '') {
    $validated_request_email = \FpHic\HIC_Input_Validator::validate_email($request_email);
    if (!is_wp_error($validated_request_email)) {
      $data['email'] = $validated_request_email;
    }
  }

  // Validate payload structure and required fields using enhanced validator
  $payload_validation = \FpHic\HIC_Input_Validator::validate_webhook_payload($data);
  if (is_wp_error($payload_validation)) {
    hic_log('Webhook validation failed: ' . $payload_validation->get_error_message());
    return $payload_validation;
  }
  
  // Use validated data
  $data = $payload_validation;
  
  // Log received data (be careful with sensitive information)
  hic_log(['Webhook ricevuto' => array_merge($data, ['email' => !empty($data['email']) ? '***HIDDEN***' : 'missing'])]);

  // Generate unique identifier for deduplication
  $reservation_id = hic_extract_reservation_id($data);
  
  // Check for duplication to prevent double processing
  if (!empty($reservation_id) && hic_is_reservation_already_processed($reservation_id)) {
    hic_log("Webhook skipped: reservation $reservation_id already processed");
    return ['status'=>'ok', 'processed' => false, 'reason' => 'already_processed'];
  }

  // Acquire processing lock to prevent concurrent processing
  if (!empty($reservation_id) && !hic_acquire_reservation_lock($reservation_id)) {
    hic_log("Webhook skipped: reservation $reservation_id is being processed by another request");
    return ['status'=>'ok', 'processed' => false, 'reason' => 'concurrent_processing'];
  }

  try {
    // Process booking data with error handling
    $email_missing = empty($data['email']);
    $result = hic_process_booking_data($data);

    if (!is_array($result)) {
      $result = [
        'status' => $result ? 'success' : 'failed',
        'should_mark_processed' => (bool) $result,
        'messages' => ['legacy_result'],
      ];
    }

    $status = isset($result['status']) ? (string) $result['status'] : 'failed';
    $should_mark_processed = !empty($result['should_mark_processed']);

    if (!empty($reservation_id) && !empty($result['failed_details']) && is_array($result['failed_details'])) {
      $retry_context = [];
      if (!empty($result['context']) && is_array($result['context'])) {
        $retry_context = $result['context'];
      }
      $retry_context['source'] = 'webhook';
      $retry_context['status'] = $status;
      if ($email_missing) {
        $retry_context['email_missing'] = '1';
      }

      hic_queue_integration_retry($reservation_id, $result['failed_details'], $retry_context);
    }

    if ($should_mark_processed && !empty($reservation_id)) {
      hic_mark_reservation_processed_by_id($reservation_id);
    }

    // Update last webhook processing time for diagnostics
    update_option('hic_last_webhook_processing', current_time('mysql'), false);
    hic_clear_option_cache('hic_last_webhook_processing');

    if ($status === 'failed') {
      if ($email_missing) {
        hic_log('Webhook: dati elaborati senza email, integrazioni limitate');
        return [
          'status' => 'ok',
          'processed' => false,
          'reason' => 'missing_email',
          'result' => $result,
        ];
      }

      hic_log('Webhook: elaborazione fallita per dati ricevuti');
      return new WP_REST_Response([
        'error' => 'processing failed',
        'result' => $result,
      ], 500);
    }

    $response_result = [
      'status' => $status,
      'should_mark_processed' => $should_mark_processed,
      'failed_integrations' => $result['failed_integrations'] ?? [],
      'successful_integrations' => $result['successful_integrations'] ?? [],
      'messages' => $result['messages'] ?? [],
    ];

    if (!empty($result['failed_details'])) {
      $response_result['failed_details'] = $result['failed_details'];
    }

    if (!empty($result['summary'])) {
      $response_result['summary'] = $result['summary'];
    }

    return [
      'status' => 'ok',
      'processed' => $should_mark_processed,
      'result' => $response_result,
    ];

  } finally {
    // Always release the lock
    if (!empty($reservation_id)) {
      hic_release_reservation_lock($reservation_id);
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
    return new \WP_Error('invalid_payload', 'Payload non valido', ['status' => 400]);
  }

  // Email validation (optional field)
  if (array_key_exists('email', $payload)) {
    $email_value = $payload['email'];

    if (is_string($email_value)) {
      $email_value = trim($email_value);
    }

    if ($email_value !== '' && $email_value !== null) {
      if (!is_scalar($email_value)) {
        hic_log('Webhook payload: email non valida');
        return new \WP_Error('invalid_email', 'Campo email non valido', ['status' => 400]);
      }

      $sanitized_email = sanitize_email((string) $email_value);

      if ($sanitized_email === '' || !hic_is_valid_email($sanitized_email)) {
        hic_log('Webhook payload: email non valida');
        return new \WP_Error('invalid_email', 'Campo email non valido', ['status' => 400]);
      }
    }
  }

  return true;
}
