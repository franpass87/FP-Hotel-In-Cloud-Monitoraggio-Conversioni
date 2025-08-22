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
  $token = $request->get_param('token');
  if ($token !== hic_get_webhook_token()) {
    hic_log('Webhook rifiutato: token invalido');
    return new WP_Error('invalid_token','Token non valido',['status'=>403]);
  }

  $data = $request->get_json_params();
  if (!$data) {
    hic_log('Webhook senza payload');
    return new WP_REST_Response(['error'=>'no data'], 400);
  }
  hic_log(['Webhook ricevuto' => $data]);

  hic_process_booking_data($data);

  return ['status'=>'ok'];
}