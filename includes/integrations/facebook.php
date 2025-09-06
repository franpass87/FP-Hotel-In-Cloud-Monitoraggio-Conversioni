<?php
/**
 * Facebook Meta CAPI Integration
 */

if (!defined('ABSPATH')) exit;

/* ============ Meta CAPI (Purchase + bucket) ============ */
function hic_send_to_fb($data, $gclid, $fbclid){
  if (!hic_get_fb_pixel_id() || !hic_get_fb_access_token()) {
    hic_log('FB Pixel non configurato.');
    return false;
  }
  
  // Validate required data
  if (!is_array($data)) {
    hic_log('FB: data is not an array');
    return false;
  }
  
  if (empty($data['email']) || !hic_is_valid_email($data['email'])) {
    hic_log('FB: email mancante o non valida, evento non inviato');
    return false;
  }
  
  $bucket = fp_normalize_bucket($gclid, $fbclid); // gads | fbads | organic
  
  // Generate event ID using consistent extraction
  $event_id = hic_extract_reservation_id($data);
  if (empty($event_id)) {
    $event_id = uniqid('fb_');
  }

  // Validate and sanitize amount
  $amount = 0;
  if (isset($data['amount']) && (is_numeric($data['amount']) || is_string($data['amount']))) {
    $amount = hic_normalize_price($data['amount']);
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

  $payload = [
    'data' => [[
      'event_name'       => 'Purchase',      // evento standard Meta
      'event_time'       => time(),
      'event_id'         => $event_id,
      'action_source'    => 'website',
      'event_source_url' => home_url(),
      'user_data' => $user_data,
      'custom_data' => [
        'currency'     => sanitize_text_field($data['currency'] ?? 'EUR'),
        'value'        => $amount,
        'order_id'     => $event_id,
        'bucket'       => $bucket,           // per creare custom conversions per fbads/organic/gads
        'content_name' => sanitize_text_field($data['room'] ?? 'Prenotazione'),
        'vertical'     => 'hotel'
      ]
    ]]
  ];

  // Validate JSON encoding
  $json_payload = wp_json_encode($payload);
  if ($json_payload === false) {
    hic_log('FB: Failed to encode JSON payload');
    return false;
  }

  $url = 'https://graph.facebook.com/v19.0/' . hic_get_fb_pixel_id() . '/events?access_token=' . hic_get_fb_access_token();
  $res = wp_remote_post($url, [
    'headers' => ['Content-Type'=>'application/json'],
    'body'    => $json_payload,
    'timeout' => 15
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
 * Meta Pixel dispatcher for HIC reservation schema
 */
function hic_dispatch_pixel_reservation($data) {
  if (!hic_get_fb_pixel_id() || !hic_get_fb_access_token()) {
    hic_log('FB HIC dispatch SKIPPED: Pixel ID o Access Token mancanti');
    return false;
  }
  
  // Validate input data
  if (!is_array($data)) {
    hic_log('FB HIC dispatch: data is not an array');
    return false;
  }
  
  // Validate required data
  if (empty($data['email']) || !hic_is_valid_email($data['email'])) {
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
  $value = hic_normalize_price($data['value']);
  $currency = sanitize_text_field($data['currency']);

  // Get gclid/fbclid for bucket normalization if available
  $gclid = '';
  $fbclid = '';
  if (!empty($data['transaction_id'])) {
    global $wpdb;
    $table = $wpdb->prefix . 'hic_gclids';
    
    // Check if table exists before querying
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    if ($table_exists) {
      // Try to find tracking data using transaction_id as sid
      $row = $wpdb->get_row($wpdb->prepare("SELECT gclid, fbclid FROM $table WHERE sid=%s ORDER BY id DESC LIMIT 1", $data['transaction_id']));
      if ($row) { 
        $gclid = $row->gclid ?: ''; 
        $fbclid = $row->fbclid ?: ''; 
      }
    }
  }

  $bucket = fp_normalize_bucket($gclid, $fbclid);

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

  $custom_data = [
    'currency' => $currency,
    'value' => $value,
    'content_ids' => [sanitize_text_field($data['accommodation_id'] ?? '')],
    'content_name' => sanitize_text_field($data['accommodation_name'] ?? 'Accommodation'),
    'num_items' => max(1, intval($data['guests'] ?? 1)),
    'bucket' => $bucket,             // Bucket attribution for custom conversions
    'vertical' => 'hotel'
  ];

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
      'event_time' => time(),
      'event_id' => $transaction_id,
      'action_source' => 'website',
      'event_source_url' => home_url(),
      'user_data' => $user_data,
      'custom_data' => $custom_data
    ]]
  ];

  // Validate JSON encoding
  $json_payload = wp_json_encode($payload);
  if ($json_payload === false) {
    hic_log('FB HIC dispatch: Failed to encode JSON payload');
    return false;
  }

  $url = 'https://graph.facebook.com/v19.0/' . hic_get_fb_pixel_id() . '/events?access_token=' . hic_get_fb_access_token();
  $res = wp_remote_post($url, [
    'headers' => ['Content-Type' => 'application/json'],
    'body' => $json_payload,
    'timeout' => 15
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