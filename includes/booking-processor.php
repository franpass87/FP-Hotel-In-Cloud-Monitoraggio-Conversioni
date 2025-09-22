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
 * @return array<string,mixed> Risultato strutturato con stato complessivo e dettagli delle integrazioni.
 */
function hic_process_booking_data(array $data) {
  $result = hic_create_integration_result([
    'context' => [
      'source' => 'booking_processor',
    ],
  ]);

  $return_failure = static function (array $base, string $message) {
    $failure = $base;
    $failure['status'] = 'failed';
    $failure['should_mark_processed'] = false;
    if ($message !== '') {
      $failure['messages'][] = $message;
    }

    $failure['messages'] = array_values(array_unique($failure['messages']));

    return $failure;
  };

  $normalized_email = null;

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
    $result['context']['sid'] = $sid;
  }

  // Validation for amount if present
  if (isset($data['amount'])) {
    $data['amount'] = Helpers\hic_normalize_price($data['amount']);
  }

  $tracking_context = [
    'gclid'   => $gclid,
    'fbclid'  => $fbclid,
    'msclkid' => $msclkid,
    'ttclid'  => $ttclid,
    'gbraid'  => $gbraid,
    'wbraid'  => $wbraid,
    'sid'     => $sid,
  ];

  $filtered_data = apply_filters('hic_booking_data', $data, $tracking_context);

  if (is_array($filtered_data)) {
    $data = $filtered_data;
  } else {
    hic_log('hic_process_booking_data: hic_booking_data filter must return an array');
  }

  $status = strtolower($data['status'] ?? ($data['presence'] ?? ''));
  $is_refund = in_array($status, ['cancelled', 'canceled', 'refunded'], true);

  // Determine if booking should be tracked
  $booking_id = Helpers\hic_extract_reservation_id($data);
  if (!empty($booking_id)) {
    $safe_booking_id = is_scalar($booking_id) ? sanitize_text_field((string) $booking_id) : '';
    if ($safe_booking_id !== '') {
      $result['reservation_id'] = $safe_booking_id;
      $result['uid'] = $safe_booking_id;
      $result['context']['reservation_id'] = $safe_booking_id;
    }
  }
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

  $result['context']['tracking_skipped'] = $tracking_skipped ? '1' : '0';

  // Normalize customer identifiers
  if (array_key_exists('email', $data)) {
    $email_value = $data['email'];

    if (is_scalar($email_value)) {
      $email_candidate = trim((string) $email_value);

      if ($email_candidate === '') {
        unset($data['email']);
      } else {
        $sanitized_email = sanitize_email($email_candidate);

        if ($sanitized_email === '' || !Helpers\hic_is_valid_email($sanitized_email)) {
          hic_log('hic_process_booking_data: email non valida - ' . $email_candidate);
          return $return_failure($result, 'invalid_email');
        }

        $normalized_email = $sanitized_email;
        $data['email'] = $sanitized_email;
        $result['context']['email'] = $sanitized_email;
      }
    } elseif ($email_value === null || $email_value === '') {
      unset($data['email']);
    } else {
      hic_log('hic_process_booking_data: email non valida - tipo non supportato');
      return $return_failure($result, 'invalid_email');
    }
  }

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
  $customer_payload = [];

  if (!empty($data['email'])) {
    $customer_payload['email'] = $data['email'];
  }

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

  $filtered_booking_payload = apply_filters('hic_booking_payload', $booking_payload, $tracking_context, $data);

  if (is_array($filtered_booking_payload)) {
    $booking_payload = $filtered_booking_payload;
  } else {
    hic_log('hic_process_booking_data: hic_booking_payload filter must return an array');
  }
  $customer_payload = apply_filters('hic_booking_customer_data', $customer_payload, $booking_payload, $data);

  // Invii with error handling
  $tracking_mode = Helpers\hic_get_tracking_mode();
  $result['context']['tracking_mode'] = $tracking_mode;
  $result['context']['is_refund'] = $is_refund ? '1' : '0';

  $has_ga4_credentials = Helpers\hic_get_measurement_id() && Helpers\hic_get_api_secret();
  $has_fb_credentials = Helpers\hic_get_fb_pixel_id() && Helpers\hic_get_fb_access_token();
  $brevo_enabled = Helpers\hic_is_brevo_enabled() && Helpers\hic_get_brevo_api_key();

  $record_result = static function (string $integration, string $status, string $note = '') use (&$result): void {
    Helpers\hic_append_integration_result($result, $integration, $status, $note);
  };

  try {
    if (!$tracking_skipped) {
      do_action('hic_process_booking', $booking_payload, $customer_payload);

      if ($is_refund) {
        if (!Helpers\hic_refund_tracking_enabled()) {
          hic_log('hic_process_booking_data: refund detected but tracking disabled, skipping refund events');

          if (in_array($tracking_mode, ['ga4_only', 'hybrid'], true)) {
            $record_result('GA4', 'skipped', 'refund tracking disabled');
          }

          $record_result('Meta Pixel', 'skipped', 'refund tracking disabled');
          $record_result('Brevo', 'skipped', 'refund tracking disabled');
        } else {
          if (in_array($tracking_mode, ['ga4_only', 'hybrid'], true)) {
            if ($has_ga4_credentials) {
              $ga4_success = hic_send_ga4_refund($data, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid);
              $record_result('GA4', $ga4_success ? 'success' : 'failed', 'refund');
            } else {
              $record_result('GA4', 'skipped', 'missing credentials');
            }
          }

          if ($has_fb_credentials) {
            $fb_success = hic_send_fb_refund($data, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid);
            $record_result('Meta Pixel', $fb_success ? 'success' : 'failed', 'refund');
          } else {
            $record_result('Meta Pixel', 'skipped', 'missing credentials');
          }

          if ($brevo_enabled) {
            $brevo_success = hic_send_brevo_refund_event($data, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid);
            $record_result('Brevo', $brevo_success ? 'success' : 'failed', 'refund');
          } else {
            $record_result('Brevo', 'skipped', 'not enabled');
          }
        }
      } else {
        if (in_array($tracking_mode, ['ga4_only', 'hybrid'], true)) {
          if ($has_ga4_credentials) {
            $ga4_success = hic_send_to_ga4($data, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid, $sid);
            $record_result('GA4', $ga4_success ? 'success' : 'failed');
          } else {
            if ($tracking_mode === 'ga4_only') {
              hic_log('hic_process_booking_data: GA4 credentials missing for ga4_only mode, skipping');
            }
            $record_result('GA4', 'skipped', 'missing credentials');
          }
        }

        if (in_array($tracking_mode, ['gtm_only', 'hybrid'], true)) {
          if (Helpers\hic_is_gtm_enabled()) {
            $gtm_success = hic_send_to_gtm_datalayer($data, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid, $sid);
            $record_result('GTM', $gtm_success ? 'success' : 'failed');
          } else {
            if ($tracking_mode === 'gtm_only') {
              hic_log('hic_process_booking_data: GTM not enabled for gtm_only mode, skipping');
            }
            $record_result('GTM', 'skipped', 'not enabled');
          }
        }

        if ($has_fb_credentials) {
          $fb_success = hic_send_to_fb($data, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid);
          $record_result('Meta Pixel', $fb_success ? 'success' : 'failed');
        } else {
          hic_log('hic_process_booking_data: Facebook credentials missing, skipping');
          $record_result('Meta Pixel', 'skipped', 'missing credentials');
        }

        if ($brevo_enabled) {
          if ($normalized_email === null || $normalized_email === '') {
            hic_log('hic_process_booking_data: Brevo contact dispatch skipped - missing email');
            $record_result('Brevo contact', 'skipped', 'missing email');
          } else {
            $data['email'] = $normalized_email;
            $brevo_result = hic_send_unified_brevo_events($data, $gclid, $fbclid, $msclkid, $ttclid, $gbraid, $wbraid);

            if ($brevo_result === true) {
              $record_result('Brevo', 'success');
            } elseif ($brevo_result === 'skipped') {
              $record_result('Brevo', 'skipped', 'deferred');
            } else {
              $record_result('Brevo', 'failed');
            }
          }
        } else {
          hic_log('hic_process_booking_data: Brevo disabled or credentials missing, skipping');
          $record_result('Brevo', 'skipped', 'not enabled');
        }

        $admin_email = Helpers\hic_get_admin_email();
        if (!empty($admin_email) && Helpers\hic_is_valid_email($admin_email)) {
          $email_sid = $sid ?? '';
          if (Helpers\hic_send_admin_email($data, $gclid, $fbclid, $email_sid)) {
            $record_result('Admin email', 'success');
          } else {
            $safe_sid = $sid !== null ? $sid : 'N/A';
            hic_log('hic_process_booking_data: Admin email sending failed, marking as skipped (SID: ' . $safe_sid . ')', HIC_LOG_LEVEL_WARNING);
            $record_result('Admin email', 'skipped', 'send failed');
          }
        } else {
          hic_log('hic_process_booking_data: Admin email not configured or invalid, skipping');
          $record_result('Admin email', 'skipped', 'not configured');
        }
      }
    }

    $result = Helpers\hic_finalize_integration_result($result, $tracking_skipped);

    $summary_parts = array();
    foreach ($result['integrations'] as $integration => $details) {
      $status = isset($details['status']) ? (string) $details['status'] : '';
      $note = isset($details['note']) ? (string) $details['note'] : '';
      $part = $integration . '=' . $status;
      if ($note !== '') {
        $part .= ' (' . $note . ')';
      }
      $summary_parts[] = $part;
    }
    $summary = !empty($summary_parts) ? implode(', ', $summary_parts) : '';
    $result['summary'] = $summary;

    $skipped_messages = array();
    foreach ($result['skipped_integrations'] as $integration => $note) {
      $entry = $integration;
      if ($note !== '') {
        $entry .= ' (' . $note . ')';
      }
      $skipped_messages[] = $entry;
    }

    $status = isset($result['status']) ? (string) $result['status'] : 'success';
    $safe_sid = $sid !== null ? $sid : 'N/A';
    $reference = $result['reservation_id'] ?? ($result['uid'] ?? 'N/A');

    $log_message = 'Prenotazione ' . $reference . ' processata (SID: ' . $safe_sid . ') - Stato: ' . $status;
    if ($summary !== '') {
      $log_message .= ' - Summary: ' . $summary;
    }
    if (!empty($result['failed_integrations'])) {
      $log_message .= ' - Failed integrations: ' . implode(', ', $result['failed_integrations']);
    }
    if (!empty($skipped_messages)) {
      $log_message .= ' - Skipped: ' . implode(', ', $skipped_messages);
    }
    if (!empty($result['messages'])) {
      $log_message .= ' - Messages: ' . implode(', ', array_unique(array_map('strval', $result['messages'])));
    }

    if ($status === 'failed') {
      hic_log($log_message, HIC_LOG_LEVEL_ERROR);
    } elseif ($status === 'partial') {
      hic_log($log_message, HIC_LOG_LEVEL_WARNING);
    } else {
      hic_log($log_message);
    }

    if (!$tracking_skipped && !$is_refund && in_array($status, ['success', 'partial'], true) && !empty($booking_payload['booking_id'])) {
      do_action('hic_booking_processed', $booking_payload['booking_id'], $gclid, $customer_payload);
    }

    return $result;
  } catch (\Exception $e) {
    $message = $e->getMessage();
    hic_log('Errore critico processando prenotazione: ' . $message);
    $safe_message = sanitize_text_field((string) $message);
    return $return_failure($result, 'exception:' . $safe_message);
  } catch (\Throwable $e) {
    $message = $e->getMessage();
    hic_log('Errore fatale processando prenotazione: ' . $message);
    $safe_message = sanitize_text_field((string) $message);
    return $return_failure($result, 'exception:' . $safe_message);
  }
}
