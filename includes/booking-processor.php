<?php
/**
 * Common booking processing logic
 */

if (!defined('ABSPATH')) exit;

// Funzione comune per processare i dati di prenotazione (sia webhook che API)
function hic_process_booking_data($data) {
  $sid    = $data['sid'] ?? null;
  $gclid  = null;
  $fbclid = null;

  if ($sid) {
    global $wpdb;
    $table = $wpdb->prefix . 'hic_gclids';
    $row = $wpdb->get_row($wpdb->prepare("SELECT gclid, fbclid FROM $table WHERE sid=%s ORDER BY id DESC LIMIT 1", $sid));
    if ($row) { $gclid = $row->gclid; $fbclid = $row->fbclid; }
  }

  // Invii
  hic_send_to_ga4($data, $gclid, $fbclid);
  hic_send_to_fb($data, $gclid, $fbclid);
  if (hic_is_brevo_enabled()) {
    hic_send_brevo_contact($data, $gclid, $fbclid);
    hic_send_brevo_event($data, $gclid, $fbclid);
  }
  hic_send_admin_email($data, $gclid, $fbclid, $sid);
}