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
function hic_get_brevo_list_it() { return hic_get_option('brevo_list_it', '20'); }
function hic_get_brevo_list_en() { return hic_get_option('brevo_list_en', '21'); }
function hic_get_brevo_list_default() { return hic_get_option('brevo_list_default', '20'); }
function hic_get_brevo_optin_default() { return hic_get_option('brevo_optin_default', '0') === '1'; }
function hic_is_brevo_enabled() { return hic_get_option('brevo_enabled', '0') === '1'; }
function hic_is_debug_verbose() { return hic_get_option('debug_verbose', '0') === '1'; }

// New email enrichment settings
function hic_updates_enrich_contacts() { return hic_get_option('updates_enrich_contacts', '1') === '1'; }
function hic_get_brevo_list_alias() { return hic_get_option('brevo_list_alias', ''); }
function hic_brevo_double_optin_on_enrich() { return hic_get_option('brevo_double_optin_on_enrich', '0') === '1'; }

// Real-time sync settings
function hic_realtime_brevo_sync_enabled() { return hic_get_option('realtime_brevo_sync', '1') === '1'; }
function hic_get_brevo_event_endpoint() { 
    return hic_get_option('brevo_event_endpoint', 'https://in-automate.brevo.com/api/v2/trackEvent'); 
}

// Reliable polling settings
function hic_reliable_polling_enabled() { return hic_get_option('reliable_polling_enabled', '1') === '1'; }

// Admin and General Settings
function hic_get_admin_email() { return hic_get_option('admin_email', get_option('admin_email')); }
function hic_get_log_file() { return hic_get_option('log_file', WP_CONTENT_DIR . '/hic-log.txt'); }

// Facebook Settings
function hic_get_fb_pixel_id() { return hic_get_option('fb_pixel_id', ''); }
function hic_get_fb_access_token() { return hic_get_option('fb_access_token', ''); }

// Hotel in Cloud Connection Settings (with wp-config.php constants support)
function hic_get_connection_type() { return hic_get_option('connection_type', 'webhook'); }
function hic_get_webhook_token() { return hic_get_option('webhook_token', ''); }
function hic_get_api_url() { return hic_get_option('api_url', ''); }
function hic_get_api_key() { return hic_get_option('api_key', ''); }

function hic_get_api_email() { 
    // Check for wp-config.php constant first, then fall back to option
    if (defined('HIC_API_EMAIL') && !empty(HIC_API_EMAIL)) {
        return HIC_API_EMAIL;
    }
    return hic_get_option('api_email', ''); 
}

function hic_get_api_password() { 
    // Check for wp-config.php constant first, then fall back to option
    if (defined('HIC_API_PASSWORD') && !empty(HIC_API_PASSWORD)) {
        return HIC_API_PASSWORD;
    }
    return hic_get_option('api_password', ''); 
}

function hic_get_property_id() { 
    // Check for wp-config.php constant first, then fall back to option
    if (defined('HIC_PROPERTY_ID') && !empty(HIC_PROPERTY_ID)) {
        return HIC_PROPERTY_ID;
    }
    return hic_get_option('property_id', ''); 
}

/**
 * Helper function to check if Basic Auth credentials are configured
 */
function hic_has_basic_auth_credentials() {
    return hic_get_property_id() && hic_get_api_email() && hic_get_api_password();
}

// HIC Extended Integration Settings
function hic_get_currency() { return hic_get_option('currency', 'EUR'); }
function hic_use_net_value() { return hic_get_option('ga4_use_net_value', '0') === '1'; }
function hic_process_invalid() { return hic_get_option('process_invalid', '0') === '1'; }
function hic_allow_status_updates() { return hic_get_option('allow_status_updates', '0') === '1'; }
function hic_get_polling_range_extension_days() { return intval(hic_get_option('polling_range_extension_days', '7')); }

/**
 * Get configured polling interval for quasi-realtime polling
 */
function hic_get_polling_interval() { 
    $interval = hic_get_option('polling_interval', 'every_two_minutes'); 
    $valid_intervals = array('every_minute', 'every_two_minutes', 'hic_poll_interval', 'hic_reliable_interval');
    return in_array($interval, $valid_intervals) ? $interval : 'every_two_minutes';
}

/**
 * Quasi-realtime polling lock functions
 */
function hic_acquire_polling_lock($timeout = 300) {
    $lock_key = 'hic_polling_lock';
    $lock_value = time();
    
    // Check if lock exists and is still valid
    $existing_lock = get_transient($lock_key);
    if ($existing_lock && ($lock_value - $existing_lock) < $timeout) {
        return false; // Lock is held by another process
    }
    
    // Acquire lock
    return set_transient($lock_key, $lock_value, $timeout);
}

