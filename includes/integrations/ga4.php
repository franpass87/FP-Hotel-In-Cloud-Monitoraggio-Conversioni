<?php declare(strict_types=1);
namespace FpHic;
/**
 * Google Analytics 4 Integration
 */

if (!defined('ABSPATH')) exit;

/**
 * Normalize complex values to achieve deterministic hashing.
 *
 * @param mixed $value
 * @return mixed
 */
function hic_ga4_normalize_value_for_hash($value) {
  if (is_array($value)) {
    $normalized = [];
    $keys = array_keys($value);
    sort($keys, SORT_STRING);
    foreach ($keys as $key) {
      $normalized[(string) $key] = hic_ga4_normalize_value_for_hash($value[$key]);
    }
    return $normalized;
  }

  if (is_object($value)) {
    return hic_ga4_normalize_value_for_hash(get_object_vars($value));
  }

  if (is_bool($value) || is_null($value) || is_scalar($value)) {
    return $value;
  }

  return (string) $value;
}

/**
 * Extract a validated email address from reservation data.
 *
 * @param array<string, mixed> $data
 */
function hic_ga4_extract_fingerprint_email(array $data): string {
  $email_fields = ['email', 'guest_email', 'customer_email', 'primary_email', 'contact_email', 'user_email'];
  foreach ($email_fields as $field) {
    if (!array_key_exists($field, $data)) {
      continue;
    }

    $value = $data[$field];
    if (!is_scalar($value)) {
      continue;
    }

    $candidate = trim((string) $value);
    if ($candidate === '' || !Helpers\hic_is_valid_email($candidate)) {
      continue;
    }

    $sanitized = sanitize_email($candidate);
    if (!is_string($sanitized) || $sanitized === '') {
      continue;
    }

    return strtolower($sanitized);
  }

  return '';
}

/**
 * Extract a normalized check-in date from reservation data.
 *
 * @param array<string, mixed> $data
 */
function hic_ga4_extract_fingerprint_checkin(array $data): string {
  $checkin_fields = ['from_date', 'checkin', 'check_in', 'arrival_date', 'checkin_date', 'start_date', 'start', 'date'];
  foreach ($checkin_fields as $field) {
    if (!array_key_exists($field, $data)) {
      continue;
    }

    $value = $data[$field];
    if (!is_scalar($value)) {
      continue;
    }

    $candidate = trim((string) $value);
    if ($candidate === '') {
      continue;
    }

    $timestamp = strtotime($candidate);
    if ($timestamp !== false) {
      return gmdate('Y-m-d', $timestamp);
    }

    $sanitized = sanitize_text_field($candidate);
    if ($sanitized !== '') {
      return $sanitized;
    }
  }

  return '';
}

/**
 * Resolve a stable GA4 transaction identifier.
 *
 * @param array<string, mixed> $data
 * @param string|int|null $sid
 */
function hic_ga4_resolve_transaction_id(array $data, $sid = ''): string {
  $normalized_sid = '';
  if (is_string($sid) || is_numeric($sid)) {
    $normalized_sid = sanitize_text_field((string) $sid);
  }

  $reservation_id = Helpers\hic_extract_reservation_id($data);
  if (!empty($reservation_id)) {
    $reservation_id = sanitize_text_field((string) $reservation_id);
    if ($reservation_id !== '') {
      return $reservation_id;
    }
  }

  if ($normalized_sid !== '') {
    return $normalized_sid;
  }

  $email = hic_ga4_extract_fingerprint_email($data);
  $checkin = hic_ga4_extract_fingerprint_checkin($data);
  if ($email !== '' && $checkin !== '') {
    $hash = substr(hash('sha256', $email . '|' . $checkin), 0, 32);
    return 'hic_tx_' . $hash;
  }

  $booking_uid = '';
  $candidate_fields = Helpers\hic_candidate_reservation_id_fields(Helpers\hic_booking_uid_primary_fields());
  foreach ($candidate_fields as $field) {
    if (!array_key_exists($field, $data)) {
      continue;
    }

    $value = $data[$field];
    if (!is_scalar($value)) {
      continue;
    }

    $candidate = trim((string) $value);
    if ($candidate !== '') {
      $booking_uid = Helpers\hic_booking_uid($data);
      break;
    }
  }

  if (!empty($booking_uid)) {
    $booking_uid = sanitize_text_field((string) $booking_uid);
    if ($booking_uid !== '') {
      return $booking_uid;
    }
  }

  $normalized_payload = hic_ga4_normalize_value_for_hash($data);
  $encoded = wp_json_encode($normalized_payload);
  if (is_string($encoded) && $encoded !== '') {
    $hash = substr(hash('sha256', $encoded), 0, 32);
    return 'hic_tx_' . $hash;
  }

  $serialized_payload = function_exists('maybe_serialize')
    ? maybe_serialize($normalized_payload)
    : serialize($normalized_payload);

  if (is_string($serialized_payload) && $serialized_payload !== '') {
    $hash = substr(hash('sha256', $serialized_payload), 0, 32);
    $fallback = 'hic_tx_' . $hash;

    // The prefix and hexadecimal digest survive sanitize_text_field(), so this cannot become empty.
    return sanitize_text_field($fallback);
  }

  $fallback_hash = substr(hash('sha256', 'hic_ga4_fallback'), 0, 32);
  return sanitize_text_field('hic_tx_' . $fallback_hash);
}

