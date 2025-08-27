<?php
/**
 * Brevo Integration
 */

if (!defined('ABSPATH')) exit;

/* ============ Brevo: aggiorna contatto ============ */
function hic_send_brevo_contact($data, $gclid, $fbclid){
  if (!hic_get_brevo_api_key()) { hic_log('Brevo disabilitato (API key vuota).'); return; }

  $email = isset($data['email']) ? $data['email'] : null;
  if (!$email) { hic_log('Nessuna email nel payload → skip Brevo contact.'); return; }

  // Lista in base alla lingua (supporta sia 'lingua' sia 'lang')
  $lang = isset($data['lingua']) ? $data['lingua'] : (isset($data['lang']) ? $data['lang'] : '');
  $list_ids = array();
  if (strtolower($lang) === 'en') { $list_ids[] = intval(hic_get_brevo_list_en()); } else { $list_ids[] = intval(hic_get_brevo_list_it()); }

  $body = array(
    'email' => $email,
    'attributes' => array(
      'FIRSTNAME' => isset($data['first_name']) ? $data['first_name'] : '',
      'LASTNAME'  => isset($data['last_name']) ? $data['last_name'] : '',
      'RESVID'    => isset($data['reservation_id']) ? $data['reservation_id'] : (isset($data['id']) ? $data['id'] : ''),
      'GCLID'     => isset($gclid) ? $gclid : '',
      'FBCLID'    => isset($fbclid) ? $fbclid : '',
      'DATE'      => isset($data['date']) ? $data['date'] : date('Y-m-d'),
      'AMOUNT'    => isset($data['amount']) ? floatval($data['amount']) : 0,
      'CURRENCY'  => isset($data['currency']) ? $data['currency'] : 'EUR',
      'WHATSAPP'  => isset($data['whatsapp']) ? $data['whatsapp'] : '',
      'LINGUA'    => $lang
    ),
    'listIds'       => $list_ids,
    'updateEnabled' => true
  );

  $res = wp_remote_post('https://api.brevo.com/v3/contacts', array(
    'headers' => array(
      'accept'       => 'application/json',
      'api-key'      => hic_get_brevo_api_key(),
      'content-type' => 'application/json'
    ),
    'body'    => wp_json_encode($body),
    'timeout' => 15
  ));
  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  hic_log(array('Brevo contact inviato' => $body, 'HTTP'=>$code));
}

/* ============ Brevo: evento personalizzato (purchase + bucket) ============ */
function hic_send_brevo_event($data, $gclid, $fbclid){
  if (!hic_get_brevo_api_key()) { return; }
  $bucket = hic_get_bucket($gclid, $fbclid);

  $body = array(
    'event' => 'purchase', // puoi rinominare in 'hic_booking' se preferisci
    'email' => isset($data['email']) ? $data['email'] : '',
    'properties' => array(
      'reservation_id' => isset($data['reservation_id']) ? $data['reservation_id'] : (isset($data['id']) ? $data['id'] : ''),
      'amount'         => isset($data['amount']) ? floatval($data['amount']) : 0,
      'currency'       => isset($data['currency']) ? $data['currency'] : 'EUR',
      'date'           => isset($data['date']) ? $data['date'] : date('Y-m-d'),
      'whatsapp'       => isset($data['whatsapp']) ? $data['whatsapp'] : '',
      'lingua'         => isset($data['lingua']) ? $data['lingua'] : (isset($data['lang']) ? $data['lang'] : ''),
      'firstname'      => isset($data['first_name']) ? $data['first_name'] : '',
      'lastname'       => isset($data['last_name']) ? $data['last_name'] : '',
      'bucket'         => $bucket
    )
  );

  $res = wp_remote_post('https://in-automate.brevo.com/api/v2/trackEvent', array(
    'headers' => array(
      'accept'       => 'application/json',
      'content-type' => 'application/json',
      'api-key'      => hic_get_brevo_api_key()
    ),
    'body'    => wp_json_encode($body),
    'timeout' => 15
  ));
  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  hic_log(array('Brevo event: purchase (bucket='.$bucket.')' => $body, 'HTTP'=>$code));
}

