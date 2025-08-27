<?php
/**
 * Helper functions for HIC Plugin
 */

if (!defined('ABSPATH')) exit;

/* ================= CONFIG FUNCTIONS ================= */
function hic_get_option($key, $default = '') {
    return get_option('hic_' . $key, $default);
}

// Helper functions to get configuration values
function hic_get_measurement_id() { return hic_get_option('measurement_id', ''); }
function hic_get_api_secret() { return hic_get_option('api_secret', ''); }
function hic_get_brevo_api_key() { return hic_get_option('brevo_api_key', ''); }
function hic_get_brevo_list_ita() { return hic_get_option('brevo_list_ita', '20'); }
function hic_get_brevo_list_eng() { return hic_get_option('brevo_list_eng', '21'); }
function hic_get_fb_pixel_id() { return hic_get_option('fb_pixel_id', ''); }
function hic_get_fb_access_token() { return hic_get_option('fb_access_token', ''); }
function hic_get_webhook_token() { return hic_get_option('webhook_token', ''); }
function hic_get_admin_email() { return hic_get_option('admin_email', get_option('admin_email')); }
function hic_get_log_file() { return hic_get_option('log_file', WP_CONTENT_DIR . '/hic-ga4.log'); }
function hic_is_brevo_enabled() { return hic_get_option('brevo_enabled', '1') === '1'; }
function hic_get_connection_type() { return hic_get_option('connection_type', 'webhook'); }
function hic_get_api_url() { return hic_get_option('api_url', ''); }
function hic_get_api_key() { return hic_get_option('api_key', ''); }

// New API Basic Auth functions with PHP constant support
function hic_get_api_email() { 
    return defined('HIC_API_EMAIL') ? HIC_API_EMAIL : hic_get_option('api_email',''); 
}
function hic_get_api_password() { 
    return defined('HIC_API_PASSWORD') ? HIC_API_PASSWORD : hic_get_option('api_password',''); 
}
function hic_get_property_id() { 
    return defined('HIC_PROPERTY_ID') ? HIC_PROPERTY_ID : hic_get_option('property_id',''); 
}

/* ============ New Extended HIC Integration Functions ============ */
function hic_get_currency() { return hic_get_option('currency', 'EUR'); }
function hic_use_net_value() { return hic_get_option('ga4_use_net_value', '0') === '1'; }
function hic_process_invalid() { return hic_get_option('process_invalid', '0') === '1'; }
function hic_allow_status_updates() { return hic_get_option('allow_status_updates', '1') === '1'; }
function hic_get_brevo_list_it() { return hic_get_option('brevo_list_it', '20'); }
function hic_get_brevo_list_en() { return hic_get_option('brevo_list_en', '21'); }
function hic_get_brevo_list_default() { return hic_get_option('brevo_list_default', '20'); }
function hic_get_brevo_optin_default() { return hic_get_option('brevo_optin_default', '0') === '1'; }
function hic_is_debug_verbose() { return hic_get_option('debug_verbose', '0') === '1'; }

/* ============ New Helper Functions ============ */
function hic_normalize_price($value) {
    if (empty($value)) return 0.0;
    // Convert comma to dot and ensure numeric
    $normalized = str_replace(',', '.', (string) $value);
    return floatval($normalized);
}

function hic_is_valid_email($email) {
    return !empty($email) && is_email($email);
}

function hic_booking_uid($reservation) {
    return $reservation['id'] ?? '';
}

/* ============ Helpers ============ */
function hic_log($msg){
  $date = date('Y-m-d H:i:s');
  $line = '['.$date.'] ' . (is_scalar($msg) ? $msg : print_r($msg, true)) . "\n";
  @file_put_contents(hic_get_log_file(), $line, FILE_APPEND);
}

function hic_get_bucket($gclid, $fbclid){
  if (!empty($gclid))  return 'gads';
  if (!empty($fbclid)) return 'fbads';
  return 'organic';
}

/* ============ Email admin (include bucket) ============ */
function hic_send_admin_email($data, $gclid, $fbclid, $sid){
  $bucket    = hic_get_bucket($gclid, $fbclid);
  $to        = hic_get_admin_email();
  $site_name = get_bloginfo('name');
  $subject   = "Nuova prenotazione da " . $site_name;

  $body  = "Hai ricevuto una nuova prenotazione da $site_name:\n\n";
  $body .= "Reservation ID: " . ($data['reservation_id'] ?? ($data['id'] ?? 'n/a')) . "\n";
  $body .= "Importo: " . (isset($data['amount']) ? $data['amount'] : '0') . " " . ($data['currency'] ?? 'EUR') . "\n";
  $body .= "Nome: " . trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')) . "\n";
  $body .= "Email: " . ($data['email'] ?? 'n/a') . "\n";
  $body .= "Lingua: " . ($data['lingua'] ?? ($data['lang'] ?? 'n/a')) . "\n";
  $body .= "Camera: " . ($data['room'] ?? 'n/a') . "\n";
  $body .= "Check-in: " . ($data['checkin'] ?? 'n/a') . "\n";
  $body .= "Check-out: " . ($data['checkout'] ?? 'n/a') . "\n";
  $body .= "SID: " . ($sid ?? 'n/a') . "\n";
  $body .= "GCLID: " . ($gclid ?? 'n/a') . "\n";
  $body .= "FBCLID: " . ($fbclid ?? 'n/a') . "\n";
  $body .= "Bucket: " . $bucket . "\n";

  $content_type_filter = function(){ return 'text/plain; charset=UTF-8'; };
  add_filter('wp_mail_content_type', $content_type_filter);
  wp_mail($to, $subject, $body);
  remove_filter('wp_mail_content_type', $content_type_filter);

  hic_log('Email admin inviata (bucket='.$bucket.') a '.$to);
}