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
    $tracking = hic_get_tracking_ids_by_sid($sid);
    $gclid = $tracking['gclid'];
    $fbclid = $tracking['fbclid'];
  }

  // Validation for amount if present
  if (isset($data['amount'])) {
    $data['amount'] = hic_normalize_price($data['amount']);
  }

  // Invii with error handling
  $success_count = 0;
  $error_count = 0;
  
  try {
    // Tracking integration based on selected mode
    $tracking_mode = hic_get_tracking_mode();
    
    // GA4 Integration (server-side)
    if (($tracking_mode === 'ga4_only' || $tracking_mode === 'hybrid') && 
        hic_get_measurement_id() && hic_get_api_secret()) {
      if (hic_send_to_ga4($data, $gclid, $fbclid)) {
        $success_count++;
      } else {
        $error_count++;
      }
    } else if ($tracking_mode === 'ga4_only') {
      hic_log('hic_process_booking_data: GA4 credentials missing for ga4_only mode, skipping');
    }
    
    // GTM Integration (client-side via dataLayer)
    if (($tracking_mode === 'gtm_only' || $tracking_mode === 'hybrid') && hic_is_gtm_enabled()) {
      if (hic_send_to_gtm_datalayer($data, $gclid, $fbclid)) {
        $success_count++;
      } else {
        $error_count++;
      }
    } else if ($tracking_mode === 'gtm_only') {
      hic_log('hic_process_booking_data: GTM not enabled for gtm_only mode, skipping');
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
    
    // Brevo Integration - Unified approach to prevent duplicate events
    if (hic_is_brevo_enabled() && hic_get_brevo_api_key()) {
      $brevo_success = hic_send_unified_brevo_events($data, $gclid, $fbclid);
      if ($brevo_success) {
        $success_count++;
      } else {
        $error_count++;
      }
    } else {
      hic_log('hic_process_booking_data: Brevo disabled or credentials missing, skipping');
    }
    
    // Admin email - only attempt if valid email is configured
    $admin_email = hic_get_admin_email();
    if (!empty($admin_email) && hic_is_valid_email($admin_email)) {
      if (hic_send_admin_email($data, $gclid, $fbclid, $sid)) {
        $success_count++;
      } else {
        $error_count++;
      }
    } else {
      hic_log('hic_process_booking_data: Admin email not configured or invalid, skipping');
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