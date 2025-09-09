<?php declare(strict_types=1);
namespace FpHic;
/**
 * Brevo Integration
 */

if (!defined('ABSPATH')) exit;

/* ============ Brevo: aggiorna contatto ============ */
function hic_send_brevo_contact($data, $gclid, $fbclid, $msclkid = '', $ttclid = ''){
  if (!Helpers\hic_get_brevo_api_key()) { 
    hic_log('Brevo dispatch SKIPPED: API key mancante'); 
    return; 
  }

  $email = isset($data['email']) ? $data['email'] : null;
  if (!$email) { hic_log('Nessuna email nel payload → skip Brevo contact.'); return; }

  // Lista in base alla lingua (supporta sia 'lingua' sia 'lang')
  $lang = isset($data['lingua']) ? $data['lingua'] : (isset($data['lang']) ? $data['lang'] : '');

  // Detect language from phone prefix if available
  $phone_data = Helpers\hic_detect_phone_language($data['phone'] ?? ($data['whatsapp'] ?? ''));
  if (!empty($phone_data['phone'])) {
    if (isset($data['phone'])) { $data['phone'] = $phone_data['phone']; }
    if (isset($data['whatsapp'])) { $data['whatsapp'] = $phone_data['phone']; }
  }
  if (!empty($phone_data['language'])) {
    $lang = $phone_data['language'];
  }

  $list_ids = array();
  if (strtolower($lang) === 'en') { $list_ids[] = intval(Helpers\hic_get_brevo_list_en()); } else { $list_ids[] = intval(Helpers\hic_get_brevo_list_it()); }

  $body = array(
    'email' => $email,
    'attributes' => array(
      'FIRSTNAME' => isset($data['guest_first_name']) ? $data['guest_first_name'] : '',
      'LASTNAME'  => isset($data['guest_last_name']) ? $data['guest_last_name'] : '',
      'PHONE'     => $data['phone'] ?? '',
      'RESVID'    => isset($data['reservation_id']) ? $data['reservation_id'] : (isset($data['id']) ? $data['id'] : ''),
      'GCLID'     => isset($gclid) ? $gclid : '',
      'FBCLID'    => isset($fbclid) ? $fbclid : '',
      'MSCLKID'   => isset($msclkid) ? $msclkid : '',
      'TTCLID'    => isset($ttclid) ? $ttclid : '',
      'DATE'      => isset($data['date']) ? $data['date'] : wp_date('Y-m-d'),
      'AMOUNT'    => isset($data['amount']) ? Helpers\hic_normalize_price($data['amount']) : 0,
      'CURRENCY'  => isset($data['currency']) ? $data['currency'] : 'EUR',
      'WHATSAPP'  => isset($data['whatsapp']) ? $data['whatsapp'] : '',
      'LINGUA'    => $lang
    ),
    'listIds'       => $list_ids,
    'updateEnabled' => true
  );

  // Populate UTM attributes if SID available
  if (!empty($data['sid'])) {
    $utm = Helpers\hic_get_utm_params_by_sid($data['sid']);
    if (!empty($utm['utm_source']))   { $body['attributes']['UTM_SOURCE']   = $utm['utm_source']; }
    if (!empty($utm['utm_medium']))   { $body['attributes']['UTM_MEDIUM']   = $utm['utm_medium']; }
    if (!empty($utm['utm_campaign'])) { $body['attributes']['UTM_CAMPAIGN'] = $utm['utm_campaign']; }
    if (!empty($utm['utm_content']))  { $body['attributes']['UTM_CONTENT']  = $utm['utm_content']; }
    if (!empty($utm['utm_term']))     { $body['attributes']['UTM_TERM']     = $utm['utm_term']; }
  }

  $res = Helpers\hic_http_request('https://api.brevo.com/v3/contacts', array(
    'method'  => 'POST',
    'headers' => array(
      'accept'       => 'application/json',
      'api-key'      => Helpers\hic_get_brevo_api_key(),
      'content-type' => 'application/json'
    ),
    'body'    => wp_json_encode($body),
  ));
  
  $result = hic_handle_brevo_response($res, 'contact_legacy', array(
    'email' => $email, 
    'lists' => implode(',', $list_ids)
  ));
  
  if (!$result['success']) {
    hic_log('Brevo contact dispatch FAILED: ' . $result['error']);
  }
}

