<?php declare(strict_types=1);
namespace FpHic;
/**
 * Facebook Meta CAPI Integration
 */

if (!defined('ABSPATH')) exit;

/* ============ Meta CAPI (Purchase + bucket) ============ */
function hic_send_to_fb($data, $gclid, $fbclid, $msclkid = '', $ttclid = ''){
  if (!Helpers\hic_get_fb_pixel_id() || !Helpers\hic_get_fb_access_token()) {
    hic_log('FB Pixel non configurato.');
    return false;
  }
  
  // Validate required data
  if (!is_array($data)) {
    hic_log('FB: data is not an array');
    return false;
  }
  
  if (empty($data['email']) || !Helpers\hic_is_valid_email($data['email'])) {
    hic_log('FB: email mancante o non valida, evento non inviato');
    return false;
  }
  
  $bucket = Helpers\fp_normalize_bucket($gclid, $fbclid); // gads | fbads | organic
  
  // Generate event ID using consistent extraction
  $event_id = Helpers\hic_extract_reservation_id($data);
  if (empty($event_id)) {
    $event_id = uniqid('fb_');
  }

  // Validate and sanitize amount
  $amount = 0;
  if (isset($data['amount']) && (is_numeric($data['amount']) || is_string($data['amount']))) {
    $amount = Helpers\hic_normalize_price($data['amount']);
  }

  $user_data = [
    'em' => [ hash('sha256', strtolower(trim($data['email']))) ]
  ];
  
  // Add optional user data if available and not empty
  if (!empty($data['first_name']) && is_string($data['first_name'])) {
    $user_data['fn'] = [ hash('sha256', strtolower(trim($data['first_name']))) ];
  }
  if (!empty($data['last_name']) && is_string($data['last_name'])) {
    $user_data['ln'] = [ hash('sha256', strtolower(trim($data['last_name']))) ];
  }
  $phone = $data['whatsapp'] ?? $data['phone'] ?? '';
  if (!empty($phone) && is_string($phone)) {
    $user_data['ph'] = [ hash('sha256', preg_replace('/\D/','', $phone)) ];
  }

  if ($fbclid) {
    $fbc = 'fb.1.' . current_time('timestamp') . '.' . $fbclid;
    $user_data['fbc'] = [$fbc];
  }
  if (!empty($_COOKIE['_fbp'])) {
    $user_data['fbp'] = [sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) )];
  }

  $custom_data = [
    'currency'     => sanitize_text_field($data['currency'] ?? 'EUR'),
    'value'        => $amount,
    'order_id'     => $event_id,
    'bucket'       => $bucket,           // per creare custom conversions per fbads/organic/gads
    'content_name' => sanitize_text_field($data['room'] ?? $data['accommodation_name'] ?? 'Prenotazione'),
    'vertical'     => 'hotel'
  ];

  if (!empty($gclid))   { $custom_data['gclid']   = sanitize_text_field($gclid); }
  if (!empty($fbclid))  { $custom_data['fbclid']  = sanitize_text_field($fbclid); }
  if (!empty($msclkid)) { $custom_data['msclkid'] = sanitize_text_field($msclkid); }
  if (!empty($ttclid))  { $custom_data['ttclid']  = sanitize_text_field($ttclid); }

  // Attach UTM parameters if available
  if (!empty($data['sid'])) {
    $utm = Helpers\hic_get_utm_params_by_sid($data['sid']);
    if (!empty($utm['utm_source']))   { $custom_data['utm_source']   = sanitize_text_field($utm['utm_source']); }
    if (!empty($utm['utm_medium']))   { $custom_data['utm_medium']   = sanitize_text_field($utm['utm_medium']); }
    if (!empty($utm['utm_campaign'])) { $custom_data['utm_campaign'] = sanitize_text_field($utm['utm_campaign']); }
    if (!empty($utm['utm_content']))  { $custom_data['utm_content']  = sanitize_text_field($utm['utm_content']); }
    if (!empty($utm['utm_term']))     { $custom_data['utm_term']     = sanitize_text_field($utm['utm_term']); }
  }

  $payload = [
    'data' => [[
      'event_name'       => 'Purchase',      // evento standard Meta
      'event_time'       => current_time('timestamp'),
      'event_id'         => $event_id,
      'action_source'    => 'website',
      'event_source_url' => home_url(),
      'client_ip_address'  => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
      'client_user_agent'  => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
      'user_data'        => $user_data,
      'custom_data'      => $custom_data
    ]]
  ];

  // Allow payload customization before encoding
  $payload = apply_filters('hic_fb_payload', $payload, $data, $gclid, $fbclid, $msclkid, $ttclid);

  // Validate JSON encoding
  $json_payload = wp_json_encode($payload);
  if ($json_payload === false) {
    hic_log('FB: Failed to encode JSON payload');
    return false;
  }

  $url = 'https://graph.facebook.com/v19.0/' . Helpers\hic_get_fb_pixel_id() . '/events?access_token=' . Helpers\hic_get_fb_access_token();
  $res = Helpers\hic_http_request($url, [
    'method'  => 'POST',
    'headers' => ['Content-Type'=>'application/json'],
    'body'    => $json_payload,
  ]);
  
  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  $log_msg = "FB dispatch: Purchase (bucket=$bucket) event_id=$event_id value=$amount HTTP=$code";
  
  if (is_wp_error($res)) {
    $log_msg .= " ERROR: " . $res->get_error_message();
    hic_log($log_msg);
    return false;
  }
  
  if ($code !== 200) {
    $response_body = wp_remote_retrieve_body($res);
    $log_msg .= " RESPONSE: " . substr($response_body, 0, 200);
    hic_log($log_msg);
    return false;
  }
  
  // Parse response to check for errors
  $response_body = wp_remote_retrieve_body($res);
  $response_data = json_decode($response_body, true);
  
  if (json_last_error() !== JSON_ERROR_NONE) {
    hic_log('FB: Invalid JSON response: ' . json_last_error_msg());
    return false;
  }
  
  if (isset($response_data['error'])) {
    hic_log('FB: API Error: ' . json_encode($response_data['error']));
    return false;
  }
  
  hic_log($log_msg);
  return true;
}

