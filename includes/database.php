<?php
/**
 * Database operations for HIC Plugin
 */

if (!defined('ABSPATH')) exit;

/* ============ DB: tabella sid↔gclid/fbclid ============ */
function hic_create_database_table(){
  global $wpdb;
  $table   = $wpdb->prefix . 'hic_gclids';
  $charset = $wpdb->get_charset_collate();
  $sql = "CREATE TABLE IF NOT EXISTS $table (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    gclid  VARCHAR(255),
    fbclid VARCHAR(255),
    sid    VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY gclid (gclid(100)),
    KEY fbclid (fbclid(100)),
    KEY sid (sid(100))
  ) $charset;";
  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  dbDelta($sql);
  hic_log('DB ready: '.$table);
  
  // Create real-time sync state table
  hic_create_realtime_sync_table();
}

/* ============ DB: tabella stati sync real-time per Brevo ============ */
function hic_create_realtime_sync_table(){
  global $wpdb;
  $table = $wpdb->prefix . 'hic_realtime_sync';
  $charset = $wpdb->get_charset_collate();
  $sql = "CREATE TABLE IF NOT EXISTS $table (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    reservation_id VARCHAR(255) NOT NULL,
    sync_status ENUM('new', 'notified', 'failed') DEFAULT 'new',
    first_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_attempt TIMESTAMP NULL,
    attempt_count INT DEFAULT 0,
    last_error TEXT NULL,
    brevo_event_sent TINYINT(1) DEFAULT 0,
    UNIQUE KEY unique_reservation (reservation_id),
    KEY status_idx (sync_status),
    KEY first_seen_idx (first_seen)
  ) $charset;";
  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  dbDelta($sql);
  hic_log('DB ready: '.$table.' (realtime sync states)');
}

/* ============ Cattura gclid/fbclid → cookie + DB ============ */
function hic_capture_tracking_params(){
  global $wpdb;
  $table = $wpdb->prefix . 'hic_gclids';

  // Get existing SID or create new one if it doesn't exist
  $existing_sid = isset($_COOKIE['hic_sid']) ? sanitize_text_field($_COOKIE['hic_sid']) : null;
  
  if (!empty($_GET['gclid'])) {
    $gclid = sanitize_text_field($_GET['gclid']);
    
    // Use existing SID if available, otherwise use gclid as SID
    $sid_to_use = $existing_sid ?: $gclid;
    
    // Only update cookie if we don't have an existing SID or if existing SID was the gclid
    if (!$existing_sid || $existing_sid === $gclid) {
      setcookie('hic_sid', $gclid, time() + 60*60*24*90, '/', '', is_ssl(), true);
      $_COOKIE['hic_sid'] = $gclid;
    }
    
    // Store the association between gclid and SID (avoid duplicates)
    $existing = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM $table WHERE gclid = %s AND sid = %s LIMIT 1", 
      $gclid, $sid_to_use
    ));
    if (!$existing) {
      $wpdb->insert($table, ['gclid'=>$gclid, 'sid'=>$sid_to_use]);
    }
    hic_log("GCLID salvato → $gclid (SID: $sid_to_use)");
  }

  if (!empty($_GET['fbclid'])) {
    $fbclid = sanitize_text_field($_GET['fbclid']);
    
    // Use existing SID if available, otherwise use fbclid as SID
    $sid_to_use = $existing_sid ?: $fbclid;
    
    // Only update cookie if we don't have an existing SID or if existing SID was the fbclid
    if (!$existing_sid || $existing_sid === $fbclid) {
      setcookie('hic_sid', $fbclid, time() + 60*60*24*90, '/', '', is_ssl(), true);
      $_COOKIE['hic_sid'] = $fbclid;
    }
    
    // Store the association between fbclid and SID (avoid duplicates)
    $existing = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM $table WHERE fbclid = %s AND sid = %s LIMIT 1", 
      $fbclid, $sid_to_use
    ));
    if (!$existing) {
      $wpdb->insert($table, ['fbclid'=>$fbclid, 'sid'=>$sid_to_use]);
    }
    hic_log("FBCLID salvato → $fbclid (SID: $sid_to_use)");
  }
}

/* ============ Funzioni per gestione stati sync real-time ============ */

/**
 * Check if a reservation is new for real-time sync
 */
function hic_is_reservation_new_for_realtime($reservation_id) {
  if (empty($reservation_id)) return false;
  
  global $wpdb;
  $table = $wpdb->prefix . 'hic_realtime_sync';
  
  $existing = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM $table WHERE reservation_id = %s LIMIT 1",
    $reservation_id
  ));
  
  return !$existing;
}

/**
 * Mark reservation as seen (new) for real-time sync
 */
function hic_mark_reservation_new_for_realtime($reservation_id) {
  if (empty($reservation_id)) return false;
  
  global $wpdb;
  $table = $wpdb->prefix . 'hic_realtime_sync';
  
  // Insert if not exists
  $result = $wpdb->query($wpdb->prepare(
    "INSERT IGNORE INTO $table (reservation_id, sync_status) VALUES (%s, 'new')",
    $reservation_id
  ));
  
  return $result !== false;
}

/**
 * Mark reservation as successfully notified to Brevo
 */
function hic_mark_reservation_notified_to_brevo($reservation_id) {
  if (empty($reservation_id)) return false;
  
  global $wpdb;
  $table = $wpdb->prefix . 'hic_realtime_sync';
  
  $result = $wpdb->update(
    $table,
    array(
      'sync_status' => 'notified',
      'brevo_event_sent' => 1,
      'last_attempt' => current_time('mysql'),
      'last_error' => null
    ),
    array('reservation_id' => $reservation_id),
    array('%s', '%d', '%s', '%s'),
    array('%s')
  );
  
  return $result !== false;
}

/**
 * Mark reservation notification as failed
 */
function hic_mark_reservation_notification_failed($reservation_id, $error_message = null) {
  if (empty($reservation_id)) return false;
  
  global $wpdb;
  $table = $wpdb->prefix . 'hic_realtime_sync';
  
  // Get current attempt count
  $current = $wpdb->get_row($wpdb->prepare(
    "SELECT attempt_count FROM $table WHERE reservation_id = %s",
    $reservation_id
  ));
  
  $attempt_count = $current ? ($current->attempt_count + 1) : 1;
  
  $result = $wpdb->update(
    $table,
    array(
      'sync_status' => 'failed',
      'last_attempt' => current_time('mysql'),
      'attempt_count' => $attempt_count,
      'last_error' => $error_message
    ),
    array('reservation_id' => $reservation_id),
    array('%s', '%s', '%d', '%s'),
    array('%s')
  );
  
  return $result !== false;
}

/**
 * Get failed reservations that need retry
 */
function hic_get_failed_reservations_for_retry($max_attempts = 3, $retry_delay_minutes = 30) {
  global $wpdb;
  $table = $wpdb->prefix . 'hic_realtime_sync';
  
  $retry_time = date('Y-m-d H:i:s', strtotime("-{$retry_delay_minutes} minutes"));
  
  $results = $wpdb->get_results($wpdb->prepare(
    "SELECT reservation_id, attempt_count, last_error 
     FROM $table 
     WHERE sync_status = 'failed' 
     AND attempt_count < %d 
     AND (last_attempt IS NULL OR last_attempt < %s)
     ORDER BY first_seen ASC
     LIMIT 10",
    $max_attempts,
    $retry_time
  ));
  
  return $results ? $results : array();
}