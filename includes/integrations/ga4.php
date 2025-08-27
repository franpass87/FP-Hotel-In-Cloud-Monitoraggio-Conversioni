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
    'vertical'       => 'hotel',
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

/**
 * GA4 dispatcher for HIC reservation schema
 */
function hic_dispatch_ga4_reservation($data) {
  // Validate configuration
  $measurement_id = hic_get_measurement_id();
  $api_secret = hic_get_api_secret();
  
  if (empty($measurement_id) || empty($api_secret)) {
    hic_log('GA4: measurement ID o API secret mancanti');
    return;
  }

  $client_id = (string) wp_generate_uuid4();
  $transaction_id = $data['transaction_id'];
  $value = floatval($data['value']);
  $currency = $data['currency'];

  $params = [
    'transaction_id' => $transaction_id,
    'currency' => $currency,
    'value' => $value,
    'items' => [[
      'item_id' => $data['accommodation_id'],
      'item_name' => $data['accommodation_name'],
      'quantity' => $data['guests']
    ]],
    'checkin' => $data['from_date'],
    'checkout' => $data['to_date'],
    'reservation_code' => $data['reservation_code'],
    'presence' => $data['presence'],
    'unpaid_balance' => $data['unpaid_balance'],
    'vertical' => 'hotel'
  ];

  // Add optional item_category only if room_name is available
  if (!empty($data['room_name'])) {
    $params['items'][0]['item_category'] = $data['room_name'];
  }

  // Remove null values to clean up the payload
  $params = array_filter($params, function($value) {
    return $value !== null && $value !== '';
  });

  $payload = [
    'client_id' => $client_id,
    'events' => [[
      'name' => 'purchase',
      'params' => $params
    ]]
  ];

  $url = 'https://www.google-analytics.com/mp/collect?measurement_id='
       . rawurlencode($measurement_id)
       . '&api_secret='
       . rawurlencode($api_secret);

  $res = wp_remote_post($url, [
    'headers' => ['Content-Type' => 'application/json'],
    'body' => wp_json_encode($payload),
    'timeout' => 15
  ]);
  
  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  hic_log(['GA4 HIC reservation sent' => ['transaction_id' => $transaction_id, 'value' => $value, 'currency' => $currency], 'HTTP' => $code]);
}