<?php
/**
 * Facebook Meta CAPI Integration
 */

if (!defined('ABSPATH')) exit;

/* ============ Meta CAPI (Purchase + bucket) ============ */
function hic_send_to_fb($data, $gclid, $fbclid){
  if (!hic_get_fb_pixel_id() || !hic_get_fb_access_token()) {
    hic_log('FB Pixel non configurato.');
    return;
  }
  
  // Validate required data
  if (empty($data['email'])) {
    hic_log('FB: email mancante, evento non inviato');
    return;
  }
  
  $bucket   = hic_get_bucket($gclid, $fbclid); // gads | fbads | organic
  $event_id = $data['reservation_id'] ?? uniqid();

  // Validate and sanitize amount
  $amount = 0;
  if (isset($data['amount']) && is_numeric($data['amount'])) {
    $amount = floatval($data['amount']);
  }

  $user_data = [
    'em' => [ hash('sha256', strtolower(trim($data['email']))) ]
  ];
  
  // Add optional user data if available and not empty
  if (!empty($data['first_name'])) {
    $user_data['fn'] = [ hash('sha256', strtolower(trim($data['first_name']))) ];
  }
  if (!empty($data['last_name'])) {
    $user_data['ln'] = [ hash('sha256', strtolower(trim($data['last_name']))) ];
  }
  if (!empty($data['whatsapp'])) {
    $user_data['ph'] = [ hash('sha256', preg_replace('/\D/','', $data['whatsapp'])) ];
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
        'currency'     => $data['currency'] ?? 'EUR',
        'value'        => $amount,
        'order_id'     => $event_id,
        'bucket'       => $bucket,           // per creare custom conversions per fbads/organic/gads
        'content_name' => $data['room'] ?? 'Prenotazione',
        'vertical'     => 'hotel'
      ]
    ]]
  ];

  $url = 'https://graph.facebook.com/v19.0/' . hic_get_fb_pixel_id() . '/events?access_token=' . hic_get_fb_access_token();
  $res = wp_remote_post($url, [
    'headers' => ['Content-Type'=>'application/json'],
    'body'    => wp_json_encode($payload),
    'timeout' => 15
  ]);
  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  hic_log(['FB inviato: Purchase (bucket='.$bucket.')' => $payload, 'HTTP'=>$code]);
}

/**
 * Meta Pixel dispatcher for HIC reservation schema
 */
function hic_dispatch_pixel_reservation($data) {
  if (!hic_get_fb_pixel_id() || !hic_get_fb_access_token()) {
    hic_log('FB Pixel non configurato.');
    return;
  }
  
  // Validate required data
  if (empty($data['email']) || !hic_is_valid_email($data['email'])) {
    hic_log('FB: email mancante o non valida, evento non inviato');
    return;
  }
  
  $transaction_id = $data['transaction_id'];
  $value = floatval($data['value']);
  $currency = $data['currency'];

  $user_data = [
    'em' => [hash('sha256', strtolower(trim($data['email'])))]
  ];
  
  // Add optional user data if available and not empty
  if (!empty($data['guest_first_name'])) {
    $user_data['fn'] = [hash('sha256', strtolower(trim($data['guest_first_name'])))];
  }
  if (!empty($data['guest_last_name'])) {
    $user_data['ln'] = [hash('sha256', strtolower(trim($data['guest_last_name'])))];
  }
  if (!empty($data['phone'])) {
    $user_data['ph'] = [hash('sha256', preg_replace('/\D/', '', $data['phone']))];
  }

  $custom_data = [
    'currency' => $currency,
    'value' => $value,
    'content_ids' => [$data['accommodation_id']],
    'content_name' => $data['accommodation_name'],
    'num_items' => $data['guests'],
    'vertical' => 'hotel'
  ];

  // Add contents array if value > 0
  if ($value > 0) {
    $item_price = $data['guests'] > 0 ? $value / $data['guests'] : $value;
    $custom_data['contents'] = [[
      'id' => $data['accommodation_id'],
      'quantity' => $data['guests'],
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

  $url = 'https://graph.facebook.com/v19.0/' . hic_get_fb_pixel_id() . '/events?access_token=' . hic_get_fb_access_token();
  $res = wp_remote_post($url, [
    'headers' => ['Content-Type' => 'application/json'],
    'body' => wp_json_encode($payload),
    'timeout' => 15
  ]);
  
  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  hic_log(['FB HIC reservation sent' => ['transaction_id' => $transaction_id, 'value' => $value, 'currency' => $currency], 'HTTP' => $code]);
}