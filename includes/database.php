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

  if (!empty($_GET['gclid'])) {
    $gclid = sanitize_text_field($_GET['gclid']);
    setcookie('hic_sid', $gclid, time() + 60*60*24*30, '/', '', is_ssl(), true);
    $_COOKIE['hic_sid'] = $gclid;
    $wpdb->insert($table, ['gclid'=>$gclid, 'sid'=>$gclid]);
    hic_log("GCLID salvato → $gclid");
  }

  if (!empty($_GET['fbclid'])) {
    $fbclid = sanitize_text_field($_GET['fbclid']);
    setcookie('hic_sid', $fbclid, time() + 60*60*24*30, '/', '', is_ssl(), true);
    $_COOKIE['hic_sid'] = $fbclid;
    $wpdb->insert($table, ['fbclid'=>$fbclid, 'sid'=>$fbclid]);
    hic_log("FBCLID salvato → $fbclid");
  }
}