/**
 * Send refund event to Meta with negative value
 */
function hic_send_fb_refund($data, $gclid, $fbclid, $msclkid = '', $ttclid = ''){
  if (!Helpers\hic_get_fb_pixel_id() || !Helpers\hic_get_fb_access_token()) {
    hic_log('FB refund: Pixel ID o Access Token mancanti.');
    return false;
  }

  if (!is_array($data)) {
    hic_log('FB refund: data is not an array');
    return false;
  }

  if (empty($data['email']) || !Helpers\hic_is_valid_email($data['email'])) {
    hic_log('FB refund: email mancante o non valida, evento non inviato');
    return false;
  }

  $bucket = Helpers\fp_normalize_bucket($gclid, $fbclid);

  $event_id = Helpers\hic_extract_reservation_id($data);
  if (empty($event_id)) {
    $event_id = uniqid('fb_refund_');
  }

  $amount = 0;
  if (isset($data['amount']) && (is_numeric($data['amount']) || is_string($data['amount']))) {
    $amount = -abs(Helpers\hic_normalize_price($data['amount']));
  }

  $user_data = [
    'em' => [ hash('sha256', strtolower(trim($data['email']))) ]
  ];

  if (!empty($data['first_name']) && is_string($data['first_name'])) {
    $user_data['fn'] = [ hash('sha256', strtolower(trim($data['first_name']))) ];
  }
  if (!empty($data['last_name']) && is_string($data['last_name'])) {
    $user_data['ln'] = [ hash('sha256', strtolower(trim($data['last_name']))) ];
  }
  $phone = $data['whatsapp'] ?? $data['phone'] ?? '';
  if (!empty($phone) && is_string($phone)) {
    $user_data['ph'] = [ hash('sha256', preg_replace('/\D/','', $phone)) ];
  }

  if ($fbclid) {
    $fbc = 'fb.1.' . current_time('timestamp') . '.' . $fbclid;
    $user_data['fbc'] = [$fbc];
  }
  if (!empty($_COOKIE['_fbp'])) {
    $user_data['fbp'] = [sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) )];
  }

  $custom_data = [
    'currency'     => sanitize_text_field($data['currency'] ?? 'EUR'),
    'value'        => $amount,
    'order_id'     => $event_id,
    'bucket'       => $bucket,
    'content_name' => sanitize_text_field($data['room'] ?? $data['accommodation_name'] ?? 'Prenotazione'),
    'vertical'     => 'hotel'
  ];

  if (!empty($gclid))   { $custom_data['gclid']   = sanitize_text_field($gclid); }
  if (!empty($fbclid))  { $custom_data['fbclid']  = sanitize_text_field($fbclid); }
  if (!empty($msclkid)) { $custom_data['msclkid'] = sanitize_text_field($msclkid); }
  if (!empty($ttclid))  { $custom_data['ttclid']  = sanitize_text_field($ttclid); }

  if (!empty($data['sid'])) {
    $utm = Helpers\hic_get_utm_params_by_sid($data['sid']);
    if (!empty($utm['utm_source']))   { $custom_data['utm_source']   = sanitize_text_field($utm['utm_source']); }
    if (!empty($utm['utm_medium']))   { $custom_data['utm_medium']   = sanitize_text_field($utm['utm_medium']); }
    if (!empty($utm['utm_campaign'])) { $custom_data['utm_campaign'] = sanitize_text_field($utm['utm_campaign']); }
    if (!empty($utm['utm_content']))  { $custom_data['utm_content']  = sanitize_text_field($utm['utm_content']); }
    if (!empty($utm['utm_term']))     { $custom_data['utm_term']     = sanitize_text_field($utm['utm_term']); }
  }

  $payload = [
    'data' => [[
      'event_name'       => 'Refund',
      'event_time'       => current_time('timestamp'),
      'event_id'         => $event_id,
      'action_source'    => 'website',
      'event_source_url' => home_url(),
      'client_ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '',
      'client_user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
      'user_data'        => $user_data,
      'custom_data'      => $custom_data
    ]]
  ];

  $payload = apply_filters('hic_fb_refund_payload', $payload, $data, $gclid, $fbclid, $msclkid, $ttclid);

  $json_payload = wp_json_encode($payload);
  if ($json_payload === false) {
    hic_log('FB refund: Failed to encode JSON payload');
    return false;
  }

  $url = 'https://graph.facebook.com/v19.0/' . Helpers\hic_get_fb_pixel_id() . '/events?access_token=' . Helpers\hic_get_fb_access_token();
  $res = Helpers\hic_http_request($url, [
    'method'  => 'POST',
    'headers' => ['Content-Type'=>'application/json'],
    'body'    => $json_payload,
  ]);

  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  $log_msg = "FB dispatch: Refund (bucket=$bucket) event_id=$event_id value=$amount HTTP=$code";

  if (is_wp_error($res)) {
    $log_msg .= " ERROR: " . $res->get_error_message();
    hic_log($log_msg);
    return false;
  }

  if ($code !== 200) {
    $response_body = wp_remote_retrieve_body($res);
    $log_msg .= " RESPONSE: " . substr($response_body, 0, 200);
    hic_log($log_msg);
    return false;
  }

  $response_body = wp_remote_retrieve_body($res);
  $response_data = json_decode($response_body, true);
  if (json_last_error() !== JSON_ERROR_NONE) {
    hic_log('FB refund: Invalid JSON response: ' . json_last_error_msg());
    return false;
  }
  if (isset($response_data['error'])) {
    hic_log('FB refund: API Error: ' . json_encode($response_data['error']));
    return false;
  }

  hic_log($log_msg);
  return true;
}

