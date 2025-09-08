<?php declare(strict_types=1);
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
  if (!Helpers\hic_is_valid_email($data['email'])) {
    hic_log('hic_process_booking_data: email non valida - ' . $data['email']);
    return false;
  }

  // Sanitize SID input
  $sid = !empty($data['sid']) ? sanitize_text_field($data['sid']) : null;
  $gclid   = null;
  $fbclid  = null;
  $msclkid = null;
  $ttclid  = null;

  if ($sid) {
    $tracking = Helpers\hic_get_tracking_ids_by_sid($sid);
    $gclid   = $tracking['gclid'];
    $fbclid  = $tracking['fbclid'];
    $msclkid = $tracking['msclkid'];
    $ttclid  = $tracking['ttclid'];
  }

  // Validation for amount if present
  if (isset($data['amount'])) {
    $data['amount'] = Helpers\hic_normalize_price($data['amount']);
  }

  $status = strtolower($data['status'] ?? ($data['presence'] ?? ''));
  $is_refund = in_array($status, ['cancelled', 'canceled', 'refunded'], true);

  // Allow developers to conditionally skip tracking
  if (!apply_filters('hic_should_track_reservation', true, $data)) {
    return false;
  }

  // Invii with error handling
  $success_count = 0;
  $error_count = 0;

  try {
    // Tracking integration based on selected mode
    $tracking_mode = Helpers\hic_get_tracking_mode();

    if ($is_refund) {
      if (!Helpers\hic_refund_tracking_enabled()) {
        hic_log('hic_process_booking_data: refund detected but tracking disabled');
        return false;
      }

      // GA4 refund event
      if (($tracking_mode === 'ga4_only' || $tracking_mode === 'hybrid') &&
          Helpers\hic_get_measurement_id() && Helpers\hic_get_api_secret()) {
        if (hic_send_ga4_refund($data, $gclid, $fbclid, $msclkid, $ttclid, $sid)) {
          $success_count++;
        } else {
          $error_count++;
        }
      }

      // Facebook refund event
      if (Helpers\hic_get_fb_pixel_id() && Helpers\hic_get_fb_access_token()) {
        if (hic_send_fb_refund($data, $gclid, $fbclid, $msclkid, $ttclid)) {
          $success_count++;
        } else {
          $error_count++;
        }
      }

      // Brevo refund event
      if (Helpers\hic_is_brevo_enabled() && Helpers\hic_get_brevo_api_key()) {
        $brevo_success = hic_send_brevo_refund_event($data, $gclid, $fbclid, $msclkid, $ttclid);
        if ($brevo_success) {
          $success_count++;
        } else {
          $error_count++;
        }
      }
    } else {
      // GA4 Integration (server-side)
      if (($tracking_mode === 'ga4_only' || $tracking_mode === 'hybrid') &&
          Helpers\hic_get_measurement_id() && Helpers\hic_get_api_secret()) {
        if (hic_send_to_ga4($data, $gclid, $fbclid, $msclkid, $ttclid, $sid)) {
          $success_count++;
        } else {
          $error_count++;
        }
      } else if ($tracking_mode === 'ga4_only') {
        hic_log('hic_process_booking_data: GA4 credentials missing for ga4_only mode, skipping');
      }

      // GTM Integration (client-side via dataLayer)
      if (($tracking_mode === 'gtm_only' || $tracking_mode === 'hybrid') && Helpers\hic_is_gtm_enabled()) {
        if (hic_send_to_gtm_datalayer($data, $gclid, $fbclid, $sid)) {
          $success_count++;
        } else {
          $error_count++;
        }
      } else if ($tracking_mode === 'gtm_only') {
        hic_log('hic_process_booking_data: GTM not enabled for gtm_only mode, skipping');
      }

      // Facebook Integration
      if (Helpers\hic_get_fb_pixel_id() && Helpers\hic_get_fb_access_token()) {
        if (hic_send_to_fb($data, $gclid, $fbclid, $msclkid, $ttclid)) {
          $success_count++;
        } else {
          $error_count++;
        }
      } else {
        hic_log('hic_process_booking_data: Facebook credentials missing, skipping');
      }

      // Brevo Integration - Unified approach to prevent duplicate events
      if (Helpers\hic_is_brevo_enabled() && Helpers\hic_get_brevo_api_key()) {
        $brevo_success = hic_send_unified_brevo_events($data, $gclid, $fbclid, $msclkid, $ttclid);
        if ($brevo_success) {
          $success_count++;
        } else {
          $error_count++;
        }
      } else {
        hic_log('hic_process_booking_data: Brevo disabled or credentials missing, skipping');
      }

      // Admin email - only attempt if valid email is configured
      $admin_email = Helpers\hic_get_admin_email();
      if (!empty($admin_email) && Helpers\hic_is_valid_email($admin_email)) {
        if (Helpers\hic_send_admin_email($data, $gclid, $fbclid, $sid)) {
          $success_count++;
        } else {
          $error_count++;
        }
      } else {
        hic_log('hic_process_booking_data: Admin email not configured or invalid, skipping');
      }
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