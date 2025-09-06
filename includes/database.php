<?php
/**
 * Database operations for HIC Plugin
 */

if (!defined('ABSPATH')) exit;

/* ============ DB: tabella sid↔gclid/fbclid ============ */
function hic_create_database_table(){
  global $wpdb;
  
  // Check if wpdb is available
  if (!$wpdb) {
    Helpers\hic_log('hic_create_database_table: wpdb is not available');
    return false;
  }
  
  $table = $wpdb->prefix . 'hic_gclids';
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
  
  $result = dbDelta($sql);
  
  if ($result === false) {
    Helpers\hic_log('hic_create_database_table: Failed to create table ' . $table);
    return false;
  }
  
  // Verify table was created
  $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
  if (!$table_exists) {
    Helpers\hic_log('hic_create_database_table: Table creation verification failed for ' . $table);
    return false;
  }
  
  Helpers\hic_log('DB ready: '.$table);
  
  // Create real-time sync state table
  return hic_create_realtime_sync_table();
}

/* ============ DB: tabella stati sync real-time per Brevo ============ */
function hic_create_realtime_sync_table(){
  global $wpdb;
  
  // Check if wpdb is available
  if (!$wpdb) {
    Helpers\hic_log('hic_create_realtime_sync_table: wpdb is not available');
    return false;
  }
  
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
  
  $result = dbDelta($sql);
  
  if ($result === false) {
    Helpers\hic_log('hic_create_realtime_sync_table: Failed to create table ' . $table);
    return false;
  }
  
  // Verify table was created
  $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
  if (!$table_exists) {
    Helpers\hic_log('hic_create_realtime_sync_table: Table creation verification failed for ' . $table);
    return false;
  }
  
  Helpers\hic_log('DB ready: '.$table.' (realtime sync states)');
  
  // Create booking events queue table
  return hic_create_booking_events_table();
}

/* ============ DB: tabella queue eventi prenotazioni ============ */
function hic_create_booking_events_table(){
  global $wpdb;
  
  // Check if wpdb is available
  if (!$wpdb) {
    Helpers\hic_log('hic_create_booking_events_table: wpdb is not available');
    return false;
  }
  
  $table = $wpdb->prefix . 'hic_booking_events';
  $charset = $wpdb->get_charset_collate();
  
  $sql = "CREATE TABLE IF NOT EXISTS $table (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    booking_id VARCHAR(255) NOT NULL,
    version_hash VARCHAR(64) NOT NULL,
    raw_data TEXT NOT NULL,
    poll_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed TINYINT(1) DEFAULT 0,
    processed_at TIMESTAMP NULL,
    process_attempts INT DEFAULT 0,
    last_error TEXT NULL,
    UNIQUE KEY unique_booking_version (booking_id, version_hash),
    KEY booking_id_idx (booking_id),
    KEY poll_timestamp_idx (poll_timestamp),
    KEY processed_idx (processed)
  ) $charset;";
  
  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  
  $result = dbDelta($sql);
  
  if ($result === false) {
    Helpers\hic_log('hic_create_booking_events_table: Failed to create table ' . $table);
    return false;
  }
  
  // Verify table was created
  $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
  if (!$table_exists) {
    Helpers\hic_log('hic_create_booking_events_table: Table creation verification failed for ' . $table);
    return false;
  }
  
  Helpers\hic_log('DB ready: '.$table.' (booking events queue)');
  return true;
}

