<?php
/**
 * Brevo Integration
 */

if (!defined('ABSPATH')) exit;

/* ============ Brevo: aggiorna contatto ============ */
function hic_send_brevo_contact($data, $gclid, $fbclid){
  if (!hic_get_brevo_api_key()) { 
    hic_log('Brevo dispatch SKIPPED: API key mancante'); 
    return; 
  }

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
      'AMOUNT'    => isset($data['amount']) ? hic_normalize_price($data['amount']) : 0,
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
  $log_msg = "Brevo contact dispatch: email=$email lists=" . implode(',', $list_ids) . " HTTP=$code";
  if (is_wp_error($res)) {
    $log_msg .= " ERROR: " . $res->get_error_message();
  }
  hic_log($log_msg);
}

/* ============ Brevo: evento personalizzato (purchase + bucket) ============ */
function hic_send_brevo_event($data, $gclid, $fbclid){
  if (!hic_get_brevo_api_key()) { return; }
  $bucket = fp_normalize_bucket($gclid, $fbclid);

  $body = array(
    'event' => 'purchase', // puoi rinominare in 'hic_booking' se preferisci
    'email' => isset($data['email']) ? $data['email'] : '',
    'properties' => array(
      'reservation_id' => isset($data['reservation_id']) ? $data['reservation_id'] : (isset($data['id']) ? $data['id'] : ''),
      'amount'         => isset($data['amount']) ? hic_normalize_price($data['amount']) : 0,
      'currency'       => isset($data['currency']) ? $data['currency'] : 'EUR',
      'date'           => isset($data['date']) ? $data['date'] : date('Y-m-d'),
      'whatsapp'       => isset($data['whatsapp']) ? $data['whatsapp'] : '',
      'lingua'         => isset($data['lingua']) ? $data['lingua'] : (isset($data['lang']) ? $data['lang'] : ''),
      'firstname'      => isset($data['first_name']) ? $data['first_name'] : '',
      'lastname'       => isset($data['last_name']) ? $data['last_name'] : '',
      'bucket'         => $bucket,
      'vertical'       => 'hotel'
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

  // Get gclid/fbclid for legacy compatibility
  // Note: In API polling mode, these values are only available if the reservation
  // was originally tracked through the website with tracking parameters
  $gclid = '';
  $fbclid = '';
  if (!empty($data['transaction_id'])) {
    global $wpdb;
    $table = $wpdb->prefix . 'hic_gclids';
    
    // Check if table exists before querying
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
    if ($table_exists) {
      // Try to find tracking data using transaction_id as sid
      $row = $wpdb->get_row($wpdb->prepare("SELECT gclid, fbclid FROM $table WHERE sid=%s ORDER BY id DESC LIMIT 1", $data['transaction_id']));
      if ($row) { 
        $gclid = $row->gclid ?: ''; 
        $fbclid = $row->fbclid ?: ''; 
      }
    }
  }

  $attributes = array(
    // Standard contact attributes (shared)
    'FIRSTNAME' => isset($data['guest_first_name']) ? $data['guest_first_name'] : '',
    'LASTNAME' => isset($data['guest_last_name']) ? $data['guest_last_name'] : '',
    'PHONE' => isset($data['phone']) ? $data['phone'] : '',
    'LANGUAGE' => $language,
    
    // Modern HIC attributes
    'HIC_RES_ID' => isset($data['transaction_id']) ? $data['transaction_id'] : '',
    'HIC_RES_CODE' => isset($data['reservation_code']) ? $data['reservation_code'] : '',
    'HIC_FROM' => isset($data['from_date']) ? $data['from_date'] : '',
    'HIC_TO' => isset($data['to_date']) ? $data['to_date'] : '',
    'HIC_GUESTS' => isset($data['guests']) ? $data['guests'] : '',
    'HIC_ROOM' => isset($data['accommodation_name']) ? $data['accommodation_name'] : '',
    'HIC_PRICE' => isset($data['original_price']) ? $data['original_price'] : '',
    
    // Legacy webhook attributes (for backward compatibility)
    'RESVID' => isset($data['transaction_id']) ? $data['transaction_id'] : '',
    'GCLID' => $gclid,
    'FBCLID' => $fbclid,
    'DATE' => isset($data['from_date']) ? $data['from_date'] : date('Y-m-d'),
    'AMOUNT' => isset($data['original_price']) ? hic_normalize_price($data['original_price']) : 0,
    'CURRENCY' => isset($data['currency']) ? $data['currency'] : 'EUR',
    'WHATSAPP' => isset($data['phone']) ? $data['phone'] : '',
    'LINGUA' => $language
  );

  // Remove empty values but keep valid zeros for numeric fields
  $attributes = array_filter($attributes, function($value, $key) {
    // Keep numeric zero values for AMOUNT and HIC_PRICE
    if (in_array($key, ['AMOUNT', 'HIC_PRICE']) && is_numeric($value)) {
      return true;
    }
    return $value !== null && $value !== '';
  }, ARRAY_FILTER_USE_BOTH);

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

/**
 * Send reservation_created event to Brevo for real-time notifications
 */
function hic_send_brevo_reservation_created_event($data) {
  if (!hic_get_brevo_api_key()) { 
    hic_log('Brevo reservation_created event SKIPPED: API key mancante'); 
    return false; 
  }

  if (!hic_realtime_brevo_sync_enabled()) {
    hic_log('Brevo reservation_created event SKIPPED: real-time sync disabilitato');
    return false;
  }

  $email = isset($data['email']) ? $data['email'] : '';
  if (!hic_is_valid_email($email)) { 
    hic_log('Brevo reservation_created event SKIPPED: email mancante o non valida'); 
    return false; 
  }

  // Get gclid/fbclid for bucket normalization if available
  $gclid = '';
  $fbclid = '';
  if (!empty($data['transaction_id'])) {
    global $wpdb;
    $table = $wpdb->prefix . 'hic_gclids';
    
    // Check if table exists before querying
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
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

  $body = array(
    'event' => 'reservation_created',
    'email' => $email,
    'properties' => array(
      'reservation_id' => isset($data['transaction_id']) ? $data['transaction_id'] : '',
      'reservation_code' => isset($data['reservation_code']) ? $data['reservation_code'] : '',
      'amount' => isset($data['original_price']) ? hic_normalize_price($data['original_price']) : 0,
      'currency' => isset($data['currency']) ? $data['currency'] : 'EUR',
      'from_date' => isset($data['from_date']) ? $data['from_date'] : '',
      'to_date' => isset($data['to_date']) ? $data['to_date'] : '',
      'guests' => isset($data['guests']) ? $data['guests'] : '',
      'accommodation' => isset($data['accommodation_name']) ? $data['accommodation_name'] : '',
      'phone' => isset($data['phone']) ? $data['phone'] : '',
      'language' => isset($data['language']) ? $data['language'] : '',
      'firstname' => isset($data['guest_first_name']) ? $data['guest_first_name'] : '',
      'lastname' => isset($data['guest_last_name']) ? $data['guest_last_name'] : '',
      'bucket' => $bucket,
      'vertical' => 'hotel',
      'created_at' => current_time('mysql')
    )
  );

  $res = wp_remote_post('https://in-automate.brevo.com/api/v2/trackEvent', array(
    'headers' => array(
      'accept' => 'application/json',
      'content-type' => 'application/json',
      'api-key' => hic_get_brevo_api_key()
    ),
    'body' => wp_json_encode($body),
    'timeout' => 15
  ));
  
  $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
  $success = !is_wp_error($res) && $code >= 200 && $code < 300;
  
  $log_data = array(
    'event' => 'reservation_created',
    'email' => $email,
    'reservation_id' => $body['properties']['reservation_id'],
    'bucket' => $bucket,
    'HTTP' => $code
  );
  
  if (!$success) {
    $error_message = is_wp_error($res) ? $res->get_error_message() : "HTTP $code";
    $log_data['error'] = $error_message;
    hic_log(array('Brevo reservation_created event FAILED' => $log_data));
    return false;
  }

  hic_log(array('Brevo reservation_created event sent' => $log_data));
  return true;
}