<?php
/**
 * Brevo Integration
 */

if (!defined('ABSPATH')) exit;

/* ============ Brevo: aggiorna contatto ============ */
function hic_send_brevo_contact($data, $gclid, $fbclid){
  if (!hic_get_brevo_api_key()) { hic_log('Brevo disabilitato (API key vuota).'); return; }

  $email = $data['email'] ?? null;
  if (!$email) { hic_log('Nessuna email nel payload → skip Brevo contact.'); return; }

  // Lista in base alla lingua (supporta sia 'lingua' sia 'lang')
  $lang = $data['lingua'] ?? ($data['lang'] ?? '');
  $list_ids = [];
  if (strtolower($lang) === 'en') { $list_ids[] = intval(hic_get_brevo_list_eng()); } else { $list_ids[] = intval(hic_get_brevo_list_ita()); }

  $body = [
    'email' => $email,
    'attributes' => [
      'FIRSTNAME' => $data['first_name'] ?? '',
      'LASTNAME'  => $data['last_name'] ?? '',
      'RESVID'    => $data['reservation_id'] ?? ($data['id'] ?? ''),
      'GCLID'     => $gclid  ?? '',
      'FBCLID'    => $fbclid ?? '',
      'DATE'      => $data['date'] ?? date('Y-m-d'),
      'AMOUNT'    => isset($data['amount']) ? floatval($data['amount']) : 0,
      'CURRENCY'  => $data['currency'] ?? 'EUR',
      'WHATSAPP'  => $data['whatsapp'] ?? '',
      'LINGUA'    => $lang
    ],
    'listIds'       => $list_ids,
    'updateEnabled' => true
  ];

  $res = wp_remote_post('https://api.brevo.com/v3/contacts', [
    'headers' => [
      'accept'       => 'application/json',
      'api-key'      => hic_get_brevo_api_key(),
      'content-type' => 'application/json'
    ],
    'body'    => wp_json_encode($body),
    'timeout' => 15
  ]);
  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  hic_log(['Brevo contact inviato' => $body, 'HTTP'=>$code]);
}

/* ============ Brevo: evento personalizzato (purchase + bucket) ============ */
function hic_send_brevo_event($data, $gclid, $fbclid){
  if (!hic_get_brevo_api_key()) { return; }
  $bucket = hic_get_bucket($gclid, $fbclid);

  $body = [
    'event' => 'purchase', // puoi rinominare in 'hic_booking' se preferisci
    'email' => $data['email'] ?? '',
    'properties' => [
      'reservation_id' => $data['reservation_id'] ?? ($data['id'] ?? ''),
      'amount'         => isset($data['amount']) ? floatval($data['amount']) : 0,
      'currency'       => $data['currency'] ?? 'EUR',
      'date'           => $data['date'] ?? date('Y-m-d'),
      'whatsapp'       => $data['whatsapp'] ?? '',
      'lingua'         => $data['lingua'] ?? ($data['lang'] ?? ''),
      'firstname'      => $data['first_name'] ?? '',
      'lastname'       => $data['last_name'] ?? '',
      'bucket'         => $bucket
    ]
  ];

  $res = wp_remote_post('https://in-automate.brevo.com/api/v2/trackEvent', [
    'headers' => [
      'accept'       => 'application/json',
      'content-type' => 'application/json',
      'api-key'      => hic_get_brevo_api_key()
    ],
    'body'    => wp_json_encode($body),
    'timeout' => 15
  ]);
  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  hic_log(['Brevo event: purchase (bucket='.$bucket.')' => $body, 'HTTP'=>$code]);
}

/**
 * Brevo dispatcher for HIC reservation schema
 */
function hic_dispatch_brevo_reservation($data) {
  if (!hic_get_brevo_api_key()) { 
    hic_log('Brevo disabilitato (API key vuota).'); 
    return; 
  }

  $email = $data['email'];
  if (!hic_is_valid_email($email)) { 
    hic_log('Brevo: email mancante o non valida, skip contatto.'); 
    return; 
  }

  // Determine list based on language
  $language = $data['language'];
  $list_ids = [];
  
  if (in_array($language, ['it'])) {
    $list_id = intval(hic_get_brevo_list_it());
    if ($list_id > 0) $list_ids[] = $list_id;
  } elseif (in_array($language, ['en'])) {
    $list_id = intval(hic_get_brevo_list_en());
    if ($list_id > 0) $list_ids[] = $list_id;
  } else {
    $list_id = intval(hic_get_brevo_list_default());
    if ($list_id > 0) $list_ids[] = $list_id;
  }

  $attributes = [
    'FIRSTNAME' => $data['guest_first_name'],
    'LASTNAME' => $data['guest_last_name'],
    'PHONE' => $data['phone'],
    'LANGUAGE' => $language,
    'HIC_RES_ID' => $data['transaction_id'],
    'HIC_RES_CODE' => $data['reservation_code'],
    'HIC_FROM' => $data['from_date'],
    'HIC_TO' => $data['to_date'],
    'HIC_GUESTS' => $data['guests'],
    'HIC_ROOM' => $data['accommodation_name'],
    'HIC_PRICE' => $data['original_price']
  ];

  // Remove empty values to clean up
  $attributes = array_filter($attributes, function($value) {
    return $value !== null && $value !== '';
  });

  $body = [
    'email' => $email,
    'attributes' => $attributes,
    'listIds' => $list_ids,
    'updateEnabled' => true
  ];

  // Add marketing opt-in only if default is enabled
  if (hic_get_brevo_optin_default()) {
    $body['emailBlacklisted'] = false;
  }

  $res = wp_remote_post('https://api.brevo.com/v3/contacts', [
    'headers' => [
      'accept' => 'application/json',
      'api-key' => hic_get_brevo_api_key(),
      'content-type' => 'application/json'
    ],
    'body' => wp_json_encode($body),
    'timeout' => 15
  ]);
  
  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  hic_log(['Brevo HIC contact sent' => ['email' => $email, 'res_id' => $data['transaction_id'], 'lists' => $list_ids], 'HTTP' => $code]);
}