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

/**
 * Retrieve the wpdb instance when available and supporting the required methods.
 *
 * @param string[] $required_methods
 * @return object|null
 */
function hic_get_wpdb(array $required_methods = [])
{
    if (function_exists('FpHic\\Helpers\\hic_get_wpdb_instance')) {
        $wpdb = \FpHic\Helpers\hic_get_wpdb_instance($required_methods);
        if ($wpdb) {
            return $wpdb;
        }
    }

    global $wpdb;

    if (!isset($wpdb) || !is_object($wpdb)) {
        return null;
    }

    foreach ($required_methods as $method) {
        if (!method_exists($wpdb, $method)) {
            return null;
        }
    }

    return $wpdb;
}

/* ============ DB: tabella sid↔gclid/fbclid ============ */
function hic_create_database_table(){
  $wpdb = hic_get_wpdb(['get_charset_collate', 'get_var', 'prepare']);

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
    gbraid       VARCHAR(255),
    wbraid       VARCHAR(255),
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
    KEY gbraid (gbraid(100)),
    KEY wbraid (wbraid(100)),
    KEY sid (sid(100)),
    KEY utm_source (utm_source(100)),
    KEY utm_medium (utm_medium(100)),
    KEY utm_campaign (utm_campaign(100)),
    KEY utm_content (utm_content(100)),
    KEY utm_term (utm_term(100))
  ) $charset;";

  $upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
  if (!function_exists('dbDelta')) {
    if (is_readable($upgrade_file)) {
      require_once $upgrade_file;
    } else {
      hic_log('hic_create_database_table: dbDelta unavailable');
      return false;
    }
  }

  if (!function_exists('dbDelta')) {
    hic_log('hic_create_database_table: dbDelta function missing');
    return false;
  }

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
  $wpdb = hic_get_wpdb(['get_charset_collate', 'get_var', 'prepare']);

  if (!$wpdb) {
    hic_log('hic_create_realtime_sync_table: wpdb is not available');
    return false;
  }
  
  $table = $wpdb->prefix . 'hic_realtime_sync';
  $charset = $wpdb->get_charset_collate();
  
  $sql = "CREATE TABLE IF NOT EXISTS $table (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    reservation_id VARCHAR(255) NOT NULL,
    sync_status ENUM('new', 'notified', 'failed', 'permanent_failure') DEFAULT 'new',
    first_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_attempt TIMESTAMP NULL,
    attempt_count INT DEFAULT 0,
    last_error TEXT NULL,
    brevo_event_sent TINYINT(1) DEFAULT 0,
    payload_json LONGTEXT NULL,
    UNIQUE KEY unique_reservation (reservation_id),
    KEY status_idx (sync_status),
    KEY first_seen_idx (first_seen)
  ) $charset;";
  
  $upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
  if (!function_exists('dbDelta')) {
    if (is_readable($upgrade_file)) {
      require_once $upgrade_file;
    } else {
      hic_log('hic_create_realtime_sync_table: dbDelta unavailable');
      return false;
    }
  }

  if (!function_exists('dbDelta')) {
    hic_log('hic_create_realtime_sync_table: dbDelta function missing');
    return false;
  }

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
  $wpdb = hic_get_wpdb(['get_charset_collate', 'get_var', 'prepare']);

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
  
  $upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
  if (!function_exists('dbDelta')) {
    if (is_readable($upgrade_file)) {
      require_once $upgrade_file;
    } else {
      hic_log('hic_create_booking_events_table: dbDelta unavailable');
      return false;
    }
  }

  if (!function_exists('dbDelta')) {
    hic_log('hic_create_booking_events_table: dbDelta function missing');
    return false;
  }

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
  $wpdb = hic_get_wpdb(['get_charset_collate', 'get_var', 'prepare']);

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

  $upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
  if (!function_exists('dbDelta')) {
    if (is_readable($upgrade_file)) {
      require_once $upgrade_file;
    } else {
      hic_log('hic_create_database_table: dbDelta unavailable');
      return false;
    }
  }

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
  return hic_create_booking_metrics_table();
}

/**
 * Create or update the booking metrics table used by the real-time dashboard.
 */