/* ============ Cattura gclid/fbclid → cookie + DB ============ */
function hic_capture_tracking_params(){
  global $wpdb;
  
  // Check if wpdb is available
  if (!$wpdb) {
    error_log('HIC Plugin: wpdb is not available in hic_capture_tracking_params');
    return false;
  }
  
  $table = $wpdb->prefix . 'hic_gclids';

  // Check if table exists
  $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
  if (!$table_exists) {
    Helpers\hic_log('hic_capture_tracking_params: Table does not exist, creating: ' . $table);
    hic_create_database_table();
    // Re-check if table exists after creation
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    if (!$table_exists) {
      Helpers\hic_log('hic_capture_tracking_params: Failed to create table: ' . $table);
      return false;
    }
  }

  // Get existing SID or create new one if it doesn't exist
  $existing_sid = isset($_COOKIE['hic_sid']) ? sanitize_text_field($_COOKIE['hic_sid']) : null;
  
  if (!empty($_GET['gclid'])) {
    $gclid = sanitize_text_field($_GET['gclid']);
    
    // Validate gclid format (basic validation)
    if (strlen($gclid) < 10 || strlen($gclid) > 255) {
      Helpers\hic_log('hic_capture_tracking_params: Invalid gclid format: ' . $gclid);
      return false;
    }
    
    // Use existing SID if available, otherwise use gclid as SID
    $sid_to_use = $existing_sid ?: $gclid;
    
    // Only update cookie if we don't have an existing SID or if existing SID was the gclid
    if (!$existing_sid || $existing_sid === $gclid) {
      $secure_flag = is_ssl();
      $httponly_flag = true;
      $cookie_set = setcookie('hic_sid', $gclid, time() + 60*60*24*90, '/', $secure_flag, $httponly_flag);
      if ($cookie_set) {
        $_COOKIE['hic_sid'] = $gclid;
      } else {
        Helpers\hic_log('hic_capture_tracking_params: Failed to set gclid cookie');
      }
    }
    
    // Store the association between gclid and SID (avoid duplicates)
    $existing = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM $table WHERE gclid = %s AND sid = %s LIMIT 1", 
      $gclid, $sid_to_use
    ));
    
    if ($wpdb->last_error) {
      Helpers\hic_log('hic_capture_tracking_params: Database error checking existing gclid: ' . $wpdb->last_error);
      return false;
    }
    
    if (!$existing) {
      $insert_result = $wpdb->insert($table, ['gclid'=>$gclid, 'sid'=>$sid_to_use]);
      if ($insert_result === false) {
        Helpers\hic_log('hic_capture_tracking_params: Failed to insert gclid: ' . ($wpdb->last_error ?: 'Unknown error'));
        return false;
      }
    }
    Helpers\hic_log("GCLID salvato → $gclid (SID: $sid_to_use)");
  }

  if (!empty($_GET['fbclid'])) {
    $fbclid = sanitize_text_field($_GET['fbclid']);
    
    // Validate fbclid format (basic validation)
    if (strlen($fbclid) < 10 || strlen($fbclid) > 255) {
      Helpers\hic_log('hic_capture_tracking_params: Invalid fbclid format: ' . $fbclid);
      return false;
    }
    
    // Use existing SID if available, otherwise use fbclid as SID
    $sid_to_use = $existing_sid ?: $fbclid;
    
    // Only update cookie if we don't have an existing SID or if existing SID was the fbclid
    if (!$existing_sid || $existing_sid === $fbclid) {
      $secure_flag = is_ssl();
      $httponly_flag = true;
      $cookie_set = setcookie('hic_sid', $fbclid, time() + 60*60*24*90, '/', $secure_flag, $httponly_flag);
      if ($cookie_set) {
        $_COOKIE['hic_sid'] = $fbclid;
      } else {
        Helpers\hic_log('hic_capture_tracking_params: Failed to set fbclid cookie');
      }
    }
    
    // Store the association between fbclid and SID (avoid duplicates)
    $existing = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM $table WHERE fbclid = %s AND sid = %s LIMIT 1", 
      $fbclid, $sid_to_use
    ));
    
    if ($wpdb->last_error) {
      Helpers\hic_log('hic_capture_tracking_params: Database error checking existing fbclid: ' . $wpdb->last_error);
      return false;
    }
    
    if (!$existing) {
      $insert_result = $wpdb->insert($table, ['fbclid'=>$fbclid, 'sid'=>$sid_to_use]);
      if ($insert_result === false) {
        Helpers\hic_log('hic_capture_tracking_params: Failed to insert fbclid: ' . ($wpdb->last_error ?: 'Unknown error'));
        return false;
      }
    }
    Helpers\hic_log("FBCLID salvato → $fbclid (SID: $sid_to_use)");
  }
  
  return true;
}

/* ============ Funzioni per gestione stati sync real-time ============ */

/**
 * Check if a reservation is new for real-time sync
 */
function hic_is_reservation_new_for_realtime($reservation_id) {
  if (empty($reservation_id) || !is_scalar($reservation_id)) {
    Helpers\hic_log('hic_is_reservation_new_for_realtime: Invalid reservation_id');
    return false;
  }
  
  global $wpdb;
  
  // Check if wpdb is available
  if (!$wpdb) {
    Helpers\hic_log('hic_is_reservation_new_for_realtime: wpdb is not available');
    return false;
  }
  
  $table = $wpdb->prefix . 'hic_realtime_sync';
  
  // Check if table exists
  $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
  if (!$table_exists) {
    Helpers\hic_log('hic_is_reservation_new_for_realtime: Table does not exist: ' . $table);
    return true; // Assume new if table doesn't exist
  }
  
  $existing = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM $table WHERE reservation_id = %s LIMIT 1",
    $reservation_id
  ));
  
  if ($wpdb->last_error) {
    Helpers\hic_log('hic_is_reservation_new_for_realtime: Database error: ' . $wpdb->last_error);
    return true; // Assume new on error
  }
  
  return !$existing;
}

/**
 * Mark reservation as seen (new) for real-time sync
 */
function hic_mark_reservation_new_for_realtime($reservation_id) {
  if (empty($reservation_id) || !is_scalar($reservation_id)) {
    Helpers\hic_log('hic_mark_reservation_new_for_realtime: Invalid reservation_id');
    return false;
  }
  
  global $wpdb;
  
  // Check if wpdb is available
  if (!$wpdb) {
    Helpers\hic_log('hic_mark_reservation_new_for_realtime: wpdb is not available');
    return false;
  }
  
  $table = $wpdb->prefix . 'hic_realtime_sync';
  
  // Check if table exists
  $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
  if (!$table_exists) {
    Helpers\hic_log('hic_mark_reservation_new_for_realtime: Table does not exist, creating: ' . $table);
    if (!hic_create_realtime_sync_table()) {
      return false;
    }
  }
  
  // Insert if not exists
  $result = $wpdb->query($wpdb->prepare(
    "INSERT IGNORE INTO $table (reservation_id, sync_status) VALUES (%s, 'new')",
    $reservation_id
  ));
  
  if ($result === false) {
    Helpers\hic_log('hic_mark_reservation_new_for_realtime: Database error: ' . ($wpdb->last_error ?: 'Unknown error'));
    return false;
  }
  
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
 * Mark reservation notification as permanently failed (non-retryable)
 */
function hic_mark_reservation_notification_permanent_failure($reservation_id, $error_message = null) {
  if (empty($reservation_id)) return false;
  
  global $wpdb;
  $table = $wpdb->prefix . 'hic_realtime_sync';
  
  $result = $wpdb->update(
    $table,
    array(
      'sync_status' => 'permanent_failure',
      'last_attempt' => current_time('mysql'),
      'last_error' => $error_message
    ),
    array('reservation_id' => $reservation_id),
    array('%s', '%s', '%s'),
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