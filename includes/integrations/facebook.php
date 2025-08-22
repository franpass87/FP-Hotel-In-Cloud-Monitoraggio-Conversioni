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
  $bucket   = hic_get_bucket($gclid, $fbclid); // gads | fbads | organic
  $event_id = $data['reservation_id'] ?? uniqid();

  $payload = [
    'data' => [[
      'event_name'       => 'Purchase',      // evento standard Meta
      'event_time'       => time(),
      'event_id'         => $event_id,
      'action_source'    => 'website',
      'event_source_url' => home_url(),
      'user_data' => [
        'em' => [ hash('sha256', strtolower(trim($data['email']      ?? ''))) ],
        'fn' => [ hash('sha256', strtolower(trim($data['first_name'] ?? ''))) ],
        'ln' => [ hash('sha256', strtolower(trim($data['last_name']  ?? ''))) ],
        // opzionale: usa whatsapp come telefono, normalizzato numerico
        'ph' => !empty($data['whatsapp']) ? [ hash('sha256', preg_replace('/\D/','', $data['whatsapp'])) ] : []
      ],
      'custom_data' => [
        'currency'     => $data['currency'] ?? 'EUR',
        'value'        => isset($data['amount']) ? floatval($data['amount']) : 0,
        'order_id'     => $event_id,
        'bucket'       => $bucket,           // per creare custom conversions per fbads/organic/gads
        'content_name' => $data['room'] ?? 'Prenotazione'
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