function hic_create_booking_metrics_table() {
  $wpdb = hic_get_wpdb(['get_charset_collate', 'get_var', 'prepare', 'query']);

  if (!$wpdb) {
    hic_log('hic_create_booking_metrics_table: wpdb is not available');
    return false;
  }

  $table = $wpdb->prefix . 'hic_booking_metrics';
  $charset = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE IF NOT EXISTS $table (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    reservation_id VARCHAR(255) NOT NULL,
    sid VARCHAR(255) NULL,
    channel VARCHAR(100) NOT NULL DEFAULT 'Direct',
    utm_source VARCHAR(255) NULL,
    utm_medium VARCHAR(255) NULL,
    utm_campaign VARCHAR(255) NULL,
    utm_content VARCHAR(255) NULL,
    utm_term VARCHAR(255) NULL,
    amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    currency VARCHAR(10) NOT NULL DEFAULT 'EUR',
    is_refund TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(50) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY unique_reservation (reservation_id),
    KEY channel_idx (channel),
    KEY created_at_idx (created_at),
    KEY utm_source_idx (utm_source(191))
  ) $charset;";

  $upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
  if (!function_exists('dbDelta')) {
    if (is_readable($upgrade_file)) {
      require_once $upgrade_file;
    } else {
      hic_log('hic_create_booking_metrics_table: dbDelta unavailable');
      return false;
    }
  }

  $result = dbDelta($sql);

  if ($result === false) {
    hic_log('hic_create_booking_metrics_table: Failed to create table ' . $table);
    return false;
  }

  $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
  if (!$table_exists) {
    hic_log('hic_create_booking_metrics_table: Table creation verification failed for ' . $table);
    return false;
  }

  hic_log('DB ready: ' . $table . ' (booking metrics)');
  return true;
}

/**
 * Ensure UTM columns exist in the gclids table
 * This function checks and adds missing UTM columns dynamically
 */
