<?php declare(strict_types=1);

namespace FpHic;

/**
 * Common booking processing logic
 */

if (!defined('ABSPATH')) exit;

// Funzione comune per processare i dati di prenotazione (sia webhook che API)
/**
 * Process booking data by sending tracking events to configured integrations.
 *
 * Expected keys in the $data array:
 * - "email"   (string) Cliente associato alla prenotazione (obbligatorio).
 * - "sid"     (string|null) Session identifier per recuperare i tracciamenti.
 * - "amount"  (int|float|string|null) Importo della prenotazione.
 * - "status"  (string|null) Stato della prenotazione usato per determinare i rimborsi.
 * - "presence" (string|null) Campo alternativo allo stato proveniente dall'API.
 *
 * @param array{
 *   email: string,
 *   sid?: string|null,
 *   amount?: int|float|string|null,
 *   status?: string|null,
 *   presence?: string|null
 * } $data Dati della prenotazione da processare.
 *
 * @return bool True se la prenotazione è stata processata con successo, false in caso contrario.
 */
function hic_process_booking_data(array $data): bool {

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
  $gbraid  = null;
  $wbraid  = null;

  if ($sid) {
    $tracking = Helpers\hic_get_tracking_ids_by_sid($sid);
    $gclid   = $tracking['gclid'];
    $fbclid  = $tracking['fbclid'];
    $msclkid = $tracking['msclkid'];
    $ttclid  = $tracking['ttclid'];
    $gbraid  = $tracking['gbraid'];
    $wbraid  = $tracking['wbraid'];
  }

  // Validation for amount if present
  if (isset($data['amount'])) {
    $data['amount'] = Helpers\hic_normalize_price($data['amount']);
  }

  $filtered_data = apply_filters('hic_booking_data', $data, [
    'gclid'   => $gclid,
    'fbclid'  => $fbclid,
    'msclkid' => $msclkid,
    'ttclid'  => $ttclid,
    'gbraid'  => $gbraid,
    'wbraid'  => $wbraid,
    'sid'     => $sid,
  ]);

  if (is_array($filtered_data)) {
    $data = $filtered_data;
  } else {
    hic_log('hic_process_booking_data: hic_booking_data filter must return an array');
  }

  $status = strtolower($data['status'] ?? ($data['presence'] ?? ''));
  $is_refund = in_array($status, ['cancelled', 'canceled', 'refunded'], true);

  // Determine if booking should be tracked
  $booking_id = Helpers\hic_extract_reservation_id($data);
  $should_track = apply_filters('hic_should_track_reservation', true, $data);
  $tracking_skipped = false;

  if (!$should_track) {
    $tracking_skipped = true;

    $safe_email = '';
    if (isset($data['email']) && is_scalar($data['email'])) {
      $safe_email = sanitize_email((string) $data['email']);
    }

    hic_log(sprintf(
      'hic_process_booking_data: tracciamento ignorato da hic_should_track_reservation (reservation_id: %s, email: %s)',
      $booking_id ?: 'N/A',
      $safe_email !== '' ? $safe_email : 'N/A'
    ));
  }

  // Normalize customer identifiers
  $data['email'] = sanitize_email($data['email']);

  // Normalize currency with fallback to plugin configuration
  $currency = '';
  if (isset($data['currency']) && is_scalar($data['currency'])) {
    $currency = strtoupper(sanitize_text_field((string) $data['currency']));
  }
  if ($currency === '') {
    $currency = Helpers\hic_get_currency() ?: 'EUR';
  }
  $data['currency'] = $currency;

  $amount_value = isset($data['amount']) ? (float) $data['amount'] : null;

  // Extract guest names from multiple possible fields
  $first_name = $data['first_name']
    ?? $data['guest_first_name']
    ?? $data['guest_firstname']
    ?? $data['firstname']
    ?? $data['customer_first_name']
    ?? $data['customer_firstname']
    ?? '';
  $last_name = $data['last_name']
    ?? $data['guest_last_name']
    ?? $data['guest_lastname']
    ?? $data['lastname']
    ?? $data['customer_last_name']
    ?? $data['customer_lastname']
    ?? '';

  if ((empty($first_name) || empty($last_name)) && !empty($data['guest_name']) && is_string($data['guest_name'])) {
    $parts = preg_split('/\s+/', trim($data['guest_name']), 2);
    if (empty($first_name) && isset($parts[0])) {
      $first_name = $parts[0];
    }
    if (empty($last_name) && isset($parts[1])) {
      $last_name = $parts[1];
    }
  }

  if ((empty($first_name) || empty($last_name)) && !empty($data['name']) && is_string($data['name'])) {
    $parts = preg_split('/\s+/', trim($data['name']), 2);
    if (empty($first_name) && isset($parts[0])) {
      $first_name = $parts[0];
    }
    if (empty($last_name) && isset($parts[1])) {
      $last_name = $parts[1];
    }
  }

  $first_name = $first_name !== '' ? sanitize_text_field((string) $first_name) : '';
  $last_name = $last_name !== '' ? sanitize_text_field((string) $last_name) : '';

  // Normalize phone and infer language where possible
  $raw_phone = '';
  foreach (['phone', 'client_phone', 'whatsapp'] as $phone_field) {
    if (!empty($data[$phone_field]) && is_scalar($data[$phone_field])) {
      $raw_phone = (string) $data[$phone_field];
      break;
    }
  }

  $phone_details = ['phone' => '', 'language' => null];
  if ($raw_phone !== '') {
    $phone_details = Helpers\hic_detect_phone_language($raw_phone);
  }

  $normalized_phone = $phone_details['phone'] ?? '';
  $detected_language = $phone_details['language'] ?? null;

  // Determine booking/customer language
  $language_value = null;
  foreach (['language', 'locale'] as $language_field) {
    if (!empty($data[$language_field]) && is_scalar($data[$language_field])) {
      $language_value = sanitize_text_field((string) $data[$language_field]);
      break;
    }
  }
  if ($language_value === null && !empty($detected_language)) {
    $language_value = sanitize_text_field((string) $detected_language);
  }

  // Collect address-related information
  $address_parts = [];
  foreach (['address', 'address_line1', 'address_line_1', 'street', 'city', 'province', 'state', 'postal_code', 'zip', 'country'] as $address_field) {
    if (!empty($data[$address_field]) && is_scalar($data[$address_field])) {
      $address_parts[] = sanitize_text_field((string) $data[$address_field]);
    }
  }
  $address_parts = array_values(array_filter(array_unique($address_parts)));
  $address_string = !empty($address_parts) ? implode(', ', $address_parts) : null;

  // Normalize stay information
  $checkin_value = null;
  foreach (['checkin', 'from_date', 'arrival_date'] as $checkin_field) {
    if (!empty($data[$checkin_field]) && is_scalar($data[$checkin_field])) {
      $checkin_value = sanitize_text_field((string) $data[$checkin_field]);
      break;
    }
  }

  $checkout_value = null;
  foreach (['checkout', 'to_date', 'departure_date'] as $checkout_field) {
    if (!empty($data[$checkout_field]) && is_scalar($data[$checkout_field])) {
      $checkout_value = sanitize_text_field((string) $data[$checkout_field]);
      break;
    }
  }

  $room_value = null;
  foreach (['room', 'room_name', 'accommodation_name'] as $room_field) {
    if (!empty($data[$room_field]) && is_scalar($data[$room_field])) {
      $room_value = sanitize_text_field((string) $data[$room_field]);
      break;
    }
  }

  $guest_count = null;
  foreach (['guests', 'guest_count', 'pax', 'adults'] as $guest_field) {
    if (isset($data[$guest_field]) && is_numeric($data[$guest_field])) {
      $guest_count = (int) $data[$guest_field];
      break;
    }
  }

  $nights_value = null;
  if (!empty($checkin_value) && !empty($checkout_value)) {
    try {
      $checkin_date = new \DateTime($checkin_value);
      $checkout_date = new \DateTime($checkout_value);
      $interval = $checkin_date->diff($checkout_date);
      $nights_value = (int) $interval->format('%a');
    } catch (\Exception $e) {
      $nights_value = null;
    }
  }

  // Assemble booking payload for downstream listeners
  $booking_payload = [
    'booking_id'     => $booking_id ?: '',
    'reservation_id' => $booking_id ?: '',
    'sid'            => $sid,
    'gclid'          => $gclid,
    'fbclid'         => $fbclid,
    'msclkid'        => $msclkid,
    'ttclid'         => $ttclid,
    'gbraid'         => $gbraid,
    'wbraid'         => $wbraid,
    'currency'       => $currency,
    'status'         => $status ?: null,
    'is_refund'      => $is_refund,
  ];

  if ($amount_value !== null) {
    $booking_payload['amount'] = $amount_value;
    $booking_payload['total_amount'] = $amount_value;
    $booking_payload['revenue'] = $amount_value;
  }

  if (!empty($data['email'])) {
    $booking_payload['customer_email'] = $data['email'];
  }

  if (!empty($checkin_value)) {
    $booking_payload['checkin'] = $checkin_value;
  }

  if (!empty($checkout_value)) {
    $booking_payload['checkout'] = $checkout_value;
  }

  if ($nights_value !== null) {
    $booking_payload['nights'] = $nights_value;
  }

  if (!empty($room_value)) {
    $booking_payload['room_name'] = $room_value;
  }

  if ($guest_count !== null) {
    $booking_payload['guests'] = $guest_count;
  }

  if (!empty($language_value)) {
    $booking_payload['language'] = $language_value;
  }

  if (!empty($data['status']) && is_scalar($data['status'])) {
    $booking_payload['raw_status'] = sanitize_text_field((string) $data['status']);
  }

  if (!empty($data['presence']) && is_scalar($data['presence'])) {
    $booking_payload['presence'] = sanitize_text_field((string) $data['presence']);
  }

  // Build customer payload with normalized identifiers
  $customer_payload = [
    'email' => $data['email'],
  ];

  if ($first_name !== '') {
    $customer_payload['first_name'] = $first_name;
  }

  if ($last_name !== '') {
    $customer_payload['last_name'] = $last_name;
  }

  if ($first_name !== '' || $last_name !== '') {
    $customer_payload['full_name'] = trim($first_name . ' ' . $last_name);
  }

  if ($normalized_phone !== '') {
    $customer_payload['phone'] = $normalized_phone;
  }

  if (!empty($language_value)) {
    $customer_payload['language'] = $language_value;
  }

  if ($address_string !== null) {
    $customer_payload['address'] = $address_string;
  }

  foreach (['city', 'province', 'state', 'country', 'country_code', 'postal_code', 'zip'] as $field) {
    if (!empty($data[$field]) && is_scalar($data[$field])) {
      $customer_payload[$field] = sanitize_text_field((string) $data[$field]);
    }
  }

  if ($detected_language !== null && !isset($customer_payload['phone_language'])) {
    $customer_payload['phone_language'] = sanitize_text_field((string) $detected_language);
  }

  $booking_payload = apply_filters('hic_booking_data', $booking_payload, $data);
  $customer_payload = apply_filters('hic_booking_customer_data', $customer_payload, $booking_payload, $data);

  // Invii with error handling
  $success_count = 0;
  $error_count = 0;
  $skipped_count = $tracking_skipped ? 1 : 0;

  try {
    if (!$tracking_skipped) {
      do_action('hic_process_booking', $booking_payload, $customer_payload);

      // Tracking integration based on selected mode
      $tracking_mode = Helpers\hic_get_tracking_mode();

      if ($is_refund) {
        if (!Helpers\hic_refund_tracking_enabled()) {
          hic_log('hic_process_booking_data: refund detected but tracking disabled, skipping refund events');
        } else {
          // GA4 refund event
          if (($tracking_mode === 'ga4_only' || $tracking_mode === 'hybrid') &&
              Helpers\hic_get_measurement_id() && Helpers\hic_get_api_secret()) {
            if (hic_send_ga4_refund($data, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid, $sid)) {
              $success_count++;
            } else {
              $error_count++;
            }
          }

          // Facebook refund event
          if (Helpers\hic_get_fb_pixel_id() && Helpers\hic_get_fb_access_token()) {
            if (hic_send_fb_refund($data, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid)) {
              $success_count++;
            } else {
              $error_count++;
            }
          }

          // Brevo refund event
          if (Helpers\hic_is_brevo_enabled() && Helpers\hic_get_brevo_api_key()) {
            $brevo_success = hic_send_brevo_refund_event($data, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid);
            if ($brevo_success) {
              $success_count++;
            } else {
              $error_count++;
            }
          }
        }
      } else {
        // GA4 Integration (server-side)
        if (($tracking_mode === 'ga4_only' || $tracking_mode === 'hybrid') &&
            Helpers\hic_get_measurement_id() && Helpers\hic_get_api_secret()) {
          if (hic_send_to_ga4($data, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid, $sid)) {
            $success_count++;
          } else {
            $error_count++;
          }
        } else if ($tracking_mode === 'ga4_only') {
          hic_log('hic_process_booking_data: GA4 credentials missing for ga4_only mode, skipping');
        }

        // GTM Integration (client-side via dataLayer)
        if (($tracking_mode === 'gtm_only' || $tracking_mode === 'hybrid') && Helpers\hic_is_gtm_enabled()) {
          if (hic_send_to_gtm_datalayer($data, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid, $sid)) {
            $success_count++;
          } else {
            $error_count++;
          }
        } else if ($tracking_mode === 'gtm_only') {
          hic_log('hic_process_booking_data: GTM not enabled for gtm_only mode, skipping');
        }

        // Facebook Integration
        if (Helpers\hic_get_fb_pixel_id() && Helpers\hic_get_fb_access_token()) {
          if (hic_send_to_fb($data, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid)) {
            $success_count++;
          } else {
            $error_count++;
          }
        } else {
          hic_log('hic_process_booking_data: Facebook credentials missing, skipping');
        }

        // Brevo Integration - Unified approach to prevent duplicate events
        if (Helpers\hic_is_brevo_enabled() && Helpers\hic_get_brevo_api_key()) {
          $brevo_email = '';
          if (isset($data['email']) && is_scalar($data['email'])) {
            $brevo_email = \sanitize_email((string) $data['email']);
          }

          if (!Helpers\hic_is_valid_email($brevo_email)) {
            hic_log('hic_process_booking_data: Brevo dispatch skipped - missing or invalid email');
            $skipped_count++;
          } else {
            $data['email'] = $brevo_email;
            $brevo_result = hic_send_unified_brevo_events($data, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid);

            if ($brevo_result === true) {
              $success_count++;
            } elseif ($brevo_result === 'skipped') {
              $skipped_count++;
            } else {
              $error_count++;
            }
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
    }

    hic_log("Prenotazione processata (SID: " . ($sid ?? 'N/A') . ") - Successi: $success_count, Errori: $error_count, Skippate: $skipped_count");

    $processing_success = $error_count === 0;

    if ($tracking_skipped) {
      return true;
    }

    if ($processing_success && !$is_refund && !empty($booking_payload['booking_id'])) {
      do_action('hic_booking_processed', $booking_payload['booking_id'], $gclid, $customer_payload);
    }

    return $processing_success;

  } catch (\Exception $e) {
    hic_log('Errore critico processando prenotazione: ' . $e->getMessage());
    return false;
  } catch (\Throwable $e) {
    hic_log('Errore fatale processando prenotazione: ' . $e->getMessage());
    return false;
  }
}
