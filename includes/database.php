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