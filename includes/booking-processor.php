<?php
/**
 * Common booking processing logic
 */

if (!defined('ABSPATH')) exit;

// Funzione comune per processare i dati di prenotazione (sia webhook che API)
function hic_process_booking_data($data) {
  // Validate input data
  if (!is_array($data)) {
    hic_log('hic_process_booking_data: dati non validi (non array)');
    return;
  }

  // Sanitize SID input
  $sid = !empty($data['sid']) ? sanitize_text_field($data['sid']) : null;
  $gclid  = null;
  $fbclid = null;

  if ($sid) {
    global $wpdb;
    $table = $wpdb->prefix . 'hic_gclids';
    
    // Check if table exists before querying
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
    if ($table_exists) {
      $row = $wpdb->get_row($wpdb->prepare("SELECT gclid, fbclid FROM $table WHERE sid=%s ORDER BY id DESC LIMIT 1", $sid));
      if ($row) { $gclid = $row->gclid; $fbclid = $row->fbclid; }
    }
  }

  // Validate required fields
  $required_fields = ['email'];
  foreach ($required_fields as $field) {
    if (empty($data[$field])) {
      hic_log("hic_process_booking_data: campo obbligatorio mancante - $field");
      return;
    }
  }

  // Invii
  try {
    hic_send_to_ga4($data, $gclid, $fbclid);
    hic_send_to_fb($data, $gclid, $fbclid);
    if (hic_is_brevo_enabled()) {
      hic_send_brevo_contact($data, $gclid, $fbclid);
      hic_send_brevo_event($data, $gclid, $fbclid);
    }
    hic_send_admin_email($data, $gclid, $fbclid, $sid);
    hic_send_francesco_email($data, $gclid, $fbclid, $sid);
    
    hic_log('Prenotazione processata con successo (SID: ' . ($sid ?? 'N/A') . ')');
  } catch (Exception $e) {
    hic_log('Errore processando prenotazione: ' . $e->getMessage());
  }
}