/* ============ Brevo: evento personalizzato (purchase + bucket) ============ */
function hic_send_brevo_event($reservation, $gclid, $fbclid, $msclkid = '', $ttclid = ''){
  if (!Helpers\hic_get_brevo_api_key()) { return; }
  $bucket = Helpers\fp_normalize_bucket($gclid, $fbclid);

  $event_data = array(
    'event' => 'purchase', // puoi rinominare in 'hic_booking' se preferisci
    'email' => isset($reservation['email']) ? $reservation['email'] : '',
    'properties' => array(
      'reservation_id' => isset($reservation['reservation_id']) ? $reservation['reservation_id'] : (isset($reservation['id']) ? $reservation['id'] : ''),
      'amount'         => isset($reservation['amount']) ? Helpers\hic_normalize_price($reservation['amount']) : 0,
      'currency'       => isset($reservation['currency']) ? $reservation['currency'] : 'EUR',
      'date'           => isset($reservation['date']) ? $reservation['date'] : wp_date('Y-m-d'),
      'whatsapp'       => isset($reservation['whatsapp']) ? $reservation['whatsapp'] : '',
      'lingua'         => isset($reservation['lingua']) ? $reservation['lingua'] : (isset($reservation['lang']) ? $reservation['lang'] : ''),
      'guest_first_name' => isset($reservation['guest_first_name']) ? $reservation['guest_first_name'] : '',
      'guest_last_name'  => isset($reservation['guest_last_name']) ? $reservation['guest_last_name'] : '',
      'bucket'         => $bucket,
      'vertical'       => 'hotel',
      'msclkid'        => $msclkid,
      'ttclid'         => $ttclid
    )
  );

  if (isset($reservation['tags']) && is_array($reservation['tags'])) {
    $event_data['tags'] = array_values($reservation['tags']);
  }

  // Attach UTM parameters if SID available
  if (!empty($reservation['sid'])) {
    $utm = Helpers\hic_get_utm_params_by_sid($reservation['sid']);
    if (!empty($utm['utm_source']))   { $event_data['properties']['utm_source']   = $utm['utm_source']; }
    if (!empty($utm['utm_medium']))   { $event_data['properties']['utm_medium']   = $utm['utm_medium']; }
    if (!empty($utm['utm_campaign'])) { $event_data['properties']['utm_campaign'] = $utm['utm_campaign']; }
    if (!empty($utm['utm_content']))  { $event_data['properties']['utm_content']  = $utm['utm_content']; }
    if (!empty($utm['utm_term']))     { $event_data['properties']['utm_term']     = $utm['utm_term']; }
  }

  $event_data = apply_filters('hic_brevo_event', $event_data, $reservation);

  $res = Helpers\hic_http_request(Helpers\hic_get_brevo_event_endpoint(), array(
    'method'  => 'POST',
    'headers' => array(
      'accept'       => 'application/json',
      'content-type' => 'application/json',
      'api-key'      => Helpers\hic_get_brevo_api_key()
    ),
    'body'    => wp_json_encode($event_data),
  ));

  $result = hic_handle_brevo_response($res, 'event_legacy', array(
    'event' => 'purchase',
    'bucket' => $bucket,
    'email' => $event_data['email']
  ));
  
  if (!$result['success']) {
    hic_log('Brevo event dispatch FAILED: ' . $result['error']);
  }
}

/**
 * Brevo refund event
 */
function hic_send_brevo_refund_event($reservation, $gclid, $fbclid, $msclkid = '', $ttclid = ''){
  if (!Helpers\hic_get_brevo_api_key()) { return false; }
  $bucket = Helpers\fp_normalize_bucket($gclid, $fbclid);

  $event_data = array(
    'event' => 'refund',
    'email' => isset($reservation['email']) ? $reservation['email'] : '',
    'properties' => array(
      'reservation_id' => isset($reservation['reservation_id']) ? $reservation['reservation_id'] : (isset($reservation['id']) ? $reservation['id'] : ''),
      'amount'         => isset($reservation['amount']) ? -abs(Helpers\hic_normalize_price($reservation['amount'])) : 0,
      'currency'       => isset($reservation['currency']) ? $reservation['currency'] : 'EUR',
      'date'           => isset($reservation['date']) ? $reservation['date'] : wp_date('Y-m-d'),
      'whatsapp'       => isset($reservation['whatsapp']) ? $reservation['whatsapp'] : '',
      'lingua'         => isset($reservation['lingua']) ? $reservation['lingua'] : (isset($reservation['lang']) ? $reservation['lang'] : ''),
      'guest_first_name' => isset($reservation['guest_first_name']) ? $reservation['guest_first_name'] : '',
      'guest_last_name'  => isset($reservation['guest_last_name']) ? $reservation['guest_last_name'] : '',
      'bucket'         => $bucket,
      'vertical'       => 'hotel',
      'msclkid'        => $msclkid,
      'ttclid'         => $ttclid
    )
  );

  if (!empty($reservation['sid'])) {
    $utm = Helpers\hic_get_utm_params_by_sid($reservation['sid']);
    if (!empty($utm['utm_source']))   { $event_data['properties']['utm_source']   = $utm['utm_source']; }
    if (!empty($utm['utm_medium']))   { $event_data['properties']['utm_medium']   = $utm['utm_medium']; }
    if (!empty($utm['utm_campaign'])) { $event_data['properties']['utm_campaign'] = $utm['utm_campaign']; }
    if (!empty($utm['utm_content']))  { $event_data['properties']['utm_content']  = $utm['utm_content']; }
    if (!empty($utm['utm_term']))     { $event_data['properties']['utm_term']     = $utm['utm_term']; }
  }

  $event_data = apply_filters('hic_brevo_refund_event', $event_data, $reservation);

  $res = Helpers\hic_http_request(Helpers\hic_get_brevo_event_endpoint(), array(
    'method'  => 'POST',
    'headers' => array(
      'accept'       => 'application/json',
      'content-type' => 'application/json',
      'api-key'      => Helpers\hic_get_brevo_api_key()
    ),
    'body'    => wp_json_encode($event_data),
  ));

  $result = hic_handle_brevo_response($res, 'event_refund', array(
    'event' => 'refund',
    'bucket' => $bucket,
    'email' => $event_data['email']
  ));

  if (!$result['success']) {
    hic_log('Brevo refund event dispatch FAILED: ' . $result['error']);
  }

  return $result['success'];
}