/* ============ GA4 (purchase + bucket) ============ */
function hic_send_to_ga4($data, $gclid, $fbclid, $msclkid = '', $ttclid = '', $gbraid = '', $wbraid = '', $sid = null) {
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
  $sid = (is_string($sid) || is_numeric($sid)) ? sanitize_text_field((string) $sid) : '';

  // Validate and normalize amount
  $amount = 0;
  $amount_source = null;
  if (isset($data['amount']) && (is_numeric($data['amount']) || is_string($data['amount']))) {
    $amount_source = $data['amount'];
  } elseif (isset($data['value']) && (is_numeric($data['value']) || is_string($data['value']))) {
    $amount_source = $data['value'];
  }
  if ($amount_source !== null) {
    $amount = Helpers\hic_normalize_price($amount_source);
  }

  // Generate transaction ID using consistent extraction
  $transaction_id = hic_ga4_resolve_transaction_id($data, $sid);
  $transaction_id = sanitize_text_field($transaction_id);
  if ($sid !== '') {
    $client_id = $sid;
  } elseif ($transaction_id !== '' && empty($gclid) && empty($fbclid)) {
    $client_id = $transaction_id;
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
  if (!empty($gbraid))  { $params['gbraid']  = sanitize_text_field($gbraid); }
  if (!empty($wbraid))  { $params['wbraid']  = sanitize_text_field($wbraid); }

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
  $payload = apply_filters('hic_ga4_payload', $payload, $data, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid, $sid);

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
function hic_send_ga4_refund($data, $gclid, $fbclid, $msclkid = '', $ttclid = '', $gbraid = '', $wbraid = '', $sid = null) {
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
  $sid = (is_string($sid) || is_numeric($sid)) ? sanitize_text_field((string) $sid) : '';

  $amount = 0;
  $amount_source = null;
  if (isset($data['amount']) && (is_numeric($data['amount']) || is_string($data['amount']))) {
    $amount_source = $data['amount'];
  } elseif (isset($data['value']) && (is_numeric($data['value']) || is_string($data['value']))) {
    $amount_source = $data['value'];
  }
  if ($amount_source !== null) {
    $amount = -abs(Helpers\hic_normalize_price($amount_source));
  }

  $transaction_id = hic_ga4_resolve_transaction_id($data, $sid);
  $transaction_id = sanitize_text_field($transaction_id);
  if ($sid !== '') {
    $client_id = $sid;
  } elseif ($transaction_id !== '' && empty($gclid) && empty($fbclid)) {
    $client_id = $transaction_id;
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
  if (!empty($gbraid))  { $params['gbraid']  = sanitize_text_field($gbraid); }
  if (!empty($wbraid))  { $params['wbraid']  = sanitize_text_field($wbraid); }

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

  $payload = apply_filters('hic_ga4_refund_payload', $payload, $data, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid, $sid);

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
  $required_fields = ['value', 'currency'];
  foreach ($required_fields as $field) {
    if (!isset($data[$field])) {
      hic_log("GA4 HIC dispatch: Missing required field '$field'");
      return false;
    }
  }

  $sid = (is_string($sid) || is_numeric($sid)) ? \sanitize_text_field((string) $sid) : '';
  if ($sid === '' && !empty($data['sid']) && is_scalar($data['sid'])) {
    $sid = \sanitize_text_field((string) $data['sid']);
  }

  $transaction_id = '';
  if (isset($data['transaction_id']) && is_scalar($data['transaction_id'])) {
    $transaction_id = \sanitize_text_field((string) $data['transaction_id']);
  }
  if ($transaction_id === '') {
    $transaction_id = \sanitize_text_field(hic_ga4_resolve_transaction_id($data, $sid));
  }
  if ($transaction_id === '') {
    hic_log('GA4 HIC dispatch: Unable to determine transaction_id');
    return false;
  }

  $data['transaction_id'] = $transaction_id;

  $client_id = $sid !== '' ? $sid : $transaction_id;
  if ($client_id === '') {
    $client_id = (string) wp_generate_uuid4();
  }

  $value = Helpers\hic_normalize_price($data['value']);
  $currency = sanitize_text_field($data['currency']);

  // Get tracking IDs for bucket normalization if available
  $gclid = '';
  $fbclid = '';
  $msclkid = '';
  $ttclid = '';
  $gbraid = '';
  $wbraid = '';
  $lookup_id = $sid !== '' ? $sid : $transaction_id;
  if (!empty($lookup_id)) {
    $tracking = Helpers\hic_get_tracking_ids_by_sid($lookup_id);
    $gclid = $tracking['gclid'] ?? '';
    $fbclid = $tracking['fbclid'] ?? '';
    $msclkid = $tracking['msclkid'] ?? '';
    $ttclid = $tracking['ttclid'] ?? '';
    $gbraid = $tracking['gbraid'] ?? '';
    $wbraid = $tracking['wbraid'] ?? '';
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

  if ($sid !== '') {
    $params['hic_sid'] = $sid;
  }

  if (!empty($gclid))   { $params['gclid']   = sanitize_text_field($gclid); }
  if (!empty($fbclid))  { $params['fbclid']  = sanitize_text_field($fbclid); }
  if (!empty($msclkid)) { $params['msclkid'] = sanitize_text_field($msclkid); }
  if (!empty($ttclid))  { $params['ttclid']  = sanitize_text_field($ttclid); }
  if (!empty($gbraid))  { $params['gbraid']  = sanitize_text_field($gbraid); }
  if (!empty($wbraid))  { $params['wbraid']  = sanitize_text_field($wbraid); }

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
  $payload = apply_filters('hic_ga4_payload', $payload, $data, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid, $sid);

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