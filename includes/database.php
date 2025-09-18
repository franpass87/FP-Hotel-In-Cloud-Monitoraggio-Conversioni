<?php declare(strict_types=1);
/**
 * Database operations for HIC Plugin
 */

if (!defined('ABSPATH')) exit;

/**
 * Safe logging helper that checks if the logging function is available
 */
function hic_safe_log($message, $level = null) {
    if (function_exists('FpHic\\Helpers\\hic_log')) {
        if ($level !== null) {
            \FpHic\Helpers\hic_log($message, $level);
        } else {
            \FpHic\Helpers\hic_log($message);
        }
    }
}

/* ============ DB: tabella sid↔gclid/fbclid ============ */
function hic_create_database_table(){
  global $wpdb;
  
  // Check if wpdb is available
  if (!$wpdb) {
    hic_log('hic_create_database_table: wpdb is not available');
    return false;
  }
  
  $table = $wpdb->prefix . 'hic_gclids';
  $charset = $wpdb->get_charset_collate();
  
  $sql = "CREATE TABLE IF NOT EXISTS $table (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    gclid        VARCHAR(255),
    fbclid       VARCHAR(255),
    msclkid      VARCHAR(255),
    ttclid       VARCHAR(255),
    sid          VARCHAR(255),
    utm_source   VARCHAR(255),
    utm_medium   VARCHAR(255),
    utm_campaign VARCHAR(255),
    utm_content  VARCHAR(255),
    utm_term     VARCHAR(255),
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY gclid (gclid(100)),
    KEY fbclid (fbclid(100)),
    KEY msclkid (msclkid(100)),
    KEY ttclid (ttclid(100)),
    KEY sid (sid(100)),
    KEY utm_source (utm_source(100)),
    KEY utm_medium (utm_medium(100)),
    KEY utm_campaign (utm_campaign(100)),
    KEY utm_content (utm_content(100)),
    KEY utm_term (utm_term(100))
  ) $charset;";
  
  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  
  $result = dbDelta($sql);
  
  if ($result === false) {
    hic_log('hic_create_database_table: Failed to create table ' . $table);
    return false;
  }
  
  // Verify table was created
  $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
  if (!$table_exists) {
    hic_log('hic_create_database_table: Table creation verification failed for ' . $table);
    return false;
  }
  
  hic_log('DB ready: '.$table);
  
  // Create real-time sync state table
  return hic_create_realtime_sync_table();
}

/* ============ DB: tabella stati sync real-time per Brevo ============ */
function hic_create_realtime_sync_table(){
  global $wpdb;
  
  // Check if wpdb is available
  if (!$wpdb) {
    hic_log('hic_create_realtime_sync_table: wpdb is not available');
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
    hic_log('hic_create_realtime_sync_table: Failed to create table ' . $table);
    return false;
  }
  
  // Verify table was created
  $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
  if (!$table_exists) {
    hic_log('hic_create_realtime_sync_table: Table creation verification failed for ' . $table);
    return false;
  }
  
  hic_log('DB ready: '.$table.' (realtime sync states)');
  
  // Create booking events queue table
  return hic_create_booking_events_table();
}

/* ============ DB: tabella queue eventi prenotazioni ============ */
function hic_create_booking_events_table(){
  global $wpdb;
  
  // Check if wpdb is available
  if (!$wpdb) {
    hic_log('hic_create_booking_events_table: wpdb is not available');
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
    hic_log('hic_create_booking_events_table: Failed to create table ' . $table);
    return false;
  }
  
  // Verify table was created
  $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
  if (!$table_exists) {
    hic_log('hic_create_booking_events_table: Table creation verification failed for ' . $table);
    return false;
  }
  
  hic_log('DB ready: '.$table.' (booking events queue)');
  return hic_create_failed_requests_table();
}

/* ============ DB: tabella richieste HTTP fallite ============ */
function hic_create_failed_requests_table(){
  global $wpdb;

  // Check if wpdb is available
  if (!$wpdb) {
    hic_log('hic_create_failed_requests_table: wpdb is not available');
    return false;
  }

  $table = $wpdb->prefix . 'hic_failed_requests';
  $charset = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE IF NOT EXISTS $table (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    endpoint TEXT NOT NULL,
    payload LONGTEXT NULL,
    attempts INT DEFAULT 0,
    last_error TEXT NULL,
    last_try TIMESTAMP NULL,
    KEY endpoint_idx (endpoint(191))
  ) $charset;";

  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

  $result = dbDelta($sql);

  if ($result === false) {
    hic_log('hic_create_failed_requests_table: Failed to create table ' . $table);
    return false;
  }

  // Verify table was created
  $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
  if (!$table_exists) {
    hic_log('hic_create_failed_requests_table: Table creation verification failed for ' . $table);
    return false;
  }

  hic_log('DB ready: '.$table.' (failed requests)');
  return true;
}

