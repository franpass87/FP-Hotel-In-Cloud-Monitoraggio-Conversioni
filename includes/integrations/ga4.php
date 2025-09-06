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
    return false;
  }

  // Validate input data
  if (!is_array($data)) {
    hic_log('GA4: data is not an array');
    return false;
  }

  $bucket = fp_normalize_bucket($gclid, $fbclid); // gads | fbads | organic
  $client_id = $gclid ?: ($fbclid ?: (string) wp_generate_uuid4());

  // Validate and normalize amount
  $amount = 0;
  if (isset($data['amount']) && (is_numeric($data['amount']) || is_string($data['amount']))) {
    $amount = hic_normalize_price($data['amount']);
  }

  // Generate transaction ID using consistent extraction
  $transaction_id = hic_extract_reservation_id($data);
  if (empty($transaction_id)) {
    $transaction_id = uniqid('hic_ga4_');
  }

  $params = [
    'transaction_id' => $transaction_id,
    'currency'       => sanitize_text_field($data['currency'] ?? 'EUR'),
    'value'          => $amount,
    'items'          => [[
      'item_name' => sanitize_text_field($data['room'] ?? $data['accommodation_name'] ?? 'Prenotazione'),
      'quantity'  => 1,
      'price'     => $amount
    ]],
    'bucket'         => $bucket,         // <-- crea dimensione evento "bucket" in GA4
    'method'         => 'HotelInCloud',
    'vertical'       => 'hotel',
  ];
  
  if (!empty($gclid))  { $params['gclid']  = sanitize_text_field($gclid); }
  if (!empty($fbclid)) { $params['fbclid'] = sanitize_text_field($fbclid); }

  $payload = [
    'client_id' => $client_id,
    'events'    => [[
      'name'   => 'purchase',            // SEMPRE purchase
      'params' => $params
    ]]
  ];

  // Validate JSON encoding
  $json_payload = wp_json_encode($payload);
  if ($json_payload === false) {
    hic_log('GA4: Failed to encode JSON payload');
    return false;
  }

  $url = 'https://www.google-analytics.com/mp/collect?measurement_id='
       . rawurlencode($measurement_id)
       . '&api_secret='
       . rawurlencode($api_secret);

  $res = wp_remote_post($url, [
    'headers' => ['Content-Type'=>'application/json'],
    'body'    => $json_payload,
    'timeout' => 15
  ]);
  
  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  $log_msg = "GA4 dispatch: purchase (bucket=$bucket) transaction_id=$transaction_id HTTP=$code";
  
  if (is_wp_error($res)) {
    $log_msg .= " ERROR: " . $res->get_error_message();
    hic_log($log_msg);
    return false;
  }
  
  if ($code !== 204 && $code !== 200) {
    $response_body = wp_remote_retrieve_body($res);
    $log_msg .= " RESPONSE: " . substr($response_body, 0, 200);
    hic_log($log_msg);
    return false;
  }
  
  hic_log($log_msg);
  return true;
}

/**
 * GA4 dispatcher for HIC reservation schema
 */
function hic_dispatch_ga4_reservation($data) {
  // Validate configuration
  $measurement_id = hic_get_measurement_id();
  $api_secret = hic_get_api_secret();
  
  if (empty($measurement_id) || empty($api_secret)) {
    hic_log('GA4 HIC dispatch SKIPPED: measurement ID o API secret mancanti');
    return false;
  }

  // Validate input data
  if (!is_array($data)) {
    hic_log('GA4 HIC dispatch: data is not an array');
    return false;
  }

  // Validate required fields
  $required_fields = ['transaction_id', 'value', 'currency'];
  foreach ($required_fields as $field) {
    if (!isset($data[$field])) {
      hic_log("GA4 HIC dispatch: Missing required field '$field'");
      return false;
    }
  }

  $client_id = (string) wp_generate_uuid4();
  $transaction_id = sanitize_text_field($data['transaction_id']);
  $value = hic_normalize_price($data['value']);
  $currency = sanitize_text_field($data['currency']);

  // Get gclid/fbclid for bucket normalization if available
  $gclid = '';
  $fbclid = '';
  if (!empty($data['transaction_id'])) {
    $tracking = hic_get_tracking_ids_by_sid($data['transaction_id']);
    $gclid = $tracking['gclid'] ?? '';
    $fbclid = $tracking['fbclid'] ?? '';
  }

  $bucket = fp_normalize_bucket($gclid, $fbclid);

  $params = [
    'transaction_id' => $transaction_id,
    'currency' => $currency,
    'value' => $value,
    'items' => [[
      'item_id' => sanitize_text_field($data['accommodation_id'] ?? ''),
      'item_name' => sanitize_text_field($data['accommodation_name'] ?? 'Accommodation'),
      'quantity' => max(1, intval($data['guests'] ?? 1)),
      'price' => $value
    ]],
    'checkin' => sanitize_text_field($data['from_date'] ?? ''),
    'checkout' => sanitize_text_field($data['to_date'] ?? ''),
    'reservation_code' => sanitize_text_field($data['reservation_code'] ?? ''),
    'presence' => sanitize_text_field($data['presence'] ?? ''),
    'unpaid_balance' => hic_normalize_price($data['unpaid_balance'] ?? 0),
    'bucket' => $bucket,             // Use normalized bucket based on attribution
    'vertical' => 'hotel'
  ];

  // Add optional item_category only if room_name is available
  if (!empty($data['room_name'])) {
    $params['items'][0]['item_category'] = sanitize_text_field($data['room_name']);
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

  // Validate JSON encoding
  $json_payload = wp_json_encode($payload);
  if ($json_payload === false) {
    hic_log('GA4 HIC dispatch: Failed to encode JSON payload');
    return false;
  }

  $url = 'https://www.google-analytics.com/mp/collect?measurement_id='
       . rawurlencode($measurement_id)
       . '&api_secret='
       . rawurlencode($api_secret);

  $res = wp_remote_post($url, [
    'headers' => ['Content-Type' => 'application/json'],
    'body' => $json_payload,
    'timeout' => 15
  ]);
  
  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  $log_msg = "GA4 HIC dispatch: bucket=$bucket vertical=hotel transaction_id=$transaction_id value=$value $currency price_in_items={$params['items'][0]['price']} HTTP=$code";
  
  if (is_wp_error($res)) {
    $log_msg .= " ERROR: " . $res->get_error_message();
    hic_log($log_msg);
    return false;
  }
  
  if ($code !== 204 && $code !== 200) {
    $response_body = wp_remote_retrieve_body($res);
    $log_msg .= " RESPONSE: " . substr($response_body, 0, 200);
    hic_log($log_msg);
    return false;
  }
  
  hic_log($log_msg);
  return true;
}