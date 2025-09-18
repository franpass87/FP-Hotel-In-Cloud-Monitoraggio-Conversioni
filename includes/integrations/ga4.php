<?php declare(strict_types=1);
namespace FpHic;
/**
 * Google Analytics 4 Integration
 */

if (!defined('ABSPATH')) exit;

/* ============ GA4 (purchase + bucket) ============ */
function hic_send_to_ga4($data, $gclid, $fbclid, $msclkid = '', $ttclid = '', $sid = null) {
  // Validate configuration
  $measurement_id = Helpers\hic_get_measurement_id();
  $api_secret = Helpers\hic_get_api_secret();
  
  if (empty($measurement_id) || empty($api_secret)) {
    hic_log('GA4: measurement ID o API secret mancanti');
    return false;
  }

  // Validate input data
  if (!is_array($data)) {
    hic_log('GA4: data is not an array');
    return false;
  }

  $bucket = Helpers\fp_normalize_bucket($gclid, $fbclid); // gads | fbads | organic
  $client_id = $gclid ?: ($fbclid ?: (string) wp_generate_uuid4());
  $sid = !empty($sid) ? sanitize_text_field($sid) : '';

  // Validate and normalize amount
  $amount = 0;
  if (isset($data['amount']) && (is_numeric($data['amount']) || is_string($data['amount']))) {
    $amount = Helpers\hic_normalize_price($data['amount']);
  }

  // Generate transaction ID using consistent extraction
  $transaction_id = Helpers\hic_extract_reservation_id($data);
  if (empty($transaction_id)) {
    $transaction_id = uniqid('hic_ga4_');
  }
  if ($sid !== '') {
    $client_id = $sid;
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

  if ($sid !== '') {
    $params['hic_sid'] = $sid;
  }
  
  if (!empty($gclid))   { $params['gclid']   = sanitize_text_field($gclid); }
  if (!empty($fbclid))  { $params['fbclid']  = sanitize_text_field($fbclid); }
  if (!empty($msclkid)) { $params['msclkid'] = sanitize_text_field($msclkid); }
  if (!empty($ttclid))  { $params['ttclid']  = sanitize_text_field($ttclid); }

  // Append UTM parameters if available
  if ($sid !== '') {
    $utm = Helpers\hic_get_utm_params_by_sid($sid);
    if (!empty($utm['utm_source']))   { $params['utm_source']   = sanitize_text_field($utm['utm_source']); }
    if (!empty($utm['utm_medium']))   { $params['utm_medium']   = sanitize_text_field($utm['utm_medium']); }
    if (!empty($utm['utm_campaign'])) { $params['utm_campaign'] = sanitize_text_field($utm['utm_campaign']); }
    if (!empty($utm['utm_content']))  { $params['utm_content']  = sanitize_text_field($utm['utm_content']); }
    if (!empty($utm['utm_term']))     { $params['utm_term']     = sanitize_text_field($utm['utm_term']); }
  }

  $payload = [
    'client_id' => $client_id,
    'events'    => [[
      'name'   => 'purchase',            // SEMPRE purchase
      'params' => $params
    ]]
  ];

  // Allow external modification of the GA4 payload
  $payload = apply_filters('hic_ga4_payload', $payload, $data, $gclid, $fbclid, $msclkid, $ttclid, $sid);

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

  $res = Helpers\hic_http_request($url, [
    'method'  => 'POST',
    'headers' => ['Content-Type'=>'application/json'],
    'body'    => $json_payload,
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
 * Send refund event to GA4 with negative value
 */
function hic_send_ga4_refund($data, $gclid, $fbclid, $msclkid = '', $ttclid = '', $sid = null) {
  $measurement_id = Helpers\hic_get_measurement_id();
  $api_secret = Helpers\hic_get_api_secret();

  if (empty($measurement_id) || empty($api_secret)) {
    hic_log('GA4 refund: measurement ID o API secret mancanti');
    return false;
  }

  if (!is_array($data)) {
    hic_log('GA4 refund: data is not an array');
    return false;
  }

  $bucket = Helpers\fp_normalize_bucket($gclid, $fbclid);
  $client_id = $gclid ?: ($fbclid ?: (string) wp_generate_uuid4());
  $sid = !empty($sid) ? sanitize_text_field($sid) : '';

  $amount = 0;
  if (isset($data['amount']) && (is_numeric($data['amount']) || is_string($data['amount']))) {
    $amount = -abs(Helpers\hic_normalize_price($data['amount']));
  }

  $transaction_id = Helpers\hic_extract_reservation_id($data);
  if (empty($transaction_id)) {
    $transaction_id = uniqid('hic_ga4_refund_');
  }
  if ($sid !== '') {
    $client_id = $sid;
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
    'bucket'         => $bucket,
    'method'         => 'HotelInCloud',
    'vertical'       => 'hotel',
  ];

  if ($sid !== '') {
    $params['hic_sid'] = $sid;
  }

  if (!empty($gclid))   { $params['gclid']   = sanitize_text_field($gclid); }
  if (!empty($fbclid))  { $params['fbclid']  = sanitize_text_field($fbclid); }
  if (!empty($msclkid)) { $params['msclkid'] = sanitize_text_field($msclkid); }
  if (!empty($ttclid))  { $params['ttclid']  = sanitize_text_field($ttclid); }

  if ($sid !== '') {
    $utm = Helpers\hic_get_utm_params_by_sid($sid);
    if (!empty($utm['utm_source']))   { $params['utm_source']   = sanitize_text_field($utm['utm_source']); }
    if (!empty($utm['utm_medium']))   { $params['utm_medium']   = sanitize_text_field($utm['utm_medium']); }
    if (!empty($utm['utm_campaign'])) { $params['utm_campaign'] = sanitize_text_field($utm['utm_campaign']); }
    if (!empty($utm['utm_content']))  { $params['utm_content']  = sanitize_text_field($utm['utm_content']); }
    if (!empty($utm['utm_term']))     { $params['utm_term']     = sanitize_text_field($utm['utm_term']); }
  }

  $payload = [
    'client_id' => $client_id,
    'events'    => [[
      'name'   => 'refund',
      'params' => $params
    ]]
  ];

  $payload = apply_filters('hic_ga4_refund_payload', $payload, $data, $gclid, $fbclid, $msclkid, $ttclid, $sid);

  $json_payload = wp_json_encode($payload);
  if ($json_payload === false) {
    hic_log('GA4 refund: Failed to encode JSON payload');
    return false;
  }

  $url = 'https://www.google-analytics.com/mp/collect?measurement_id='
       . rawurlencode($measurement_id)
       . '&api_secret='
       . rawurlencode($api_secret);

  $res = Helpers\hic_http_request($url, [
    'method'  => 'POST',
    'headers' => ['Content-Type'=>'application/json'],
    'body'    => $json_payload,
  ]);

  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  $log_msg = "GA4 dispatch: refund (bucket=$bucket) transaction_id=$transaction_id HTTP=$code";

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
function hic_dispatch_ga4_reservation($data, $sid = '') {
  // Validate configuration
  $measurement_id = Helpers\hic_get_measurement_id();
  $api_secret = Helpers\hic_get_api_secret();
  
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
  $value = Helpers\hic_normalize_price($data['value']);
  $currency = sanitize_text_field($data['currency']);

  $sid = !empty($sid) ? \sanitize_text_field((string) $sid) : '';
  if ($sid === '' && !empty($data['sid']) && is_scalar($data['sid'])) {
    $sid = \sanitize_text_field((string) $data['sid']);
  }
  if ($sid !== '') {
    $client_id = $sid;
    $transaction_id = $sid;
  }

  // Get tracking IDs for bucket normalization if available
  $gclid = '';
  $fbclid = '';
  $msclkid = '';
  $ttclid = '';
  $lookup_id = $sid !== '' ? $sid : $transaction_id;
  if (!empty($lookup_id)) {
    $tracking = Helpers\hic_get_tracking_ids_by_sid($lookup_id);
    $gclid = $tracking['gclid'] ?? '';
    $fbclid = $tracking['fbclid'] ?? '';
    $msclkid = $tracking['msclkid'] ?? '';
    $ttclid = $tracking['ttclid'] ?? '';
  }

  $bucket = Helpers\fp_normalize_bucket($gclid, $fbclid);

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
    'unpaid_balance' => Helpers\hic_normalize_price($data['unpaid_balance'] ?? 0),
    'bucket' => $bucket,             // Use normalized bucket based on attribution
    'vertical' => 'hotel'
  ];

  if (!empty($gclid))   { $params['gclid']   = sanitize_text_field($gclid); }
  if (!empty($fbclid))  { $params['fbclid']  = sanitize_text_field($fbclid); }
  if (!empty($msclkid)) { $params['msclkid'] = sanitize_text_field($msclkid); }
  if (!empty($ttclid))  { $params['ttclid']  = sanitize_text_field($ttclid); }

  // Attach UTM parameters if available
  $utm_lookup = $sid !== '' ? $sid : $transaction_id;
  $utm = Helpers\hic_get_utm_params_by_sid($utm_lookup);
  if (!empty($utm['utm_source']))   { $params['utm_source']   = sanitize_text_field($utm['utm_source']); }
  if (!empty($utm['utm_medium']))   { $params['utm_medium']   = sanitize_text_field($utm['utm_medium']); }
  if (!empty($utm['utm_campaign'])) { $params['utm_campaign'] = sanitize_text_field($utm['utm_campaign']); }
  if (!empty($utm['utm_content']))  { $params['utm_content']  = sanitize_text_field($utm['utm_content']); }
  if (!empty($utm['utm_term']))     { $params['utm_term']     = sanitize_text_field($utm['utm_term']); }

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

  // Allow external modification of the GA4 payload
  $payload = apply_filters('hic_ga4_payload', $payload, $data, $gclid, $fbclid, $msclkid, $ttclid, $sid);

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

  $res = Helpers\hic_http_request($url, [
    'method'  => 'POST',
    'headers' => ['Content-Type' => 'application/json'],
    'body'    => $json_payload,
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