/**
 * Ensure UTM columns exist in the gclids table
 * This function checks and adds missing UTM columns dynamically
 */
function hic_ensure_utm_columns_exist() {
  global $wpdb;

  if (!$wpdb) {
    hic_log('hic_ensure_utm_columns_exist: wpdb is not available');
    return false;
  }

  $table = $wpdb->prefix . 'hic_gclids';
  
  // Check if table exists first
  $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
  if (!$table_exists) {
    hic_log('hic_ensure_utm_columns_exist: Table does not exist, creating: ' . $table);
    if (!hic_create_database_table()) {
      return false;
    }
  }

  // List of UTM columns that should exist
  $utm_columns = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
  
  foreach ($utm_columns as $column) {
    $column_exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", $column));
    if (empty($column_exists)) {
      $alter_sql = "ALTER TABLE $table ADD COLUMN $column VARCHAR(255)";
      $result = $wpdb->query($alter_sql);
      
      if ($result === false) {
        hic_log("hic_ensure_utm_columns_exist: Failed to add column $column: " . $wpdb->last_error);
        return false;
      } else {
        hic_log("hic_ensure_utm_columns_exist: Successfully added column $column to $table");
      }
    }
  }

  return true;
}

/* ============ DB: migrations ============ */
function hic_maybe_upgrade_db() {
  global $wpdb;

  // Ensure wpdb is available
  if (!$wpdb) {
    hic_safe_log('hic_maybe_upgrade_db: wpdb is not available');
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
    hic_clear_option_cache('hic_db_version');
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
    hic_clear_option_cache('hic_db_version');
    $installed_version = '1.1';
  }

  // Migration to version 1.2 - add UTM columns
  if (version_compare($installed_version, '1.2', '<')) {
    $table = $wpdb->prefix . 'hic_gclids';
    $columns = ['utm_source', 'utm_medium', 'utm_campaign'];
    foreach ($columns as $col) {
      $exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", $col));
      if (empty($exists)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN $col VARCHAR(255)");
      }
    }
    update_option('hic_db_version', '1.2');
    hic_clear_option_cache('hic_db_version');
    $installed_version = '1.2';
  }

  // Migration to version 1.3 - add msclkid and ttclid columns
  if (version_compare($installed_version, '1.3', '<')) {
    $table = $wpdb->prefix . 'hic_gclids';
    $columns = ['msclkid', 'ttclid'];
    foreach ($columns as $col) {
      $exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", $col));
      if (empty($exists)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN $col VARCHAR(255)");
      }
    }
    update_option('hic_db_version', '1.3');
    hic_clear_option_cache('hic_db_version');
    $installed_version = '1.3';
  }

  // Migration to version 1.4 - add UTM content and term columns
  if (version_compare($installed_version, '1.4', '<')) {
    $table = $wpdb->prefix . 'hic_gclids';
    $columns = ['utm_content', 'utm_term'];
    foreach ($columns as $col) {
      $exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", $col));
      if (empty($exists)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN $col VARCHAR(255)");
      }
    }
    update_option('hic_db_version', '1.4');
    hic_clear_option_cache('hic_db_version');
    $installed_version = '1.4';
  }

  // Migration to version 1.5 - add failed requests table
  if (version_compare($installed_version, '1.5', '<')) {
    hic_create_failed_requests_table();
    update_option('hic_db_version', '1.5');
    hic_clear_option_cache('hic_db_version');
    $installed_version = '1.5';
  }

  // Set final version
  update_option('hic_db_version', HIC_DB_VERSION);
  hic_clear_option_cache('hic_db_version');
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
  $allowed_types = ['gclid', 'fbclid', 'msclkid', 'ttclid'];
  if (!in_array($type, $allowed_types, true)) {
    hic_log('hic_store_tracking_id: Invalid type: ' . $type);
    return new \WP_Error('invalid_type', 'Invalid tracking type');
  }

  // Validate value length
  if (strlen($value) < 10 || strlen($value) > 255) {
    hic_log("hic_store_tracking_id: Invalid $type format: $value");
    return new \WP_Error('invalid_length', 'Invalid tracking id length');
  }

  $sanitized_existing = '';
  if (is_string($existing_sid) && $existing_sid !== '') {
    $candidate = sanitize_text_field((string) $existing_sid);
    $candidate = substr($candidate, 0, HIC_SID_MAX_LENGTH);
    if ($candidate !== '' && strlen($candidate) >= HIC_SID_MIN_LENGTH) {
      $sanitized_existing = $candidate;
    }
  }

  // Determine SID to use
  $sid_to_use = $sanitized_existing !== '' ? $sanitized_existing : \FpHic\Helpers\hic_generate_sid();

  $cookie_args = apply_filters('hic_sid_cookie_args', [
    'expires'  => time() + 60*60*24*90,
    'path'     => '/',
    'secure'   => is_ssl(),
    'httponly' => false,
    'samesite' => 'Lax',
  ], $sid_to_use);

  $cookie_set = setcookie('hic_sid', $sid_to_use, $cookie_args);
  if (!$cookie_set) {
    hic_log("hic_store_tracking_id: Failed to set $type cookie");
  }
  $_COOKIE['hic_sid'] = $sid_to_use;

  $table = $wpdb->prefix . 'hic_gclids';

  // Check for existing association
  $existing = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM $table WHERE {$type} = %s AND sid = %s LIMIT 1",
    $value,
    $sid_to_use
  ));

  if ($wpdb->last_error) {
    hic_log('hic_store_tracking_id: Database error checking existing ' . $type . ': ' . $wpdb->last_error);
    return new \WP_Error('db_select_error', 'Database error');
  }

  if ($existing) {
    $sid_record_id = (int) $existing;
  } else {
    $sid_record_id = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM $table WHERE sid = %s ORDER BY id DESC LIMIT 1",
      $sid_to_use
    ));

    if ($wpdb->last_error) {
      hic_log('hic_store_tracking_id: Database error checking existing SID row: ' . $wpdb->last_error);
      return new \WP_Error('db_select_error', 'Database error');
    }

    if ($sid_record_id > 0) {
      $update_result = $wpdb->update(
        $table,
        [$type => $value],
        ['id' => $sid_record_id],
        ['%s'],
        ['%d']
      );

      if ($update_result === false) {
        hic_log('hic_store_tracking_id: Failed to update existing SID row for ' . $type . ': ' . ($wpdb->last_error ?: 'Unknown error'));
        return new \WP_Error('db_update_error', 'Failed to update tracking id');
      }
    } else {
      $insert_result = $wpdb->insert(
        $table,
        [$type => $value, 'sid' => $sid_to_use],
        ['%s', '%s']
      );
      if ($insert_result === false) {
        hic_log('hic_store_tracking_id: Failed to insert ' . $type . ': ' . ($wpdb->last_error ?: 'Unknown error'));
        return new \WP_Error('db_insert_error', 'Failed to insert tracking id');
      }
    }
  }

  hic_log(strtoupper($type) . " salvato → $value (SID: $sid_to_use)");

  return true;
}
/* ============ Cattura gclid/fbclid → cookie + DB ============ */
function hic_capture_tracking_params(){
  global $wpdb;

  // Check if wpdb is available
  if (!$wpdb) {
    hic_log('hic_capture_tracking_params: wpdb is not available', HIC_LOG_LEVEL_ERROR);
    return false;
  }
  
  $table = $wpdb->prefix . 'hic_gclids';

  // Check if table exists
  $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
  if (!$table_exists) {
    hic_log('hic_capture_tracking_params: Table does not exist, creating: ' . $table);
    hic_create_database_table();
    // Re-check if table exists after creation
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    if (!$table_exists) {
      hic_log('hic_capture_tracking_params: Failed to create table: ' . $table);
      return false;
    }
  }

  $normalize_sid = static function ($value) {
    if (!is_string($value) || $value === '') {
      return null;
    }

    $candidate = sanitize_text_field($value);
    $candidate = substr($candidate, 0, HIC_SID_MAX_LENGTH);

    if ($candidate === '' || strlen($candidate) < HIC_SID_MIN_LENGTH) {
      return null;
    }

    return $candidate;
  };

  $refresh_existing_sid = static function () use (&$existing_sid, $normalize_sid) {
    if (isset($_COOKIE['hic_sid'])) {
      $normalized_cookie = $normalize_sid(wp_unslash($_COOKIE['hic_sid']));
      if ($normalized_cookie !== null) {
        $existing_sid = $normalized_cookie;
      }
    }
  };

  // Get existing SID or create new one if it doesn't exist
  $existing_sid = null;
  if (isset($_COOKIE['hic_sid'])) {
    $normalized_cookie = $normalize_sid(wp_unslash($_COOKIE['hic_sid']));
    if ($normalized_cookie !== null) {
      $existing_sid = $normalized_cookie;
      $_COOKIE['hic_sid'] = $normalized_cookie;
    }
  }

  if (!empty($_GET['gclid'])) {
    $gclid = sanitize_text_field( wp_unslash( $_GET['gclid'] ) );
    $result = hic_store_tracking_id('gclid', $gclid, $existing_sid);
    if (is_wp_error($result)) {
      hic_log('hic_capture_tracking_params: ' . $result->get_error_message());
      return false;
    }
    $refresh_existing_sid();
  }

  if (!empty($_GET['fbclid'])) {
    $fbclid = sanitize_text_field( wp_unslash( $_GET['fbclid'] ) );
    $result = hic_store_tracking_id('fbclid', $fbclid, $existing_sid);
    if (is_wp_error($result)) {
      hic_log('hic_capture_tracking_params: ' . $result->get_error_message());
      return false;
    }
    $refresh_existing_sid();
  }

  if (!empty($_GET['msclkid'])) {
    $msclkid = sanitize_text_field( wp_unslash( $_GET['msclkid'] ) );
    $result = hic_store_tracking_id('msclkid', $msclkid, $existing_sid);
    if (is_wp_error($result)) {
      hic_log('hic_capture_tracking_params: ' . $result->get_error_message());
      return false;
    }
    $refresh_existing_sid();
  }

  if (!empty($_GET['ttclid'])) {
    $ttclid = sanitize_text_field( wp_unslash( $_GET['ttclid'] ) );
    $result = hic_store_tracking_id('ttclid', $ttclid, $existing_sid);
    if (is_wp_error($result)) {
      hic_log('hic_capture_tracking_params: ' . $result->get_error_message());
      return false;
    }
    $refresh_existing_sid();
  }

  // Capture UTM parameters if present
  $sid_for_utm = $existing_sid;
  if (isset($_COOKIE['hic_sid'])) {
    $normalized_for_utm = $normalize_sid(wp_unslash($_COOKIE['hic_sid']));
    if ($normalized_for_utm !== null) {
      $sid_for_utm = $normalized_for_utm;
      $_COOKIE['hic_sid'] = $normalized_for_utm;
    }
  }
  $utm_params = [];
  foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $utm_key) {
    if (!empty($_GET[$utm_key])) {
      $utm_params[$utm_key] = sanitize_text_field( wp_unslash( $_GET[$utm_key] ) );
    }
  }

  if (!empty($utm_params)) {
    // Ensure UTM columns exist before trying to store data
    if (!hic_ensure_utm_columns_exist()) {
      hic_log('hic_capture_tracking_params: Failed to ensure UTM columns exist');
      return false;
    }

    if ($sid_for_utm === null) {
      $sid_for_utm = \FpHic\Helpers\hic_generate_sid();
      $cookie_args = apply_filters('hic_sid_cookie_args', [
        'expires'  => time() + 60*60*24*90,
        'path'     => '/',
        'secure'   => is_ssl(),
        'httponly' => false,
        'samesite' => 'Lax',
      ], $sid_for_utm);
      if (!setcookie('hic_sid', $sid_for_utm, $cookie_args)) {
        hic_log('hic_capture_tracking_params: Failed to set SID cookie for UTM parameters');
      }
      $_COOKIE['hic_sid'] = $sid_for_utm;
    }

    $existing_row = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM $table WHERE sid = %s LIMIT 1",
      $sid_for_utm
    ));

    if ($wpdb->last_error) {
      hic_log('hic_capture_tracking_params: Database error checking existing sid for UTM: ' . $wpdb->last_error);
      return false;
    }

    if ($existing_row) {
      $formats = array_fill(0, count($utm_params), '%s');
      $wpdb->update($table, $utm_params, ['sid' => $sid_for_utm], $formats, ['%s']);
    } else {
      $data = array_merge(['sid' => $sid_for_utm], $utm_params);
      $insert_formats = array_fill(0, count($data), '%s');
      $wpdb->insert($table, $data, $insert_formats);
    }

    if ($wpdb->last_error) {
      hic_log('hic_capture_tracking_params: Database error storing UTM params: ' . $wpdb->last_error);
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
    hic_log('hic_is_reservation_new_for_realtime: Invalid reservation_id');
    return false;
  }
  
  global $wpdb;
  
  // Check if wpdb is available
  if (!$wpdb) {
    hic_log('hic_is_reservation_new_for_realtime: wpdb is not available');
    return false;
  }
  
  $table = $wpdb->prefix . 'hic_realtime_sync';
  
  // Check if table exists
  $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
  if (!$table_exists) {
    hic_log('hic_is_reservation_new_for_realtime: Table does not exist: ' . $table);
    return true; // Assume new if table doesn't exist
  }
  
  $existing = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM $table WHERE reservation_id = %s LIMIT 1",
    $reservation_id
  ));
  
  if ($wpdb->last_error) {
    hic_log('hic_is_reservation_new_for_realtime: Database error: ' . $wpdb->last_error);
    return true; // Assume new on error
  }
  
  return !$existing;
}

