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