function hic_release_polling_lock() {
    return delete_transient('hic_polling_lock');
}

/**
 * Check if retry event should be scheduled based on conditions
 */
function hic_should_schedule_retry_event() {
    if (!hic_realtime_brevo_sync_enabled()) {
        return false;
    }
    
    if (!hic_get_brevo_api_key()) {
        return false;
    }
    
    $schedules = wp_get_schedules();
    return isset($schedules['hic_retry_interval']);
}

/* ============ New Helper Functions ============ */
function hic_normalize_price($value) {
    if (empty($value) || (!is_numeric($value) && !is_string($value))) return 0.0;
    
    // Convert comma to dot and ensure numeric
    $normalized = str_replace(',', '.', (string) $value);
    // Remove any non-numeric characters except dots and minus signs for negative values
    $normalized = preg_replace('/[^0-9.-]/', '', $normalized);
    
    // Validate that we still have a numeric value
    if (!is_numeric($normalized)) {
        hic_log('hic_normalize_price: Invalid numeric value after normalization: ' . $value);
        return 0.0;
    }
    
    $result = floatval($normalized);
    
    // Validate reasonable price range
    if ($result < 0) {
        hic_log('hic_normalize_price: Negative price detected: ' . $result . ' (original: ' . $value . ')');
        return 0.0;
    }
    
    if ($result > 999999.99) {
        hic_log('hic_normalize_price: Unusually high price detected: ' . $result . ' (original: ' . $value . ')');
    }
    
    return $result;
}

function hic_is_valid_email($email) {
    if (empty($email) || !is_string($email)) return false;
    
    // Sanitize email first
    $email = sanitize_email($email);
    if (empty($email)) return false;
    
    // Use WordPress built-in email validation
    return is_email($email);
}

function hic_is_ota_alias_email($e){
    if (empty($e) || !is_string($e)) return false;
    $e = strtolower(trim($e));
    
    // Validate email format first
    if (!filter_var($e, FILTER_VALIDATE_EMAIL)) return false;
    
    $domains = array(
      'guest.booking.com', 'message.booking.com',
      'guest.airbnb.com','airbnb.com',
      'expedia.com','stay.expedia.com','guest.expediapartnercentral.com'
    );
    
    foreach ($domains as $d) {
        if (substr($e, -strlen('@'.$d)) === '@'.$d) return true;
    }
    return false;
}

function hic_booking_uid($reservation) {
    if (!is_array($reservation)) {
        hic_log('hic_booking_uid: reservation is not an array');
        return '';
    }
    
    // Try multiple possible ID fields in order of preference
    $id_fields = ['id', 'reservation_id', 'booking_id', 'transaction_id'];
    
    foreach ($id_fields as $field) {
        if (!empty($reservation[$field]) && is_scalar($reservation[$field])) {
            return (string) $reservation[$field];
        }
    }
    
    hic_log('hic_booking_uid: No valid ID found in reservation data');
    return '';
}

/* ============ Helpers ============ */
function hic_log($msg){
  $date = date('Y-m-d H:i:s');
  $line = '['.$date.'] ' . (is_scalar($msg) ? $msg : print_r($msg, true)) . "\n";
  
  $log_file = hic_get_log_file();
  if (empty($log_file)) {
    error_log('HIC Plugin: Log file path is empty');
    return false;
  }
  
  // Check if log directory exists and is writable
  $log_dir = dirname($log_file);
  if (!is_dir($log_dir)) {
    if (!wp_mkdir_p($log_dir)) {
      error_log('HIC Plugin: Cannot create log directory: ' . $log_dir);
      return false;
    }
  }
  
  if (!is_writable($log_dir)) {
    error_log('HIC Plugin: Log directory is not writable: ' . $log_dir);
    return false;
  }
  
  $result = file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
  if ($result === false) {
    error_log('HIC Plugin: Failed to write to log file: ' . $log_file);
    return false;
  }
  
  return true;
}

/**
 * Rotate log file if it exceeds 5MB
 */
