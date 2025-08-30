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
    return false;
  }

  // Validate required fields
  $required_fields = ['email'];
  foreach ($required_fields as $field) {
    if (empty($data[$field])) {
      hic_log("hic_process_booking_data: campo obbligatorio mancante - $field");
      return false;
    }
  }

  // Validate email format
  if (!hic_is_valid_email($data['email'])) {
    hic_log('hic_process_booking_data: email non valida - ' . $data['email']);
    return false;
  }

  // Sanitize SID input
  $sid = !empty($data['sid']) ? sanitize_text_field($data['sid']) : null;
  $gclid  = null;
  $fbclid = null;

  if ($sid) {
    global $wpdb;
    
    // Check if wpdb is available
    if (!$wpdb) {
      hic_log('hic_process_booking_data: wpdb is not available');
      return false;
    }
    
    $table = $wpdb->prefix . 'hic_gclids';
    
    // Check if table exists before querying
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
    if ($table_exists) {
      $row = $wpdb->get_row($wpdb->prepare("SELECT gclid, fbclid FROM $table WHERE sid=%s ORDER BY id DESC LIMIT 1", $sid));
      
      if ($wpdb->last_error) {
        hic_log('hic_process_booking_data: Database error retrieving gclid/fbclid: ' . $wpdb->last_error);
      } else if ($row) { 
        $gclid = $row->gclid; 
        $fbclid = $row->fbclid; 
      }
    } else {
      hic_log('hic_process_booking_data: Table does not exist: ' . $table);
    }
  }

  // Validation for amount if present
  if (isset($data['amount'])) {
    $data['amount'] = hic_normalize_price($data['amount']);
  }

  // Invii with error handling
  $success_count = 0;
  $error_count = 0;
  
  try {
    // GA4 Integration
    if (hic_get_measurement_id() && hic_get_api_secret()) {
      if (hic_send_to_ga4($data, $gclid, $fbclid)) {
        $success_count++;
      } else {
        $error_count++;
      }
    } else {
      hic_log('hic_process_booking_data: GA4 credentials missing, skipping');
    }
    
    // Facebook Integration
    if (hic_get_fb_pixel_id() && hic_get_fb_access_token()) {
      if (hic_send_to_fb($data, $gclid, $fbclid)) {
        $success_count++;
      } else {
        $error_count++;
      }
    } else {
      hic_log('hic_process_booking_data: Facebook credentials missing, skipping');
    }
    
    // Brevo Integration
    if (hic_is_brevo_enabled() && hic_get_brevo_api_key()) {
      if (hic_send_brevo_contact($data, $gclid, $fbclid) && 
          hic_send_brevo_event($data, $gclid, $fbclid)) {
        $success_count++;
      } else {
        $error_count++;
      }
    } else {
      hic_log('hic_process_booking_data: Brevo disabled or credentials missing, skipping');
    }
    
    // Admin email
    if (hic_send_admin_email($data, $gclid, $fbclid, $sid)) {
      $success_count++;
    } else {
      $error_count++;
    }
    
    // Francesco email
    if (hic_send_francesco_email($data, $gclid, $fbclid, $sid)) {
      $success_count++;
    } else {
      $error_count++;
    }
    
    hic_log("Prenotazione processata (SID: " . ($sid ?? 'N/A') . ") - Successi: $success_count, Errori: $error_count");
    
    return $error_count === 0;
    
  } catch (Exception $e) {
    hic_log('Errore critico processando prenotazione: ' . $e->getMessage());
    return false;
  } catch (Throwable $e) {
    hic_log('Errore fatale processando prenotazione: ' . $e->getMessage());
    return false;
  }
}