/**
 * Meta Pixel dispatcher for HIC reservation schema
 */
function hic_dispatch_pixel_reservation($data, $sid = '') {
  if (!Helpers\hic_get_fb_pixel_id() || !Helpers\hic_get_fb_access_token()) {
    hic_log('FB HIC dispatch SKIPPED: Pixel ID o Access Token mancanti');
    return false;
  }
  
  // Validate input data
  if (!is_array($data)) {
    hic_log('FB HIC dispatch: data is not an array');
    return false;
  }
  
  // Validate required data
  if (empty($data['email']) || !Helpers\hic_is_valid_email($data['email'])) {
    hic_log('FB HIC dispatch: email mancante o non valida, evento non inviato');
    return false;
  }
  
  // Validate required fields
  $required_fields = ['transaction_id', 'value', 'currency'];
  foreach ($required_fields as $field) {
    if (!isset($data[$field])) {
      hic_log("FB HIC dispatch: Missing required field '$field'");
      return false;
    }
  }
  
  $transaction_id = sanitize_text_field($data['transaction_id']);
  $value = Helpers\hic_normalize_price($data['value']);
  $currency = sanitize_text_field($data['currency']);

  $sid = !empty($sid) ? \sanitize_text_field((string) $sid) : '';
  if ($sid === '' && !empty($data['sid']) && is_scalar($data['sid'])) {
    $sid = \sanitize_text_field((string) $data['sid']);
  }
  if ($sid !== '') {
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

  $user_data = [
    'em' => [hash('sha256', strtolower(trim($data['email'])))]
  ];
  
  // Add optional user data if available and not empty
  if (!empty($data['guest_first_name']) && is_string($data['guest_first_name'])) {
    $user_data['fn'] = [hash('sha256', strtolower(trim($data['guest_first_name'])))];
  }
  if (!empty($data['guest_last_name']) && is_string($data['guest_last_name'])) {
    $user_data['ln'] = [hash('sha256', strtolower(trim($data['guest_last_name'])))];
  }
  if (!empty($data['phone']) && is_string($data['phone'])) {
    $user_data['ph'] = [hash('sha256', preg_replace('/\D/', '', $data['phone']))];
  }
  if ($fbclid) {
    $fbc = 'fb.1.' . current_time('timestamp') . '.' . $fbclid;
    $user_data['fbc'] = [$fbc];
  }
  if (!empty($_COOKIE['_fbp'])) {
    $user_data['fbp'] = [sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) )];
  }
  
  $custom_data = [
    'currency' => $currency,
    'value' => $value,
    'content_ids' => [sanitize_text_field($data['accommodation_id'] ?? '')],
    'content_name' => sanitize_text_field($data['accommodation_name'] ?? 'Accommodation'),
    'num_items' => max(1, intval($data['guests'] ?? 1)),
    'bucket' => $bucket,             // Bucket attribution for custom conversions
    'vertical' => 'hotel'
  ];

  if (!empty($gclid))   { $custom_data['gclid']   = sanitize_text_field($gclid); }
  if (!empty($fbclid))  { $custom_data['fbclid']  = sanitize_text_field($fbclid); }
  if (!empty($msclkid)) { $custom_data['msclkid'] = sanitize_text_field($msclkid); }
  if (!empty($ttclid))  { $custom_data['ttclid']  = sanitize_text_field($ttclid); }

  // Add UTM parameters if available
  $utm_lookup = $sid !== '' ? $sid : $transaction_id;
  $utm = Helpers\hic_get_utm_params_by_sid($utm_lookup);
  if (!empty($utm['utm_source']))   { $custom_data['utm_source']   = sanitize_text_field($utm['utm_source']); }
  if (!empty($utm['utm_medium']))   { $custom_data['utm_medium']   = sanitize_text_field($utm['utm_medium']); }
  if (!empty($utm['utm_campaign'])) { $custom_data['utm_campaign'] = sanitize_text_field($utm['utm_campaign']); }
  if (!empty($utm['utm_content']))  { $custom_data['utm_content']  = sanitize_text_field($utm['utm_content']); }
  if (!empty($utm['utm_term']))     { $custom_data['utm_term']     = sanitize_text_field($utm['utm_term']); }

  // Add contents array if value > 0
  if ($value > 0) {
    $guests = max(1, intval($data['guests'] ?? 1));
    $item_price = $guests > 0 ? $value / $guests : $value;
    $custom_data['contents'] = [[
      'id' => sanitize_text_field($data['accommodation_id'] ?? ''),
      'quantity' => $guests,
      'item_price' => $item_price
    ]];
  }

  $payload = [
    'data' => [[
      'event_name' => 'Purchase',
      'event_time' => current_time('timestamp'),
      'event_id' => $transaction_id,
      'action_source' => 'website',
      'event_source_url' => home_url(),
      'client_ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '',
      'client_user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
      'user_data' => $user_data,
      'custom_data' => $custom_data
    ]]
  ];

  // Allow payload customization before encoding
  $payload = apply_filters('hic_fb_payload', $payload, $data, $gclid, $fbclid, $msclkid, $ttclid);

  // Validate JSON encoding
  $json_payload = wp_json_encode($payload);
  if ($json_payload === false) {
    hic_log('FB HIC dispatch: Failed to encode JSON payload');
    return false;
  }

  $url = 'https://graph.facebook.com/v19.0/' . Helpers\hic_get_fb_pixel_id() . '/events?access_token=' . Helpers\hic_get_fb_access_token();
  $res = Helpers\hic_http_request($url, [
    'method'  => 'POST',
    'headers' => ['Content-Type' => 'application/json'],
    'body'    => $json_payload,
  ]);
  
  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  $log_msg = "FB HIC dispatch: bucket=$bucket transaction_id=$transaction_id value=$value $currency HTTP=$code";
  
  if (is_wp_error($res)) {
    $log_msg .= " ERROR: " . $res->get_error_message();
    hic_log($log_msg);
    return false;
  }
  
  if ($code !== 200) {
    $response_body = wp_remote_retrieve_body($res);
    $log_msg .= " RESPONSE: " . substr($response_body, 0, 200);
    hic_log($log_msg);
    return false;
  }
  
  // Parse response to check for errors
  $response_body = wp_remote_retrieve_body($res);
  $response_data = json_decode($response_body, true);
  
  if (json_last_error() !== JSON_ERROR_NONE) {
    hic_log('FB HIC dispatch: Invalid JSON response: ' . json_last_error_msg());
    return false;
  }
  
  if (isset($response_data['error'])) {
    hic_log('FB HIC dispatch: API Error: ' . json_encode($response_data['error']));
    return false;
  }
  
  hic_log($log_msg);
  return true;
}