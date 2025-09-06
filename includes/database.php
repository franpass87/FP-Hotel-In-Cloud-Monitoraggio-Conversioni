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

/* ============ DB: migrations ============ */
function hic_maybe_upgrade_db() {
  global $wpdb;

  // Ensure wpdb is available
  if (!$wpdb) {
    Helpers\hic_log('hic_maybe_upgrade_db: wpdb is not available');
    return;
  }

  $installed_version = get_option('hic_db_version');

  // If up to date, nothing to do
  if ($installed_version === HIC_DB_VERSION) {
    return;
  }

  // Make sure base tables exist
  hic_create_database_table();

  // Fresh install
  if (!$installed_version) {
    update_option('hic_db_version', HIC_DB_VERSION);
    return;
  }

  // Example migration to version 1.1
  if (version_compare($installed_version, '1.1', '<')) {
    $table = $wpdb->prefix . 'hic_realtime_sync';
    $column_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'brevo_event_sent'));
    if (empty($column_exists)) {
      $wpdb->query("ALTER TABLE $table ADD COLUMN brevo_event_sent TINYINT(1) DEFAULT 0");
    }
    update_option('hic_db_version', '1.1');
    $installed_version = '1.1';
  }

  // Set final version
  update_option('hic_db_version', HIC_DB_VERSION);
}

/**
 * Store a tracking identifier and manage cookie/DB operations
 *
 * @param string      $type         Tracking type (gclid or fbclid)
 * @param string      $value        Tracking identifier value
 * @param string|null $existing_sid Existing session identifier
 *
 * @return true|WP_Error True on success, WP_Error on failure
 */
function hic_store_tracking_id($type, $value, $existing_sid) {
  global $wpdb;

  // Ensure wpdb is available
  if (!$wpdb) {
    return new \WP_Error('no_db', 'wpdb is not available');
  }

  // Only allow specific tracking types
  $allowed_types = ['gclid', 'fbclid'];
  if (!in_array($type, $allowed_types, true)) {
    Helpers\hic_log('hic_store_tracking_id: Invalid type: ' . $type);
    return new \WP_Error('invalid_type', 'Invalid tracking type');
  }

  // Validate value length
  if (strlen($value) < 10 || strlen($value) > 255) {
    Helpers\hic_log("hic_store_tracking_id: Invalid $type format: $value");
    return new \WP_Error('invalid_length', 'Invalid tracking id length');
  }

  // Determine SID to use
  $sid_to_use = $existing_sid ?: $value;

  // Set cookie if needed
  if (!$existing_sid || $existing_sid === $value) {
    $cookie_set = setcookie('hic_sid', $value, [
      'expires'  => time() + 60 * 60 * 24 * 90,
      'path'     => '/',
      'secure'   => is_ssl(),
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
    if ($cookie_set) {
      $_COOKIE['hic_sid'] = $value;
    } else {
      Helpers\hic_log("hic_store_tracking_id: Failed to set $type cookie");
      // Not a fatal error; proceed
    }
  }

  $table = $wpdb->prefix . 'hic_gclids';

  // Check for existing association
  $existing = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM $table WHERE {$type} = %s AND sid = %s LIMIT 1",
    $value,
    $sid_to_use
  ));

  if ($wpdb->last_error) {
    Helpers\hic_log('hic_store_tracking_id: Database error checking existing ' . $type . ': ' . $wpdb->last_error);
    return new \WP_Error('db_select_error', 'Database error');
  }

  if (!$existing) {
    $insert_result = $wpdb->insert($table, [$type => $value, 'sid' => $sid_to_use]);
    if ($insert_result === false) {
      Helpers\hic_log('hic_store_tracking_id: Failed to insert ' . $type . ': ' . ($wpdb->last_error ?: 'Unknown error'));
      return new \WP_Error('db_insert_error', 'Failed to insert tracking id');
    }
  }

  Helpers\hic_log(strtoupper($type) . " salvato → $value (SID: $sid_to_use)");

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
    $result = hic_store_tracking_id('gclid', sanitize_text_field($_GET['gclid']), $existing_sid);
    if (is_wp_error($result)) {
      Helpers\hic_log('hic_capture_tracking_params: ' . $result->get_error_message());
      return false;
    }
  }

  if (!empty($_GET['fbclid'])) {
    $result = hic_store_tracking_id('fbclid', sanitize_text_field($_GET['fbclid']), $existing_sid);
    if (is_wp_error($result)) {
      Helpers\hic_log('hic_capture_tracking_params: ' . $result->get_error_message());
      return false;
    }
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

/* ============ Cleanup old GCLIDs ============ */
function hic_cleanup_old_gclids($days = 90) {
  if ($days <= 0) return 0;

  global $wpdb;

  // Check if wpdb is available
  if (!$wpdb) {
    Helpers\hic_log('hic_cleanup_old_gclids: wpdb is not available');
    return false;
  }

  $table = $wpdb->prefix . 'hic_gclids';
  $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

  // Delete records older than cutoff
  $deleted = $wpdb->query($wpdb->prepare(
    "DELETE FROM $table WHERE created_at < %s",
    $cutoff
  ));

  if ($deleted === false) {
    Helpers\hic_log('hic_cleanup_old_gclids: Database error: ' . $wpdb->last_error);
    return false;
  }

  Helpers\hic_log("hic_cleanup_old_gclids: Removed $deleted records older than $days days");

  return $deleted;
}