/**
 * Brevo dispatcher for HIC reservation schema
 */
function hic_dispatch_brevo_reservation($data, $is_enrichment = false, $gclid = '', $fbclid = '', $msclkid = '', $ttclid = '') {
  if (!Helpers\hic_get_brevo_api_key()) {
    hic_log('Brevo disabilitato (API key vuota).');
    return false;
  }

  $email = isset($data['email']) ? $data['email'] : '';
  if (!Helpers\hic_is_valid_email($email)) {
    hic_log('Brevo: email mancante o non valida, skip contatto.');
    return false;
  }

  $is_alias = Helpers\hic_is_ota_alias_email($email);

  // Determine list based on language and alias status
  $language = isset($data['language']) ? $data['language'] : '';

  // Detect language from phone prefix
  $phone_data = Helpers\hic_detect_phone_language($data['phone'] ?? '');
  if (!empty($phone_data['phone'])) {
    $data['phone'] = $phone_data['phone'];
  }
  if (!empty($phone_data['language'])) {
    $language = $phone_data['language'];
  }

  $list_ids = array();
  
  if ($is_alias) {
    // Handle alias emails - only add to alias list if configured
    $alias_list_id = intval(Helpers\hic_get_brevo_list_alias());
    if ($alias_list_id > 0) {
      $list_ids[] = $alias_list_id;
    }
    // Don't mark alias emails as enriched - they need to be enriched later
  } else {
    // Handle real emails - add to language-specific lists
    if (in_array($language, array('it'))) {
      $list_id = intval(Helpers\hic_get_brevo_list_it());
      if ($list_id > 0) $list_ids[] = $list_id;
    } elseif (in_array($language, array('en'))) {
      $list_id = intval(Helpers\hic_get_brevo_list_en());
      if ($list_id > 0) $list_ids[] = $list_id;
    } else {
      $list_id = intval(Helpers\hic_get_brevo_list_default());
      if ($list_id > 0) $list_ids[] = $list_id;
    }
  }

  // Get tracking IDs for legacy compatibility
  // Use provided values when available before querying the database
  if (!empty($data['transaction_id']) && (empty($gclid) || empty($fbclid) || empty($msclkid) || empty($ttclid))) {
    $tracking = Helpers\hic_get_tracking_ids_by_sid($data['transaction_id']);
    if (empty($gclid))   { $gclid   = $tracking['gclid'] ?? ''; }
    if (empty($fbclid))  { $fbclid  = $tracking['fbclid'] ?? ''; }
    if (empty($msclkid)) { $msclkid = $tracking['msclkid'] ?? ''; }
    if (empty($ttclid))  { $ttclid  = $tracking['ttclid'] ?? ''; }
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
    'HIC_PRESENCE' => isset($data['presence']) ? $data['presence'] : '',
    'HIC_BALANCE' => isset($data['unpaid_balance']) ? Helpers\hic_normalize_price($data['unpaid_balance']) : '',
    
    // Legacy webhook attributes (for backward compatibility)
    'RESVID' => isset($data['transaction_id']) ? $data['transaction_id'] : '',
    'GCLID'  => $gclid,
    'FBCLID' => $fbclid,
    'MSCLKID' => $msclkid,
    'TTCLID'  => $ttclid,
    'DATE' => isset($data['from_date']) ? $data['from_date'] : wp_date('Y-m-d'),
    'AMOUNT' => isset($data['original_price']) ? Helpers\hic_normalize_price($data['original_price']) : 0,
    'CURRENCY' => isset($data['currency']) ? $data['currency'] : 'EUR',
    'WHATSAPP' => isset($data['phone']) ? $data['phone'] : '',
    'LINGUA' => $language
  );

  if (isset($data['tags']) && is_array($data['tags'])) {
    $attributes['TAGS'] = implode(',', $data['tags']);
  }

  // Populate UTM attributes if available
  if (!empty($data['transaction_id'])) {
    $utm = Helpers\hic_get_utm_params_by_sid($data['transaction_id']);
    if (!empty($utm['utm_source']))   { $attributes['UTM_SOURCE']   = $utm['utm_source']; }
    if (!empty($utm['utm_medium']))   { $attributes['UTM_MEDIUM']   = $utm['utm_medium']; }
    if (!empty($utm['utm_campaign'])) { $attributes['UTM_CAMPAIGN'] = $utm['utm_campaign']; }
    if (!empty($utm['utm_content']))  { $attributes['UTM_CONTENT']  = $utm['utm_content']; }
    if (!empty($utm['utm_term']))     { $attributes['UTM_TERM']     = $utm['utm_term']; }
  }

  // Remove empty values but keep valid zeros for numeric fields
  $attributes = array_filter($attributes, function($value, $key) {
    // Keep numeric zero values for AMOUNT, HIC_PRICE and HIC_BALANCE
    if (in_array($key, ['AMOUNT', 'HIC_PRICE', 'HIC_BALANCE']) && is_numeric($value)) {
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

  if (isset($data['tags']) && is_array($data['tags'])) {
    $body['tags'] = array_values($data['tags']);
  }

  // Add marketing opt-in only if not alias and default is enabled, or if enrichment is happening and double opt-in is enabled
  if (!$is_alias) {
    if (Helpers\hic_get_brevo_optin_default() || ($is_enrichment && Helpers\hic_brevo_double_optin_on_enrich())) {
      $body['emailBlacklisted'] = false;
    }
  }

  $res = Helpers\hic_http_request('https://api.brevo.com/v3/contacts', array(
    'method'  => 'POST',
    'headers' => array(
      'accept' => 'application/json',
      'api-key' => Helpers\hic_get_brevo_api_key(),
      'content-type' => 'application/json'
    ),
    'body' => wp_json_encode($body),
  ));
  
  $result = hic_handle_brevo_response($res, 'contact', array(
    'email' => $email,
    'res_id' => $data['transaction_id'],
    'lists' => $list_ids,
    'alias' => $is_alias
  ));

  if (!$result['success']) {
    hic_log('Brevo contact dispatch FAILED: ' . $result['error']);
    return false;
  }

  return true;
}

/**
 * Send reservation_created event to Brevo for real-time notifications
 *
 * @param array  $data   Transformed reservation data
 * @param string $gclid  Google click ID
 * @param string $fbclid Facebook click ID
 *
 * @return array {
 *     @type bool        $success   Whether the event was sent successfully
 *     @type bool|null   $retryable Whether the failure is retryable
 *     @type string|null $error     Error message when not successful
 *     @type bool        $skipped   True when the event was skipped before sending
 * }
 */
function hic_send_brevo_reservation_created_event($data, $gclid = '', $fbclid = '', $msclkid = '', $ttclid = '') {
  if (!Helpers\hic_get_brevo_api_key()) {
    hic_log('Brevo reservation_created event SKIPPED: API key mancante');
    return array(
      'success' => false,
      'retryable' => false,
      'error' => 'API key mancante',
      'skipped' => true,
    );
  }

  if (!Helpers\hic_realtime_brevo_sync_enabled()) {
    hic_log('Brevo reservation_created event SKIPPED: real-time sync disabilitato');
    return array(
      'success' => false,
      'retryable' => false,
      'error' => 'real-time sync disabilitato',
      'skipped' => true,
    );
  }

  $email = isset($data['email']) ? $data['email'] : '';
  if (!Helpers\hic_is_valid_email($email)) {
    hic_log('Brevo reservation_created event SKIPPED: email mancante o non valida');
    return array(
      'success' => false,
      'retryable' => false,
      'error' => 'email mancante o non valida',
      'skipped' => true,
    );
  }

  // Validate essential data fields
  $validation_errors = array();
  if (empty($data['transaction_id'])) {
    $validation_errors[] = 'transaction_id missing';
  }
  if (empty($data['original_price']) && $data['original_price'] !== 0) {
    $validation_errors[] = 'original_price missing';
  }

  if (!empty($validation_errors)) {
    hic_log('Brevo reservation_created event SKIPPED: validation errors - ' . implode(', ', $validation_errors));
    return array(
      'success' => false,
      'retryable' => false,
      'error' => 'validation errors',
      'skipped' => true,
    );
  }

  // Get tracking IDs for bucket normalization if available
  if (!empty($data['transaction_id']) && (empty($gclid) || empty($fbclid) || empty($msclkid) || empty($ttclid))) {
    $tracking = Helpers\hic_get_tracking_ids_by_sid($data['transaction_id']);
    if (empty($gclid))   { $gclid   = $tracking['gclid'] ?? ''; }
    if (empty($fbclid))  { $fbclid  = $tracking['fbclid'] ?? ''; }
    if (empty($msclkid)) { $msclkid = $tracking['msclkid'] ?? ''; }
    if (empty($ttclid))  { $ttclid  = $tracking['ttclid'] ?? ''; }
  }

  // Normalize phone number and detect language from prefix
  $phone_data = Helpers\hic_detect_phone_language($data['phone'] ?? '');
  $data['phone'] = $phone_data['phone'] ?? ($data['phone'] ?? '');
  $data['language'] = $phone_data['language'] ?? ($data['language'] ?? '');

  $bucket = Helpers\fp_normalize_bucket($gclid, $fbclid);

  $body = array(
    'event' => 'reservation_created',
    'email' => $email,
    'properties' => array(
      'reservation_id' => isset($data['transaction_id']) ? $data['transaction_id'] : '',
      'reservation_code' => isset($data['reservation_code']) ? $data['reservation_code'] : '',
      'amount' => isset($data['original_price']) ? Helpers\hic_normalize_price($data['original_price']) : 0,
      'currency' => isset($data['currency']) ? $data['currency'] : 'EUR',
      'from_date' => isset($data['from_date']) ? $data['from_date'] : '',
      'to_date' => isset($data['to_date']) ? $data['to_date'] : '',
      'guests' => isset($data['guests']) ? $data['guests'] : '',
      'accommodation' => isset($data['accommodation_name']) ? $data['accommodation_name'] : '',
      'phone' => isset($data['phone']) ? $data['phone'] : '',
      'language' => isset($data['language']) ? $data['language'] : '',
      'firstname' => isset($data['guest_first_name']) ? $data['guest_first_name'] : '',
      'lastname' => isset($data['guest_last_name']) ? $data['guest_last_name'] : '',
      'presence' => isset($data['presence']) ? $data['presence'] : '',
      'unpaid_balance' => isset($data['unpaid_balance']) ? Helpers\hic_normalize_price($data['unpaid_balance']) : 0,
      'tags' => isset($data['tags']) && is_array($data['tags']) ? implode(',', $data['tags']) : '',
      'bucket' => $bucket,
      'vertical' => 'hotel',
      'msclkid' => $msclkid,
      'ttclid' => $ttclid,
      'created_at' => current_time('mysql')
    )
  );

  // Add UTM parameters if available
  if (!empty($data['transaction_id'])) {
    $utm = Helpers\hic_get_utm_params_by_sid($data['transaction_id']);
    if (!empty($utm['utm_source']))   { $body['properties']['utm_source']   = $utm['utm_source']; }
    if (!empty($utm['utm_medium']))   { $body['properties']['utm_medium']   = $utm['utm_medium']; }
    if (!empty($utm['utm_campaign'])) { $body['properties']['utm_campaign'] = $utm['utm_campaign']; }
    if (!empty($utm['utm_content']))  { $body['properties']['utm_content']  = $utm['utm_content']; }
    if (!empty($utm['utm_term']))     { $body['properties']['utm_term']     = $utm['utm_term']; }
  }

  // Debug log the exact payload structure being sent
  hic_log(array('Brevo trackEvent payload debug' => array(
    'email' => $email,
    'event' => 'reservation_created',  
    'properties_structure' => 'properties',
    'auth_header' => 'api-key',
    'amount' => $body['properties']['amount'],
    'bucket' => $body['properties']['bucket'],
    'vertical' => $body['properties']['vertical'],
    'endpoint' => Helpers\hic_get_brevo_event_endpoint(),
    'full_payload' => $body
  )));

  $res = Helpers\hic_http_request(Helpers\hic_get_brevo_event_endpoint(), array(
    'method'  => 'POST',
    'headers' => array(
      'accept' => 'application/json',
      'content-type' => 'application/json',
      'api-key' => Helpers\hic_get_brevo_api_key()
    ),
    'body' => wp_json_encode($body),
  ));
  
  $result = hic_handle_brevo_response($res, 'event', array(
    'event' => 'reservation_created',
    'email' => $email,
    'reservation_id' => $body['properties']['reservation_id'],
    'amount' => $body['properties']['amount'],
    'bucket' => $bucket,
    'vertical' => $body['properties']['vertical'],
    'endpoint' => Helpers\hic_get_brevo_event_endpoint()
  ));
  
  if (!$result['success']) {
    hic_log('Brevo reservation_created event FAILED: ' . $result['error']);
    
    // Check if it's a retryable error or permanent failure
    $is_retryable = hic_is_brevo_error_retryable($result);
    if (!$is_retryable) {
      hic_log('Brevo error is not retryable - marking as permanently failed');
      // Mark reservation as permanently failed immediately for non-retryable errors
      $reservation_id = isset($data['transaction_id']) ? $data['transaction_id'] : '';
      if (!empty($reservation_id)) {
        hic_mark_reservation_notification_permanent_failure($reservation_id, $result['error']);
      }
    }
    
    // Return additional info about retryability
    return array(
      'success' => false,
      'retryable' => $is_retryable,
      'error' => $result['error'],
      'skipped' => false,
    );
  }

  hic_log(array('Brevo reservation_created event sent' => $result['log_data']));
  return array(
    'success' => true,
    'retryable' => null,
    'error' => null,
    'skipped' => false,
  );
}

/**
 * Enhanced Brevo API response handler with proper error handling
 * According to Brevo API v3 specification
 */
function hic_handle_brevo_response($response, $request_type = 'unknown', $log_context = array()) {
  // Check for WordPress HTTP errors
  if (is_wp_error($response)) {
    return array(
      'success' => false,
      'error' => 'Connection error: ' . $response->get_error_message(),
      'log_data' => array_merge($log_context, array('error_type' => 'wp_error', 'HTTP' => 0))
    );
  }
  
  $http_code = wp_remote_retrieve_response_code($response);
  $response_body = wp_remote_retrieve_body($response);
  
  // Parse response body for additional error information
  $parsed_body = json_decode($response_body, true);
  if (json_last_error() !== JSON_ERROR_NONE) {
    $log_data = array_merge($log_context, array('error_type' => 'json', 'HTTP' => $http_code));
    hic_log(array('Brevo response JSON parse error' => array_merge($log_data, array('error' => json_last_error_msg()))));
    return array(
      'success' => false,
      'error' => 'Invalid JSON: ' . json_last_error_msg(),
      'log_data' => $log_data
    );
  }
  $brevo_error_code = null;
  $brevo_error_message = null;
  
  if (is_array($parsed_body)) {
    $brevo_error_code = isset($parsed_body['code']) ? $parsed_body['code'] : null;
    $brevo_error_message = isset($parsed_body['message']) ? $parsed_body['message'] : null;
  }
  
  $log_data = array_merge($log_context, array(
    'HTTP' => $http_code,
    'request_type' => $request_type,
    'API_header' => 'api-key'
  ));
  
  if ($brevo_error_code) {
    $log_data['brevo_error_code'] = $brevo_error_code;
  }
  if ($brevo_error_message) {
    $log_data['brevo_error_message'] = $brevo_error_message;
  }
  
  // Handle HTTP response codes according to Brevo API specification
  switch ($http_code) {
    case 200: // OK
    case 201: // Created
    case 202: // Accepted
    case 204: // No content
      hic_log(array('Brevo ' . $request_type . ' success' => $log_data));
      return array(
        'success' => true,
        'http_code' => $http_code,
        'log_data' => $log_data
      );
      
    case 400: // Bad request
      $error_msg = 'Bad request - Invalid parameters';
      if ($brevo_error_message) {
        $error_msg .= ': ' . $brevo_error_message;
      }
      return array(
        'success' => false,
        'error' => $error_msg,
        'log_data' => $log_data
      );
      
    case 401: // Unauthorized
      return array(
        'success' => false,
        'error' => 'Unauthorized - Invalid API key or expired credentials',
        'log_data' => $log_data
      );
      
    case 402: // Payment Required
      return array(
        'success' => false,
        'error' => 'Payment required - Account not activated or insufficient credits',
        'log_data' => $log_data
      );
      
    case 403: // Forbidden
      return array(
        'success' => false,
        'error' => 'Forbidden - No permission to access this resource',
        'log_data' => $log_data
      );
      
    case 404: // Not Found
      return array(
        'success' => false,
        'error' => 'Not found - Endpoint or resource does not exist',
        'log_data' => $log_data
      );
      
    case 405: // Method Not Allowed
      return array(
        'success' => false,
        'error' => 'Method not allowed - Check HTTP method (GET/POST/PUT/DELETE)',
        'log_data' => $log_data
      );
      
    case 406: // Not Acceptable
      return array(
        'success' => false,
        'error' => 'Not acceptable - Content-Type must be application/json',
        'log_data' => $log_data
      );
      
    case 429: // Too Many Requests
      return array(
        'success' => false,
        'error' => 'Rate limit exceeded - Too many requests, retry later',
        'log_data' => $log_data,
        'retry_after' => wp_remote_retrieve_header($response, 'retry-after')
      );
      
    case 500:
    case 502:
    case 503:
      return array(
        'success' => false,
        'error' => "Server error (HTTP $http_code) - Brevo service temporarily unavailable",
        'log_data' => $log_data
      );
      
    default:
      return array(
        'success' => false,
        'error' => "Unknown HTTP error ($http_code)" . ($brevo_error_message ? ': ' . $brevo_error_message : ''),
        'log_data' => $log_data
      );
  }
}

/**
 * Determine if a Brevo API error is retryable
 */
function hic_is_brevo_error_retryable($result) {
  if (!is_array($result) || !isset($result['log_data']['HTTP'])) {
    return false;
  }
  
  $http_code = $result['log_data']['HTTP'];
  
  // Retryable errors: rate limiting, server errors
  $retryable_codes = [429, 500, 502, 503];
  
  return in_array($http_code, $retryable_codes);
}

/**
 * Unified Brevo event sender to prevent duplicate API calls
 * Replaces separate hic_send_brevo_contact() + hic_send_brevo_event() calls
 */
function hic_send_unified_brevo_events($data, $gclid, $fbclid, $msclkid = '', $ttclid = '') {
  if (!Helpers\hic_get_brevo_api_key()) { 
    hic_log('Unified Brevo dispatch SKIPPED: API key mancante'); 
    return false; 
  }

  // Validate essential data
  $email = isset($data['email']) ? $data['email'] : null;
  if (!Helpers\hic_is_valid_email($email)) { 
    hic_log('Unified Brevo dispatch SKIPPED: email mancante o non valida'); 
    return false; 
  }

  // Transform webhook data to modern format for consistency
  $transformed_data = hic_transform_webhook_data_for_brevo($data);

  // Use the modern dispatcher for contact management and capture result
  $contact_updated = hic_dispatch_brevo_reservation($transformed_data, false, $gclid, $fbclid, $msclkid, $ttclid);
  hic_log(
    $contact_updated
      ? 'Unified Brevo: contact update succeeded'
      : 'Unified Brevo: contact update failed',
    $contact_updated ? HIC_LOG_LEVEL_INFO : HIC_LOG_LEVEL_ERROR
  );

  // Send event for new reservations, allowing override via filter
  $event_sent = false;
  $reservation_id = Helpers\hic_extract_reservation_id($data);
  if (!empty($reservation_id)) {
    $is_new = hic_is_reservation_new_for_realtime($reservation_id);
    if ($is_new) {
      $send_event = apply_filters('hic_brevo_send_event', true, $data);
      hic_mark_reservation_new_for_realtime($reservation_id);
      if ($send_event) {
        $event_result = hic_send_brevo_reservation_created_event($transformed_data, $gclid, $fbclid, $msclkid, $ttclid);
        if ($event_result['success']) {
          hic_log('Unified Brevo: reservation_created event sent');
          hic_mark_reservation_notified_to_brevo($reservation_id);
          $event_sent = true;
        } else {
          hic_log('Unified Brevo: reservation_created event failed', HIC_LOG_LEVEL_WARNING);
          if ($event_result['retryable']) {
            hic_mark_reservation_notification_failed($reservation_id, 'Failed to send reservation_created event: ' . $event_result['error']);
          }
          // Non-retryable errors are already handled in hic_send_brevo_reservation_created_event
        }
      } else {
        // Event sending disabled via filter, mark as notified to avoid retries
        hic_log('Unified Brevo: reservation_created event skipped via filter');
        hic_mark_reservation_notified_to_brevo($reservation_id);
      }
    } else {
      hic_log("Unified Brevo: reservation $reservation_id already processed for real-time sync");
    }
  }

  if (!$contact_updated && !$event_sent) {
    return false;
  }

  if (!$contact_updated && $event_sent) {
    hic_log('Unified Brevo: contact update failed but event sent', HIC_LOG_LEVEL_WARNING);
  }

  return true;
}

/**
 * Transform webhook data format to match modern polling format
 */
function hic_transform_webhook_data_for_brevo($webhook_data) {
  if (!is_array($webhook_data)) {
    return array();
  }
  
  // Map webhook field names to modern field names
  $first = $webhook_data['guest_first_name']
      ?? $webhook_data['guest_firstname']
      ?? $webhook_data['first_name']
      ?? $webhook_data['firstname']
      ?? $webhook_data['customer_first_name']
      ?? $webhook_data['customer_firstname']
      ?? null;

  $last = $webhook_data['guest_last_name']
      ?? $webhook_data['guest_lastname']
      ?? $webhook_data['last_name']
      ?? $webhook_data['lastname']
      ?? $webhook_data['customer_last_name']
      ?? $webhook_data['customer_lastname']
      ?? null;

  if ((empty($first) || empty($last)) && !empty($webhook_data['name']) && is_string($webhook_data['name'])) {
      $parts = preg_split('/\s+/', trim($webhook_data['name']), 2);
      if (empty($first) && isset($parts[0])) {
          $first = $parts[0];
      }
      if (empty($last) && isset($parts[1])) {
          $last = $parts[1];
      }
  }

  $transformed = array(
    'transaction_id' => Helpers\hic_extract_reservation_id($webhook_data),
    'reservation_code' => isset($webhook_data['reservation_code']) ? $webhook_data['reservation_code'] : '',
    'email' => isset($webhook_data['email']) ? $webhook_data['email'] : '',
    'guest_first_name' => $first ?? '',
    'guest_last_name' => $last ?? '',
    'phone' => isset($webhook_data['whatsapp']) ? $webhook_data['whatsapp'] : (isset($webhook_data['phone']) ? $webhook_data['phone'] : ''),
    'original_price' => isset($webhook_data['amount']) ? Helpers\hic_normalize_price($webhook_data['amount']) : 0,
    'currency' => isset($webhook_data['currency']) ? $webhook_data['currency'] : 'EUR',
    'from_date' => $webhook_data['date'] ?? $webhook_data['checkin'] ?? '',
    'to_date'   => $webhook_data['to_date'] ?? $webhook_data['checkout'] ?? '',
    'accommodation_name' => isset($webhook_data['room']) ? $webhook_data['room'] : '',
    'guests' => isset($webhook_data['guests']) ? $webhook_data['guests'] : 1,
    'language' => isset($webhook_data['lingua']) ? $webhook_data['lingua'] : (isset($webhook_data['lang']) ? $webhook_data['lang'] : '')
  );
  
  // Remove empty values but keep numeric zeros
  foreach ($transformed as $key => $value) {
    if ($value === null || $value === '') {
      if (!in_array($key, ['original_price', 'guests']) || !is_numeric($value)) {
        unset($transformed[$key]);
      }
    }
  }
  
  return $transformed;
}

/**
 * Test Brevo Contact API connectivity
 */
function hic_test_brevo_contact_api() {
  $test_email = 'test-' . current_time('timestamp') . '@example.com';
  // Use default Italian list for the test contact to ensure a valid payload
  $list_id = intval(Helpers\hic_get_brevo_list_it());

  $body = array(
    'email' => $test_email,
    'attributes' => array(
      'FIRSTNAME' => 'Test',
      'LASTNAME' => 'Connectivity'
    ),
    'listIds' => array($list_id),
    'updateEnabled' => false // Don't update if exists
  );
  
  $res = Helpers\hic_http_request('https://api.brevo.com/v3/contacts', array(
    'method'  => 'POST',
    'headers' => array(
      'accept' => 'application/json',
      'api-key' => Helpers\hic_get_brevo_api_key(),
      'content-type' => 'application/json'
    ),
    'body' => wp_json_encode($body),
  ));
  
  $result = hic_handle_brevo_response($res, 'contact_test', array(
    'test_email' => $test_email,
    'endpoint' => 'https://api.brevo.com/v3/contacts'
  ));

  // Remove the test contact to keep the Brevo list clean
  if ($result['success']) {
    Helpers\hic_http_request('https://api.brevo.com/v3/contacts/' . rawurlencode($test_email), array(
      'method'  => 'DELETE',
      'headers' => array(
        'accept'  => 'application/json',
        'api-key' => Helpers\hic_get_brevo_api_key()
      )
    ));
  }
  
  return array(
    'success' => $result['success'],
    'message' => $result['success'] ? 'Contact API test successful' : $result['error'],
    'endpoint' => 'https://api.brevo.com/v3/contacts',
    'http_code' => isset($result['log_data']['HTTP']) ? $result['log_data']['HTTP'] : 'N/A'
  );
}

/**
 * Test Brevo Event API connectivity
 */
function hic_test_brevo_event_api() {
  $endpoint = Helpers\hic_get_brevo_event_endpoint();
  $test_email = 'test-' . current_time('timestamp') . '@example.com';
  
  $body = array(
    'event' => 'test_connectivity',
    'email' => $test_email,
    'properties' => array(
      'test_timestamp' => current_time('mysql'),
      'source' => 'hic_diagnostic_test',
      'vertical' => 'hotel'
    )
  );
  
  hic_log(array('Brevo Event API Test' => array(
    'endpoint' => $endpoint,
    'test_email' => $test_email,
    'payload' => $body
  )));
  
  $res = Helpers\hic_http_request($endpoint, array(
    'method'  => 'POST',
    'headers' => array(
      'accept' => 'application/json',
      'content-type' => 'application/json',
      'api-key' => Helpers\hic_get_brevo_api_key()
    ),
    'body' => wp_json_encode($body),
  ));
  
  $result = hic_handle_brevo_response($res, 'event_test', array(
    'test_email' => $test_email,
    'endpoint' => $endpoint,
    'event' => 'test_connectivity'
  ));
  
  $response_data = array(
    'success' => $result['success'],
    'message' => $result['success'] ? 'Event API test successful' : $result['error'],
    'endpoint' => $endpoint,
    'http_code' => isset($result['log_data']['HTTP']) ? $result['log_data']['HTTP'] : 'N/A'
  );
  
  // If primary endpoint fails, try alternative endpoint
  if (!$result['success'] && $endpoint !== 'https://in-automate.brevo.com/api/v2/trackEvent') {
    $alt_endpoint = 'https://in-automate.brevo.com/api/v2/trackEvent';
    
    hic_log(array('Brevo Event API Alternative Test' => array(
      'alt_endpoint' => $alt_endpoint,
      'reason' => 'Primary endpoint failed'
    )));
    
    $alt_res = Helpers\hic_http_request($alt_endpoint, array(
      'method'  => 'POST',
      'headers' => array(
        'accept' => 'application/json',
        'content-type' => 'application/json',
        'api-key' => Helpers\hic_get_brevo_api_key()
      ),
      'body' => wp_json_encode($body),
    ));
    
    $alt_result = hic_handle_brevo_response($alt_res, 'event_test_alt', array(
      'test_email' => $test_email,
      'endpoint' => $alt_endpoint,
      'event' => 'test_connectivity'
    ));
    
    $response_data['alternative_test'] = array(
      'success' => $alt_result['success'],
      'message' => $alt_result['success'] ? 'Alternative endpoint successful' : $alt_result['error'],
      'endpoint' => $alt_endpoint,
      'http_code' => isset($alt_result['log_data']['HTTP']) ? $alt_result['log_data']['HTTP'] : 'N/A'
    );
    
    if ($alt_result['success']) {
      $response_data['recommendation'] = 'Consider using alternative endpoint: ' . $alt_endpoint;
    }
  }
  
  return $response_data;
}