/**
 * Brevo dispatcher for HIC reservation schema
 */
function hic_dispatch_brevo_reservation($data, $is_enrichment = false) {
  if (!hic_get_brevo_api_key()) { 
    hic_log('Brevo disabilitato (API key vuota).'); 
    return; 
  }

  $email = isset($data['email']) ? $data['email'] : '';
  if (!hic_is_valid_email($email)) { 
    hic_log('Brevo: email mancante o non valida, skip contatto.'); 
    return; 
  }

  $is_alias = hic_is_ota_alias_email($email);

  // Determine list based on language and alias status
  $language = isset($data['language']) ? $data['language'] : '';
  $list_ids = array();
  
  if ($is_alias) {
    // Handle alias emails - only add to alias list if configured
    $alias_list_id = intval(hic_get_brevo_list_alias());
    if ($alias_list_id > 0) {
      $list_ids[] = $alias_list_id;
    }
    // Don't mark alias emails as enriched - they need to be enriched later
  } else {
    // Handle real emails - add to language-specific lists
    if (in_array($language, array('it'))) {
      $list_id = intval(hic_get_brevo_list_it());
      if ($list_id > 0) $list_ids[] = $list_id;
    } elseif (in_array($language, array('en'))) {
      $list_id = intval(hic_get_brevo_list_en());
      if ($list_id > 0) $list_ids[] = $list_id;
    } else {
      $list_id = intval(hic_get_brevo_list_default());
      if ($list_id > 0) $list_ids[] = $list_id;
    }
  }

  $attributes = array(
    'FIRSTNAME' => isset($data['guest_first_name']) ? $data['guest_first_name'] : '',
    'LASTNAME' => isset($data['guest_last_name']) ? $data['guest_last_name'] : '',
    'PHONE' => isset($data['phone']) ? $data['phone'] : '',
    'LANGUAGE' => $language,
    'HIC_RES_ID' => isset($data['transaction_id']) ? $data['transaction_id'] : '',
    'HIC_RES_CODE' => isset($data['reservation_code']) ? $data['reservation_code'] : '',
    'HIC_FROM' => isset($data['from_date']) ? $data['from_date'] : '',
    'HIC_TO' => isset($data['to_date']) ? $data['to_date'] : '',
    'HIC_GUESTS' => isset($data['guests']) ? $data['guests'] : '',
    'HIC_ROOM' => isset($data['accommodation_name']) ? $data['accommodation_name'] : '',
    'HIC_PRICE' => isset($data['original_price']) ? $data['original_price'] : ''
  );

  // Remove empty values to clean up
  $attributes = array_filter($attributes, function($value) {
    return $value !== null && $value !== '';
  });

  $body = array(
    'email' => $email,
    'attributes' => $attributes,
    'listIds' => $list_ids,
    'updateEnabled' => true
  );

  // Add marketing opt-in only if not alias and default is enabled, or if enrichment is happening and double opt-in is enabled
  if (!$is_alias) {
    if (hic_get_brevo_optin_default() || ($is_enrichment && hic_brevo_double_optin_on_enrich())) {
      $body['emailBlacklisted'] = false;
    }
  }

  $res = wp_remote_post('https://api.brevo.com/v3/contacts', array(
    'headers' => array(
      'accept' => 'application/json',
      'api-key' => hic_get_brevo_api_key(),
      'content-type' => 'application/json'
    ),
    'body' => wp_json_encode($body),
    'timeout' => 15
  ));
  
  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  hic_log(array('Brevo HIC contact sent' => array('email' => $email, 'res_id' => $data['transaction_id'], 'lists' => $list_ids, 'alias' => $is_alias), 'HTTP' => $code));
}