function hic_rotate_log_if_needed() {
    $log_file = hic_get_log_file();
    if (empty($log_file) || !file_exists($log_file)) {
        return false;
    }
    
    // Check if filesize() works (file could be locked)
    $file_size = @filesize($log_file);
    if ($file_size === false) {
        error_log('HIC Plugin: Cannot get log file size: ' . $log_file);
        return false;
    }
    
    $max_size = 5 * 1024 * 1024; // 5MB
    if ($file_size > $max_size) {
        $backup_file = $log_file . '.old';
        
        // Remove old backup if exists
        if (file_exists($backup_file)) {
            if (!@unlink($backup_file)) {
                error_log('HIC Plugin: Cannot remove old backup file: ' . $backup_file);
                return false;
            }
        }
        
        // Rotate current log to backup
        if (!@rename($log_file, $backup_file)) {
            error_log('HIC Plugin: Cannot rotate log file from ' . $log_file . ' to ' . $backup_file);
            return false;
        }
        
        hic_log('Log rotated: file exceeded 5MB limit');
        return true;
    }
    
    return true;
}

/**
 * Normalize bucket attribution according to priority: gclid > fbclid > organic
 * 
 * @param string|null $gclid Google Click ID from Google Ads
 * @param string|null $fbclid Facebook Click ID from Meta Ads
 * @return string One of: 'gads', 'fbads', 'organic'
 */
function fp_normalize_bucket($gclid, $fbclid){
  if (!empty($gclid) && trim($gclid) !== '')  return 'gads';
  if (!empty($fbclid) && trim($fbclid) !== '') return 'fbads';
  return 'organic';
}

/**
 * Legacy function name for backward compatibility
 * @deprecated Use fp_normalize_bucket() instead
 */
function hic_get_bucket($gclid, $fbclid){
  return fp_normalize_bucket($gclid, $fbclid);
}

/* ============ Email admin (include bucket) ============ */
function hic_send_admin_email($data, $gclid, $fbclid, $sid){
  // Validate input data
  if (!is_array($data)) {
    hic_log('hic_send_admin_email: data is not an array');
    return false;
  }
  
  $bucket = fp_normalize_bucket($gclid, $fbclid);
  $to = hic_get_admin_email();
  
  // Validate admin email
  if (empty($to) || !hic_is_valid_email($to)) {
    hic_log('hic_send_admin_email: invalid admin email: ' . $to);
    return false;
  }
  
  // Log which admin email is being used for transparency
  $custom_email = hic_get_option('admin_email', '');
  if (!empty($custom_email)) {
    hic_log('hic_send_admin_email: using custom admin email from settings: ' . $to);
  } else {
    hic_log('hic_send_admin_email: using WordPress default admin email: ' . $to);
  }
  
  $site_name = get_bloginfo('name');
  if (empty($site_name)) {
    $site_name = 'Hotel in Cloud';
  }
  
  $subject = "Nuova prenotazione da " . $site_name;

  $body  = "Hai ricevuto una nuova prenotazione da $site_name:\n\n";
  $body .= "Reservation ID: " . ($data['reservation_id'] ?? ($data['id'] ?? 'n/a')) . "\n";
  $body .= "Importo: " . (isset($data['amount']) ? hic_normalize_price($data['amount']) : '0') . " " . ($data['currency'] ?? 'EUR') . "\n";
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
  
  $sent = wp_mail($to, $subject, $body);
  
  remove_filter('wp_mail_content_type', $content_type_filter);

  if ($sent) {
    hic_log('Email admin inviata (bucket='.$bucket.') a '.$to);
    return true;
  } else {
    hic_log('Errore invio email admin a '.$to);
    return false;
  }
}



/* ============ Email Enrichment Functions ============ */
function hic_mark_email_enriched($reservation_id, $real_email) {
    if (empty($reservation_id) || !is_scalar($reservation_id)) {
        hic_log('hic_mark_email_enriched: reservation_id is empty or not scalar');
        return false;
    }
    
    if (empty($real_email) || !is_string($real_email) || !hic_is_valid_email($real_email)) {
        hic_log('hic_mark_email_enriched: real_email is empty, not string, or invalid email format');
        return false;
    }
    
    $email_map = get_option('hic_res_email_map', array());
    if (!is_array($email_map)) {
        $email_map = array(); // Reset if corrupted
    }
    
    $email_map[$reservation_id] = $real_email;
    
    // Keep only last 5k entries (FIFO) to prevent bloat
    if (count($email_map) > 5000) {
        $email_map = array_slice($email_map, -5000, null, true);
    }
    
    return update_option('hic_res_email_map', $email_map, false); // autoload=false
}

function hic_get_reservation_email($reservation_id) {
    if (empty($reservation_id) || !is_scalar($reservation_id)) {
        return null;
    }
    
    $email_map = get_option('hic_res_email_map', array());
    if (!is_array($email_map)) {
        return null; // Corrupted data
    }
    
    return isset($email_map[$reservation_id]) ? $email_map[$reservation_id] : null;
}