function hic_ensure_utm_columns_exist() {
  $wpdb = hic_get_wpdb(['get_var', 'prepare', 'get_results', 'query']);

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
  $wpdb = hic_get_wpdb(['get_results', 'prepare', 'query']);

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

  // Migration to version 1.6 - add gbraid and wbraid columns
  if (version_compare($installed_version, '1.6', '<')) {
    $table = $wpdb->prefix . 'hic_gclids';
    $columns = ['gbraid', 'wbraid'];
    foreach ($columns as $col) {
      $exists = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", $col));
      if (empty($exists)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN $col VARCHAR(255)");
      }
    }
    update_option('hic_db_version', '1.6');
    hic_clear_option_cache('hic_db_version');
    $installed_version = '1.6';
  }

  if (version_compare($installed_version, '1.7', '<')) {
    $reindex_result = hic_reindex_realtime_sync_reservations();
    if (is_array($reindex_result)) {
      hic_log(sprintf(
        'hic_maybe_upgrade_db: Reindexed real-time sync table (normalized: %d, deleted: %d)',
        $reindex_result['normalized'] ?? 0,
        $reindex_result['deleted'] ?? 0
      ));
    }
    update_option('hic_db_version', '1.7');
    hic_clear_option_cache('hic_db_version');
    $installed_version = '1.7';
  }

  if (version_compare($installed_version, '1.8', '<')) {
    hic_create_booking_metrics_table();
    update_option('hic_db_version', '1.8');
    hic_clear_option_cache('hic_db_version');
    $installed_version = '1.8';
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
  $wpdb = hic_get_wpdb(['get_var', 'prepare', 'update', 'insert']);

  if (!$wpdb) {
    return new \WP_Error('no_db', 'wpdb is not available');
  }

  // Only allow specific tracking types
  $allowed_types = ['gclid', 'fbclid', 'msclkid', 'ttclid', 'gbraid', 'wbraid'];
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
  $wpdb = hic_get_wpdb(['get_var', 'prepare', 'insert', 'update']);

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

  $tracking_cookie_setter = static function (string $cookie_name, string $value): void {
    $cookie_args = apply_filters('hic_tracking_cookie_args', [
      'expires'  => time() + 60 * 60 * 24 * 90,
      'path'     => '/',
      'secure'   => is_ssl(),
      'httponly' => false,
      'samesite' => 'Lax',
    ], $cookie_name, $value);

    if (!setcookie($cookie_name, $value, $cookie_args)) {
      hic_log('hic_capture_tracking_params: Failed to set tracking cookie ' . $cookie_name);
      return;
    }

    $_COOKIE[$cookie_name] = $value;
  };

  $tracking_sources = [
    'gclid'  => ['cookie' => null],
    'fbclid' => ['cookie' => null],
    'msclkid' => ['cookie' => null],
    'ttclid' => ['cookie' => null],
    'gbraid' => ['cookie' => 'hic_gbraid'],
    'wbraid' => ['cookie' => 'hic_wbraid'],
  ];

  foreach ($tracking_sources as $type => $config) {
    $value = null;

    if (!empty($_GET[$type])) {
      $value = sanitize_text_field(wp_unslash($_GET[$type]));
    } elseif (!empty($config['cookie']) && isset($_COOKIE[$config['cookie']])) {
      $value = sanitize_text_field(wp_unslash($_COOKIE[$config['cookie']]));
    }

    if ($value === null || $value === '') {
      continue;
    }

    $result = hic_store_tracking_id($type, $value, $existing_sid);
    if (is_wp_error($result)) {
      hic_log('hic_capture_tracking_params: ' . $result->get_error_message());
      return false;
    }

    $refresh_existing_sid();

    if (!empty($config['cookie'])) {
      $tracking_cookie_setter($config['cookie'], $value);
    }
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
  if (!is_scalar($reservation_id)) {
    hic_log('hic_is_reservation_new_for_realtime: Invalid reservation_id type');
    return false;
  }

  $normalized_reservation_id = \FpHic\Helpers\hic_normalize_reservation_id((string) $reservation_id);
  if ($normalized_reservation_id === '') {
    hic_log('hic_is_reservation_new_for_realtime: Invalid reservation_id');
    return false;
  }

  $reservation_id = $normalized_reservation_id;

  $wpdb = hic_get_wpdb(['get_var', 'prepare']);

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
  if (!is_scalar($reservation_id)) {
    hic_log('hic_mark_reservation_new_for_realtime: Invalid reservation_id type');
    return false;
  }

  $normalized_reservation_id = \FpHic\Helpers\hic_normalize_reservation_id((string) $reservation_id);
  if ($normalized_reservation_id === '') {
    hic_log('hic_mark_reservation_new_for_realtime: Invalid reservation_id');
    return false;
  }

  $reservation_id = $normalized_reservation_id;

  $wpdb = hic_get_wpdb(['get_var', 'prepare', 'query']);

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
  if (!is_scalar($reservation_id)) {
    hic_log('hic_mark_reservation_notified_to_brevo: Invalid reservation_id type');
    return false;
  }

  $normalized_reservation_id = \FpHic\Helpers\hic_normalize_reservation_id((string) $reservation_id);
  if ($normalized_reservation_id === '') {
    hic_log('hic_mark_reservation_notified_to_brevo: Invalid reservation_id');
    return false;
  }

  $reservation_id = $normalized_reservation_id;

  $wpdb = hic_get_wpdb(['update']);

  if (!$wpdb) {
    hic_log('hic_mark_reservation_notified_to_brevo: wpdb is not available');
    return false;
  }

  $table = $wpdb->prefix . 'hic_realtime_sync';

  $result = $wpdb->update(
    $table,
    array(
      'sync_status' => 'notified',
      'brevo_event_sent' => 1,
      'last_attempt' => current_time('mysql'),
      'attempt_count' => 0,
      'last_error' => null,
      'payload_json' => null
    ),
    array('reservation_id' => $reservation_id),
    array('%s', '%d', '%s', '%d', '%s', '%s'),
    array('%s')
  );
  
  return $result !== false;
}

/**
 * Mark reservation notification as failed
 */
function hic_mark_reservation_notification_failed($reservation_id, $error_message = null, $payload = null) {
  if (!is_scalar($reservation_id)) {
    hic_log('hic_mark_reservation_notification_failed: Invalid reservation_id type');
    return false;
  }

  $normalized_reservation_id = \FpHic\Helpers\hic_normalize_reservation_id((string) $reservation_id);
  if ($normalized_reservation_id === '') {
    hic_log('hic_mark_reservation_notification_failed: Invalid reservation_id');
    return false;
  }

  $reservation_id = $normalized_reservation_id;

  $wpdb = hic_get_wpdb(['get_row', 'prepare', 'update']);

  if (!$wpdb) {
    hic_log('hic_mark_reservation_notification_failed: wpdb is not available');
    return false;
  }

  $table = $wpdb->prefix . 'hic_realtime_sync';

  // Get current attempt count
  $current = $wpdb->get_row($wpdb->prepare(
    "SELECT attempt_count, payload_json FROM $table WHERE reservation_id = %s",
    $reservation_id
  ));

  $attempt_count = $current ? ($current->attempt_count + 1) : 1;

  $payload_json = null;
  if ($payload !== null) {
    if (is_string($payload)) {
      $payload_json = $payload;
    } else {
      $encoded_payload = wp_json_encode($payload);
      if ($encoded_payload !== false) {
        $payload_json = $encoded_payload;
      } else {
        hic_log('hic_mark_reservation_notification_failed: Failed to encode payload for reservation ' . $reservation_id);
      }
    }
  } elseif ($current && isset($current->payload_json)) {
    $payload_json = $current->payload_json;
  }

  $result = $wpdb->update(
    $table,
    array(
      'sync_status' => 'failed',
      'last_attempt' => current_time('mysql'),
      'attempt_count' => $attempt_count,
      'last_error' => $error_message,
      'payload_json' => $payload_json,
      'brevo_event_sent' => 0
    ),
    array('reservation_id' => $reservation_id),
    array('%s', '%s', '%d', '%s', '%s', '%d'),
    array('%s')
  );

  return $result !== false;
}

/**
 * Mark reservation notification as permanently failed (non-retryable)
 */
function hic_mark_reservation_notification_permanent_failure($reservation_id, $error_message = null) {
  if (!is_scalar($reservation_id)) {
    hic_log('hic_mark_reservation_notification_permanent_failure: Invalid reservation_id type');
    return false;
  }

  $normalized_reservation_id = \FpHic\Helpers\hic_normalize_reservation_id((string) $reservation_id);
  if ($normalized_reservation_id === '') {
    hic_log('hic_mark_reservation_notification_permanent_failure: Invalid reservation_id');
    return false;
  }

  $reservation_id = $normalized_reservation_id;

  $wpdb = hic_get_wpdb(['update']);

  if (!$wpdb) {
    hic_log('hic_mark_reservation_notification_permanent_failure: wpdb is not available');
    return false;
  }

  $table = $wpdb->prefix . 'hic_realtime_sync';
  
  $result = $wpdb->update(
    $table,
    array(
      'sync_status' => 'permanent_failure',
      'last_attempt' => current_time('mysql'),
      'last_error' => $error_message,
      'brevo_event_sent' => 0
    ),
    array('reservation_id' => $reservation_id),
    array('%s', '%s', '%s', '%d'),
    array('%s')
  );
  
  return $result !== false;
}

/**
 * Get failed reservations that need retry
 */
function hic_get_failed_reservations_for_retry($max_attempts = 3, $retry_delay_minutes = 30) {
  $wpdb = hic_get_wpdb(['get_results', 'prepare']);

  if (!$wpdb) {
    hic_log('hic_get_failed_reservations_for_retry: wpdb is not available');
    return array();
  }

  $table = $wpdb->prefix . 'hic_realtime_sync';

  $retry_time = wp_date('Y-m-d H:i:s', strtotime("-{$retry_delay_minutes} minutes", current_time('timestamp')));

  $results = $wpdb->get_results($wpdb->prepare(
    "SELECT reservation_id, attempt_count, last_error, payload_json
     FROM $table
     WHERE sync_status = 'failed'
     AND attempt_count < %d
     AND (last_attempt IS NULL OR last_attempt < %s)
     ORDER BY first_seen ASC
     LIMIT 10",
    $max_attempts,
    $retry_time
  ));

  if (empty($results)) {
    return array();
  }

  $normalized_results = array();

  foreach ($results as $result) {
    if (!isset($result->reservation_id)) {
      continue;
    }

    $normalized_reservation_id = \FpHic\Helpers\hic_normalize_reservation_id((string) $result->reservation_id);
    if ($normalized_reservation_id === '') {
      hic_log('hic_get_failed_reservations_for_retry: Skipping row with invalid reservation_id');
      continue;
    }

    $result->reservation_id = $normalized_reservation_id;
    $normalized_results[] = $result;
  }

  return $normalized_results;
}

/**
 * Normalize and deduplicate stored real-time sync states.
 *
 * @return array{normalized:int,deleted:int}
 */
function hic_reindex_realtime_sync_reservations() {
  $wpdb = hic_get_wpdb(['get_var', 'prepare', 'get_results', 'delete', 'query']);

  if (!$wpdb) {
    hic_log('hic_reindex_realtime_sync_reservations: wpdb is not available');
    return array('normalized' => 0, 'deleted' => 0);
  }

  $table = $wpdb->prefix . 'hic_realtime_sync';
  $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
  if (!$table_exists) {
    return array('normalized' => 0, 'deleted' => 0);
  }

  $rows = $wpdb->get_results(
    "SELECT id, reservation_id, sync_status, first_seen, last_attempt, attempt_count, last_error, brevo_event_sent, payload_json FROM $table",
    ARRAY_A
  );

  if ($rows === null) {
    hic_log('hic_reindex_realtime_sync_reservations: Failed to fetch rows: ' . $wpdb->last_error);
    return array('normalized' => 0, 'deleted' => 0);
  }

  if (empty($rows)) {
    return array('normalized' => 0, 'deleted' => 0);
  }

  $status_priority = array(
    'permanent_failure' => 4,
    'failed' => 3,
    'notified' => 2,
    'new' => 1,
  );

  $groups = array();
  $deleted = 0;

  foreach ($rows as $row) {
    $raw_reservation_id = is_scalar($row['reservation_id']) ? (string) $row['reservation_id'] : '';
    $normalized = \FpHic\Helpers\hic_normalize_reservation_id($raw_reservation_id);

    if ($normalized === '') {
      $delete_result = $wpdb->delete($table, array('id' => (int) $row['id']), array('%d'));
      if ($delete_result !== false) {
        $deleted += (int) $delete_result;
      } else {
        hic_log('hic_reindex_realtime_sync_reservations: Failed to delete row with empty reservation_id (ID ' . $row['id'] . '): ' . $wpdb->last_error);
      }
      continue;
    }

    $row['normalized'] = $normalized;
    if (!isset($groups[$normalized])) {
      $groups[$normalized] = array();
    }
    $groups[$normalized][] = $row;
  }

  $normalized_count = 0;

  foreach ($groups as $normalized => $group) {
    if (empty($group)) {
      continue;
    }

    $best_index = 0;
    $group_count = count($group);

    for ($i = 1; $i < $group_count; $i++) {
      $candidate = $group[$i];
      $current_best = $group[$best_index];

      $candidate_priority = $status_priority[$candidate['sync_status']] ?? 0;
      $best_priority = $status_priority[$current_best['sync_status']] ?? 0;

      if ($candidate_priority > $best_priority) {
        $best_index = $i;
        continue;
      }

      if ($candidate_priority === $best_priority) {
        $candidate_attempts = isset($candidate['attempt_count']) ? (int) $candidate['attempt_count'] : 0;
        $best_attempts = isset($current_best['attempt_count']) ? (int) $current_best['attempt_count'] : 0;

        if ($candidate_attempts > $best_attempts) {
          $best_index = $i;
          continue;
        }

        if ($candidate_attempts === $best_attempts) {
          $candidate_last_attempt = $candidate['last_attempt'];
          $best_last_attempt = $current_best['last_attempt'];

          $candidate_has_attempt = is_string($candidate_last_attempt) && $candidate_last_attempt !== '';
          $best_has_attempt = is_string($best_last_attempt) && $best_last_attempt !== '';

          if ($candidate_has_attempt && (!$best_has_attempt || $candidate_last_attempt > $best_last_attempt)) {
            $best_index = $i;
            continue;
          }

          if ($candidate_last_attempt === $best_last_attempt) {
            if ((int) $candidate['id'] < (int) $current_best['id']) {
              $best_index = $i;
            }
          }
        }
      }
    }

    $best = $group[$best_index];

    $first_seen = is_string($best['first_seen']) && $best['first_seen'] !== '' ? $best['first_seen'] : null;
    $last_attempt = is_string($best['last_attempt']) && $best['last_attempt'] !== '' ? $best['last_attempt'] : null;
    $attempt_count = isset($best['attempt_count']) ? (int) $best['attempt_count'] : 0;
    $sync_status = is_string($best['sync_status']) && $best['sync_status'] !== '' ? $best['sync_status'] : 'new';
    $last_error = isset($best['last_error']) ? $best['last_error'] : null;
    $payload_json = isset($best['payload_json']) ? $best['payload_json'] : null;
    $brevo_event_sent = isset($best['brevo_event_sent']) ? (int) $best['brevo_event_sent'] : 0;

    foreach ($group as $row) {
      $attempt_count = max($attempt_count, isset($row['attempt_count']) ? (int) $row['attempt_count'] : 0);

      if (isset($row['first_seen']) && is_string($row['first_seen']) && $row['first_seen'] !== '') {
        if ($first_seen === null || $row['first_seen'] < $first_seen) {
          $first_seen = $row['first_seen'];
        }
      }

      if (isset($row['last_attempt']) && is_string($row['last_attempt']) && $row['last_attempt'] !== '') {
        if ($last_attempt === null || $row['last_attempt'] > $last_attempt) {
          $last_attempt = $row['last_attempt'];
          $last_error = $row['last_error'];
          $payload_json = $row['payload_json'];
        }
      }

      if (isset($row['brevo_event_sent']) && (int) $row['brevo_event_sent'] === 1) {
        $brevo_event_sent = 1;
      }

      $row_priority = $status_priority[$row['sync_status']] ?? 0;
      $current_priority = $status_priority[$sync_status] ?? 0;
      if ($row_priority > $current_priority) {
        $sync_status = $row['sync_status'];
      }
    }

    $ids_to_delete = array();
    foreach ($group as $row) {
      if ((int) $row['id'] !== (int) $best['id']) {
        $ids_to_delete[] = (int) $row['id'];
      }
    }

    if (!empty($ids_to_delete)) {
      $placeholders = implode(', ', array_fill(0, count($ids_to_delete), '%d'));
      $delete_sql = "DELETE FROM $table WHERE id IN ($placeholders)";
      $prepared_delete = $wpdb->prepare($delete_sql, $ids_to_delete);
      if ($prepared_delete !== false) {
        $delete_result = $wpdb->query($prepared_delete);
        if ($delete_result !== false) {
          $deleted += (int) $delete_result;
        } else {
          hic_log('hic_reindex_realtime_sync_reservations: Failed to delete duplicate rows for ' . $normalized . ': ' . $wpdb->last_error);
        }
      }
    }

    $set_clauses = array(
      'reservation_id = %s',
      'sync_status = %s',
      'attempt_count = %d',
      'brevo_event_sent = %d',
    );
    $params = array(
      $normalized,
      $sync_status,
      $attempt_count,
      $brevo_event_sent > 0 ? 1 : 0,
    );

    if ($first_seen !== null && $first_seen !== '') {
      $set_clauses[] = 'first_seen = %s';
      $params[] = $first_seen;
    }

    if ($last_attempt !== null && $last_attempt !== '') {
      $set_clauses[] = 'last_attempt = %s';
      $params[] = $last_attempt;
    } else {
      $set_clauses[] = 'last_attempt = NULL';
    }

    if ($last_error !== null && $last_error !== '') {
      $set_clauses[] = 'last_error = %s';
      $params[] = $last_error;
    } else {
      $set_clauses[] = 'last_error = NULL';
    }

    if ($payload_json !== null && $payload_json !== '') {
      $set_clauses[] = 'payload_json = %s';
      $params[] = $payload_json;
    } else {
      $set_clauses[] = 'payload_json = NULL';
    }

    $params[] = (int) $best['id'];
    $update_sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $set_clauses) . ' WHERE id = %d';
    $prepared_update = $wpdb->prepare($update_sql, $params);
    if ($prepared_update === false) {
      hic_log('hic_reindex_realtime_sync_reservations: Failed to prepare update for ' . $normalized);
      continue;
    }

    $update_result = $wpdb->query($prepared_update);
    if ($update_result === false) {
      hic_log('hic_reindex_realtime_sync_reservations: Failed to update row ' . $best['id'] . ': ' . $wpdb->last_error);
    } else {
      $normalized_count += (int) max(0, $update_result);
    }
  }

  if ($normalized_count > 0 || $deleted > 0) {
    hic_log(sprintf('hic_reindex_realtime_sync_reservations: normalized %d rows, deleted %d duplicates', $normalized_count, $deleted));
  }

  return array('normalized' => $normalized_count, 'deleted' => $deleted);
}

/* ============ Cleanup old GCLIDs ============ */
function hic_cleanup_old_gclids($days = null) {
  if ($days === null) {
    $days = HIC_RETENTION_GCLID_DAYS;
  }

  if (function_exists('apply_filters')) {
    $days = (int) apply_filters('hic_retention_gclid_days', $days);
  }

  if ($days <= 0) {
    return 0;
  }

  $wpdb = hic_get_wpdb(['get_var', 'prepare', 'query']);

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
function hic_cleanup_booking_events($days = null) {
  if ($days === null) {
    $days = HIC_RETENTION_BOOKING_EVENT_DAYS;
  }

  if (function_exists('apply_filters')) {
    $days = (int) apply_filters('hic_retention_booking_event_days', $days);
  }

  if ($days <= 0) {
    return 0;
  }

  $wpdb = hic_get_wpdb(['get_var', 'prepare', 'query']);

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

/* ============ Cleanup realtime sync queue data ============ */
function hic_cleanup_realtime_sync($days = null) {
  if ($days === null) {
    $days = HIC_RETENTION_REALTIME_SYNC_DAYS;
  }

  if (function_exists('apply_filters')) {
    $days = (int) apply_filters('hic_retention_realtime_sync_days', $days);
  }

  if ($days <= 0) {
    return 0;
  }

  $wpdb = hic_get_wpdb(['get_var', 'prepare', 'query']);

  if (!$wpdb) {
    hic_log('hic_cleanup_realtime_sync: wpdb is not available');
    return false;
  }

  $table = $wpdb->prefix . 'hic_realtime_sync';
  $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
  if ($exists !== $table) {
    $created = hic_create_realtime_sync_table();
    if ($created === false) {
      hic_log('hic_cleanup_realtime_sync: Failed to ensure table exists: ' . $table);
    }
    return 0;
  }

  $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

  $allowed_statuses = ['new', 'notified', 'failed', 'permanent_failure'];
  $target_statuses = ['notified', 'failed', 'permanent_failure'];

  if (function_exists('apply_filters')) {
    $filtered_statuses = apply_filters('hic_retention_realtime_sync_statuses', $target_statuses);
    if (is_array($filtered_statuses)) {
      $target_statuses = array_values(array_intersect($allowed_statuses, array_map('strval', $filtered_statuses)));
    }
  }

  $params = [$cutoff];
  $condition = 'first_seen < %s';

  if (!empty($target_statuses)) {
    $placeholders = implode(', ', array_fill(0, count($target_statuses), '%s'));
    $condition .= ' AND sync_status IN (' . $placeholders . ')';
    $params = array_merge($params, $target_statuses);
  }

  $prepared = $wpdb->prepare("DELETE FROM $table WHERE $condition", $params);

  if ($prepared === false) {
    hic_log('hic_cleanup_realtime_sync: Failed to prepare deletion query for ' . $table);
    return false;
  }

  $deleted = $wpdb->query($prepared);

  if ($deleted === false) {
    hic_log('hic_cleanup_realtime_sync: Database error: ' . $wpdb->last_error);
    return false;
  }

  hic_log("hic_cleanup_realtime_sync: Removed $deleted realtime sync records older than $days days");

  return $deleted;
}