/**
 * Mark reservation as seen (new) for real-time sync
 */
function hic_mark_reservation_new_for_realtime($reservation_id) {
  if (empty($reservation_id) || !is_scalar($reservation_id)) {
    hic_log('hic_mark_reservation_new_for_realtime: Invalid reservation_id');
    return false;
  }
  
  global $wpdb;
  
  // Check if wpdb is available
  if (!$wpdb) {
    hic_log('hic_mark_reservation_new_for_realtime: wpdb is not available');
    return false;
  }
  
  $table = $wpdb->prefix . 'hic_realtime_sync';
  
  // Check if table exists
  $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
  if (!$table_exists) {
    hic_log('hic_mark_reservation_new_for_realtime: Table does not exist, creating: ' . $table);
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
    hic_log('hic_mark_reservation_new_for_realtime: Database error: ' . ($wpdb->last_error ?: 'Unknown error'));
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
  
  $retry_time = wp_date('Y-m-d H:i:s', strtotime("-{$retry_delay_minutes} minutes", current_time('timestamp')));
  
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
    hic_log('hic_cleanup_old_gclids: wpdb is not available');
    return false;
  }

  $table = $wpdb->prefix . 'hic_gclids';
  $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
  if ($exists !== $table) {
    $created = hic_create_database_table();
    if ($created === false) {
      hic_log('hic_cleanup_old_gclids: Failed to ensure table exists: ' . $table);
    }
    return 0;
  }
  $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

  // Delete records older than cutoff
  $deleted = $wpdb->query($wpdb->prepare(
    "DELETE FROM $table WHERE created_at < %s",
    $cutoff
  ));

  if ($deleted === false) {
    hic_log('hic_cleanup_old_gclids: Database error: ' . $wpdb->last_error);
    return false;
  }

  hic_log("hic_cleanup_old_gclids: Removed $deleted records older than $days days");

  return $deleted;
}

/* ============ Cleanup processed booking events ============ */
function hic_cleanup_booking_events($days = 30) {
  if ($days <= 0) return 0;

  global $wpdb;

  // Check if wpdb is available
  if (!$wpdb) {
    hic_log('hic_cleanup_booking_events: wpdb is not available');
    return false;
  }

  $table = $wpdb->prefix . 'hic_booking_events';
  $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
  if ($exists !== $table) {
    $created = hic_create_booking_events_table();
    if ($created === false) {
      hic_log('hic_cleanup_booking_events: Failed to ensure table exists: ' . $table);
    }
    return 0;
  }
  $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

  // Delete processed records older than cutoff
  $deleted = $wpdb->query($wpdb->prepare(
    "DELETE FROM $table WHERE processed = 1 AND processed_at IS NOT NULL AND processed_at < %s",
    $cutoff
  ));

  if ($deleted === false) {
    hic_log('hic_cleanup_booking_events: Database error: ' . $wpdb->last_error);
    return false;
  }

  hic_log("hic_cleanup_booking_events: Removed $deleted processed booking events older than $days days");

  return $deleted;
}
