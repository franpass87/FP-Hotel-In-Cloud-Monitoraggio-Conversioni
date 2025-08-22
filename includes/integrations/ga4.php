<?php
/**
 * Google Analytics 4 Integration
 */

if (!defined('ABSPATH')) exit;

/* ============ GA4 (purchase + bucket) ============ */
function hic_send_to_ga4($data, $gclid, $fbclid) {
  // Validate configuration
  $measurement_id = hic_get_measurement_id();
  $api_secret = hic_get_api_secret();
  
  if (empty($measurement_id) || empty($api_secret)) {
    hic_log('GA4: measurement ID o API secret mancanti');
    return;
  }

  $bucket    = hic_get_bucket($gclid, $fbclid); // gads | fbads | organic
  $client_id = $gclid ?: ($fbclid ?: (string) wp_generate_uuid4());

  // Validate amount is numeric
  $amount = 0;
  if (isset($data['amount']) && is_numeric($data['amount'])) {
    $amount = floatval($data['amount']);
  }

  $params = [
    'transaction_id' => $data['reservation_id'] ?? ($data['id'] ?? uniqid()),
    'currency'       => $data['currency'] ?? 'EUR',
    'value'          => $amount,
    'items'          => [[
      'item_name' => $data['room'] ?? 'Prenotazione',
      'quantity'  => 1,
      'price'     => $amount
    ]],
    'bucket'         => $bucket,         // <-- crea dimensione evento "bucket" in GA4
    'method'         => 'HotelInCloud',
  ];
  if (!empty($gclid))  { $params['gclid']  = $gclid; }
  if (!empty($fbclid)) { $params['fbclid'] = $fbclid; }

  $payload = [
    'client_id' => $client_id,
    'events'    => [[
      'name'   => 'purchase',            // SEMPRE purchase
      'params' => $params
    ]]
  ];

  $url = 'https://www.google-analytics.com/mp/collect?measurement_id='
       . rawurlencode($measurement_id)
       . '&api_secret='
       . rawurlencode($api_secret);

  $res  = wp_remote_post($url, [
    'headers' => ['Content-Type'=>'application/json'],
    'body'    => wp_json_encode($payload),
    'timeout' => 15
  ]);
  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  hic_log(['GA4 inviato: purchase (bucket='.$bucket.')' => $payload, 'HTTP'=>$code]);
}