<?php declare(strict_types=1);

namespace FpHic\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

function hic_booking_uid_primary_fields() {
    return ['id', 'reservation_id', 'booking_id', 'transaction_id'];
}

/**
 * Additional aliases that may contain reservation identifiers.
 *
 * @return string[]
 */
function hic_reservation_id_aliases() {
    return [
        'reservationId',
        'bookingId',
        'transactionId',
        'reservation_code',
        'reservationCode',
        'booking_code',
        'bookingCode',
        'code',
        'reservation_number',
        'reservationNumber',
        'reservation_reference',
        'reservationReference',
        'confirmation_code',
        'confirmationCode',
        'confirmationNumber',
        'reference',
        'reference_id',
        'referenceId',
        'reference_code',
        'referenceCode',
    ];
}

/**
 * Build the list of candidate reservation identifier fields, keeping order of preference.
 *
 * @param string[] $preferred_fields
 * @return string[]
 */
function hic_candidate_reservation_id_fields(array $preferred_fields) {
    $candidates = array_merge($preferred_fields, hic_reservation_id_aliases());

    return array_values(array_unique($candidates));
}

function hic_booking_uid($reservation) {
    if (!is_array($reservation)) {
        hic_log('hic_booking_uid: reservation is not an array');
        return '';
    }

    // Try multiple possible ID fields in order of preference
    $id_fields = hic_candidate_reservation_id_fields(hic_booking_uid_primary_fields());

    foreach ($id_fields as $field) {
        if (!array_key_exists($field, $reservation)) {
            continue;
        }

        $value = $reservation[$field];
        if (!is_scalar($value)) {
            continue;
        }

        $candidate = trim((string) $value);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    hic_log(
        'hic_booking_uid: No valid ID found in reservation data (checked fields: ' .
        implode(', ', $id_fields) .
        ')'
    );
    return '';
}

/**
 * Normalize reservation identifiers for consistent storage and comparisons.
 *
 * @param string|int|float|bool $value
 */
function hic_normalize_reservation_id($value): string {
    if (!is_scalar($value)) {
        return '';
    }

    $sanitized = sanitize_text_field((string) $value);
    $sanitized = trim($sanitized);
    if ($sanitized === '') {
        return '';
    }

    return strtoupper($sanitized);
}

/**
 * Collect every normalized reservation identifier present in the payload.
 *
 * @param array<string, mixed> $reservation
 * @return string[]
 */
function hic_collect_reservation_ids(array $reservation): array {
    $id_fields = hic_candidate_reservation_id_fields(hic_booking_uid_primary_fields());
    $unique_ids = [];

    foreach ($id_fields as $field) {
        if (!array_key_exists($field, $reservation)) {
            continue;
        }

        $value = $reservation[$field];
        if (!is_scalar($value)) {
            continue;
        }

        $candidate = hic_normalize_reservation_id((string) $value);
        if ($candidate === '') {
            continue;
        }

        $unique_ids[$candidate] = true;
    }

    return array_keys($unique_ids);
}

/**
 * Locate the first reservation identifier already marked as processed.
 *
 * @param array<string, mixed> $reservation
 */
function hic_find_processed_reservation_alias(array $reservation): ?string {
    $aliases = hic_collect_reservation_ids($reservation);
    if (empty($aliases)) {
        return null;
    }

    $processed = hic_get_processed_reservation_id_set();
    foreach ($aliases as $alias) {
        $normalized = hic_normalize_reservation_id($alias);
        if ($normalized === '') {
            continue;
        }

        if (array_key_exists($normalized, $processed)) {
            return $normalized;
        }
    }

    return null;
}

/**
 * Create the default structure used to aggregate integration results.
 *
 * @param array $overrides Custom values to merge with defaults.
 * @return array<string,mixed>
 */
function hic_create_integration_result(array $overrides = []) {
    $defaults = [
        'status' => 'pending',
        'integrations' => [],
        'successful_integrations' => [],
        'failed_integrations' => [],
        'failed_details' => [],
        'skipped_integrations' => [],
        'messages' => [],
        'should_mark_processed' => false,
    ];

    return array_merge($defaults, $overrides);
}

/**
 * Append an integration outcome to the aggregated result structure.
 */
function hic_append_integration_result(array &$result, string $integration, string $status, string $note = ''): void {
    $normalized_integration = trim($integration);
    if ($normalized_integration === '') {
        $normalized_integration = 'integration';
    }
    $normalized_integration = \sanitize_text_field($normalized_integration);

    $normalized_status = strtolower($status);
    if (!in_array($normalized_status, ['success', 'failed', 'skipped'], true)) {
        $normalized_status = 'failed';
    }

    $normalized_note = '';
    if ($note !== '') {
        $normalized_note = \sanitize_text_field((string) $note);
    }

    $result['integrations'][$normalized_integration] = ['status' => $normalized_status];
    if ($normalized_note !== '') {
        $result['integrations'][$normalized_integration]['note'] = $normalized_note;
    }

    switch ($normalized_status) {
        case 'success':
            $result['successful_integrations'][] = $normalized_integration;
            break;
        case 'failed':
            $result['failed_integrations'][] = $normalized_integration;
            $result['failed_details'][$normalized_integration] = $normalized_note;
            break;
        case 'skipped':
            $result['skipped_integrations'][$normalized_integration] = $normalized_note;
            break;
    }
}

/**
 * Determine final status for integration processing results.
 */
function hic_finalize_integration_result(array $result, bool $tracking_skipped = false) {
    $has_failures = !empty($result['failed_integrations']);
    $has_success = !empty($result['successful_integrations']);

    if ($has_failures && $has_success) {
        $result['status'] = 'partial';
    } elseif ($has_failures) {
        $result['status'] = 'failed';
    } else {
        $result['status'] = 'success';
    }

    if ($tracking_skipped) {
        $result['messages'][] = 'tracking_skipped';
    }

    $should_mark_processed = false;
    if (in_array($result['status'], ['success', 'partial'], true)) {
        $should_mark_processed = true;
    }

    if (!$has_success && !$has_failures && !$tracking_skipped && empty($result['integrations'])) {
        // Nothing attempted but also nothing failed; avoid retry loops.
        $should_mark_processed = true;
    }

    if ($tracking_skipped) {
        $should_mark_processed = true;
    }

    $result['should_mark_processed'] = $should_mark_processed;
    $result['messages'] = array_values(array_unique($result['messages']));

    return $result;
}

/**
 * Queue failed integrations for targeted retry handling.
 *
 * @param string|int $reservation_uid Unique reservation identifier
 * @param array<string,string> $failed_details Integration => note map
 * @param array<string,string> $context Additional context information
 */
function hic_queue_integration_retry($reservation_uid, array $failed_details, array $context = []): void {
    if (!is_scalar($reservation_uid)) {
        return;
    }

    $reservation_uid = trim((string) $reservation_uid);
    if ($reservation_uid === '') {
        return;
    }

    $reservation_uid = \FpHic\Helpers\hic_normalize_reservation_id($reservation_uid);
    if ($reservation_uid === '' || empty($failed_details)) {
        return;
    }

    $normalized_details = [];
    foreach ($failed_details as $integration => $note) {
        if (!is_scalar($integration)) {
            continue;
        }

        $safe_integration = \sanitize_text_field((string) $integration);
        if ($safe_integration === '') {
            continue;
        }

        $safe_note = '';
        if (is_scalar($note) && $note !== '') {
            $safe_note = \sanitize_text_field((string) $note);
        }

        $normalized_details[$safe_integration] = [
            'note' => $safe_note,
            'last_failure' => time(),
        ];
    }

    if (empty($normalized_details)) {
        return;
    }

    $queue = get_option('hic_integration_retry_queue', []);
    if (!is_array($queue)) {
        $queue = [];
    }

    $integrations = [];
    $keys_to_remove = [];

    foreach ($queue as $key => $entry) {
        if (!is_scalar($key) || !is_array($entry)) {
            continue;
        }

        $candidate_key = \FpHic\Helpers\hic_normalize_reservation_id((string) $key);
        if ($candidate_key !== $reservation_uid) {
            continue;
        }

        $keys_to_remove[] = $key;

        if (!isset($entry['integrations']) || !is_array($entry['integrations'])) {
            continue;
        }

        foreach ($entry['integrations'] as $integration => $payload) {
            if (!is_scalar($integration) || !is_array($payload)) {
                continue;
            }

            $safe_integration = \sanitize_text_field((string) $integration);
            if ($safe_integration === '') {
                continue;
            }

            $integrations[$safe_integration] = $payload;
        }
    }

    foreach ($keys_to_remove as $legacy_key) {
        unset($queue[$legacy_key]);
    }

    foreach ($normalized_details as $integration => $payload) {
        $integrations[$integration] = $payload;
    }

    $entry_context = [];
    foreach ($context as $key => $value) {
        if (!is_scalar($key) || !is_scalar($value)) {
            continue;
        }
        $safe_key = \sanitize_key((string) $key);
        $entry_context[$safe_key] = \sanitize_text_field((string) $value);
    }

    $queue[$reservation_uid] = [
        'integrations' => $integrations,
        'context' => $entry_context,
        'last_updated' => time(),
    ];

    if (count($queue) > 200) {
        $queue = array_slice($queue, -200, null, true);
    }

    update_option('hic_integration_retry_queue', $queue, false);
    hic_clear_option_cache('hic_integration_retry_queue');
}

/* ============ Email admin (include bucket) ============ */
function hic_send_admin_email($data, $gclid, $fbclid, $sid){
  // Validate input data
  if (!is_array($data)) {
    hic_log('hic_send_admin_email: data is not an array');
    return false;
  }

  $gbraid = '';
  $wbraid = '';
  $normalized_sid = '';

  if (!empty($sid) && (is_string($sid) || is_numeric($sid))) {
    $normalized_sid = sanitize_text_field((string) $sid);
  }

  if ($normalized_sid !== '') {
    $tracking = \FpHic\Helpers\hic_get_tracking_ids_by_sid($normalized_sid);
    if (empty($gclid) && !empty($tracking['gclid'])) {
      $gclid = $tracking['gclid'];
    }
    if (empty($fbclid) && !empty($tracking['fbclid'])) {
      $fbclid = $tracking['fbclid'];
    }
    if (!empty($tracking['gbraid'])) {
      $gbraid = $tracking['gbraid'];
    }
    if (!empty($tracking['wbraid'])) {
      $wbraid = $tracking['wbraid'];
    }
  }

  if ($gbraid === '' && isset($data['gbraid']) && is_scalar($data['gbraid'])) {
    $gbraid = sanitize_text_field((string) $data['gbraid']);
  }
  if ($wbraid === '' && isset($data['wbraid']) && is_scalar($data['wbraid'])) {
    $wbraid = sanitize_text_field((string) $data['wbraid']);
  }

  $bucket = fp_normalize_bucket($gclid, $fbclid, $gbraid, $wbraid);
  $to = hic_get_admin_email();
  
  // Enhanced email validation with detailed logging
  if (empty($to)) {
    hic_log('hic_send_admin_email: admin email is empty');
    return false;
  }
  
  if (!hic_is_valid_email($to)) {
    hic_log('hic_send_admin_email: invalid admin email format: ' . $to);
    return false;
  }
  
  // Check WordPress email configuration
  if (!function_exists('wp_mail')) {
    hic_log('hic_send_admin_email: wp_mail function not available');
    return false;
  }
  
  // Log which admin email is being used for transparency
  $custom_email = hic_get_option('admin_email', '');
  if (!empty($custom_email)) {
    hic_log('hic_send_admin_email: using custom admin email from settings: ' . $to);
  } else {
    hic_log('hic_send_admin_email: using WordPress default admin email: ' . $to);
  }
  
  // Log WordPress mail configuration for debugging
  $phpmailer_init_triggered = false;
  $phpmailer_error = '';
  
  // Add temporary hook to capture PHPMailer errors
  $phpmailer_hook = function($phpmailer) use (&$phpmailer_init_triggered, &$phpmailer_error) {
    $phpmailer_init_triggered = true;
    if ($phpmailer->ErrorInfo) {
      $phpmailer_error = $phpmailer->ErrorInfo;
    }
  };
  add_action('phpmailer_init', $phpmailer_hook);
  
  $site_name = get_bloginfo('name');
  if (empty($site_name)) {
    $site_name = 'Hotel in Cloud';
  }
  
  $subject = "Nuova prenotazione da " . $site_name;

  $body  = "Hai ricevuto una nuova prenotazione da $site_name:\n\n";
  $body .= "Reservation ID: " . ($data['reservation_id'] ?? ($data['id'] ?? 'n/a')) . "\n";
  $body .= "Importo: " . (isset($data['amount']) ? hic_normalize_price($data['amount']) : '0') . " " . ($data['currency'] ?? 'EUR') . "\n";

  $first = $data['first_name']
      ?? $data['guest_first_name']
      ?? $data['guest_firstname']
      ?? $data['firstname']
      ?? $data['customer_first_name']
      ?? $data['customer_firstname']
      ?? '';
  $last = $data['last_name']
      ?? $data['guest_last_name']
      ?? $data['guest_lastname']
      ?? $data['lastname']
      ?? $data['customer_last_name']
      ?? $data['customer_lastname']
      ?? '';

  if ((empty($first) || empty($last)) && !empty($data['guest_name']) && is_string($data['guest_name'])) {
      $parts = preg_split('/\s+/', trim($data['guest_name']), 2);
      if (empty($first) && isset($parts[0])) {
          $first = $parts[0];
      }
      if (empty($last) && isset($parts[1])) {
          $last = $parts[1];
      }
  }
  if ((empty($first) || empty($last)) && !empty($data['name']) && is_string($data['name'])) {
      $parts = preg_split('/\s+/', trim($data['name']), 2);
      if (empty($first) && isset($parts[0])) {
          $first = $parts[0];
      }
      if (empty($last) && isset($parts[1])) {
          $last = $parts[1];
      }
  }

  $body .= "Nome: " . trim($first . ' ' . $last) . "\n";
  $body .= "Email: " . ($data['email'] ?? 'n/a') . "\n";
  $body .= "Telefono: " . ($data['phone'] ?? 'n/a') . "\n";
  $body .= "Lingua: " . ($data['language'] ?? ($data['lingua'] ?? ($data['lang'] ?? 'n/a'))) . "\n";
  $body .= "Camera: " . ($data['room'] ?? 'n/a') . "\n";
  $body .= "Check-in: " . ($data['checkin'] ?? 'n/a') . "\n";
  $body .= "Check-out: " . ($data['checkout'] ?? 'n/a') . "\n";
  $body .= "SID: " . ($sid ?? 'n/a') . "\n";
  $body .= "GCLID: " . ($gclid ?? 'n/a') . "\n";
  $body .= "FBCLID: " . ($fbclid ?? 'n/a') . "\n";
  $body .= "Bucket: " . $bucket . "\n";

  // Allow customization of admin email subject and body
  $subject = apply_filters('hic_admin_email_subject', $subject, $data);
  $body    = apply_filters('hic_admin_email_body', $body, $data);

  $content_type_filter = function(){ return 'text/plain; charset=UTF-8'; };
  add_filter('wp_mail_content_type', $content_type_filter);
  
  // Enhanced email sending with detailed error reporting
  hic_log('hic_send_admin_email: attempting to send email to ' . $to . ' with subject: ' . $subject);
  
  $sent = wp_mail($to, $subject, $body);
  
  // Remove filters and capture additional debugging info
  remove_filter('wp_mail_content_type', $content_type_filter);
  remove_action('phpmailer_init', $phpmailer_hook);

  // Enhanced logging with detailed error information
  if ($sent) {
    hic_log('Email admin inviata con successo (bucket='.$bucket.') a '.$to);
    if ($phpmailer_init_triggered) {
      hic_log('PHPMailer configuration was initialized correctly');
    }
    return true;
  } else {
    $error_details = 'wp_mail returned false';
    
    // Capture PHPMailer specific errors
    if (!empty($phpmailer_error)) {
      $error_details .= ' - PHPMailer Error: ' . $phpmailer_error;
    }
    
    // Check for common WordPress mail issues
    if (!$phpmailer_init_triggered) {
      $error_details .= ' - PHPMailer was not initialized (possible mail function disabled)';
    }
    
    // Log detailed error information
    hic_log('ERRORE invio email admin a '.$to.' - '.$error_details);
    
    // Log server mail configuration for debugging
    if (function_exists('ini_get')) {
      $smtp_config = ini_get('SMTP');
      $sendmail_path = ini_get('sendmail_path');
      hic_log('Server mail config - SMTP: ' . ($smtp_config ?: 'not set') . ', Sendmail: ' . ($sendmail_path ?: 'not set'));
    }
    
    return false;
  }
}

/* ============ Email Configuration Testing ============ */
function hic_test_email_configuration($recipient_email = null) {
    $result = array(
        'success' => false,
        'message' => '',
        'details' => array()
    );
    
    // Use admin email if no recipient specified
    if (empty($recipient_email)) {
        $recipient_email = hic_get_admin_email();
    }
    
    // Validate recipient email
    if (empty($recipient_email) || !hic_is_valid_email($recipient_email)) {
        $result['message'] = 'Invalid recipient email: ' . $recipient_email;
        return $result;
    }
    
    // Check WordPress mail function availability
    if (!function_exists('wp_mail')) {
        $result['message'] = 'wp_mail function not available';
        return $result;
    }
    
    // Capture PHPMailer configuration
    $phpmailer_info = array();
    $phpmailer_hook = function($phpmailer) use (&$phpmailer_info) {
        $phpmailer_info['mailer'] = $phpmailer->Mailer;
        $phpmailer_info['host'] = $phpmailer->Host;
        $phpmailer_info['port'] = $phpmailer->Port;
        $phpmailer_info['smtp_secure'] = $phpmailer->SMTPSecure;
        $phpmailer_info['smtp_auth'] = $phpmailer->SMTPAuth;
        $phpmailer_info['username'] = $phpmailer->Username;
        $phpmailer_info['from'] = $phpmailer->From;
        $phpmailer_info['from_name'] = $phpmailer->FromName;
    };
    
    add_action('phpmailer_init', $phpmailer_hook);
    
    // Prepare test email
    $subject = 'HIC Email Configuration Test - ' . current_time('mysql');
    $body = "Questo è un test di configurazione email per il plugin Hotel in Cloud.\n\n";
    $body .= "Timestamp: " . current_time('mysql') . "\n";
    $body .= "Destinatario: " . $recipient_email . "\n";
    $body .= "Sito: " . get_bloginfo('name') . "\n";
    $body .= "URL: " . get_bloginfo('url') . "\n\n";
    $body .= "Se ricevi questa email, la configurazione email funziona correttamente.";
    
    // Send test email
    $sent = wp_mail($recipient_email, $subject, $body);
    
    remove_action('phpmailer_init', $phpmailer_hook);
    
    // Collect server mail configuration
    $server_config = array();
    if (function_exists('ini_get')) {
        $server_config['smtp'] = ini_get('SMTP') ?: 'not set';
        $server_config['smtp_port'] = ini_get('smtp_port') ?: 'not set';
        $server_config['sendmail_path'] = ini_get('sendmail_path') ?: 'not set';
        $server_config['mail_function'] = function_exists('mail') ? 'available' : 'not available';
    }
    
    // Build result
    $result['details']['phpmailer'] = $phpmailer_info;
    $result['details']['server_config'] = $server_config;
    $result['details']['wp_admin_email'] = get_option('admin_email');
    $result['details']['hic_admin_email'] = hic_get_option('admin_email', '');
    $result['details']['effective_admin_email'] = hic_get_admin_email();
    
    if ($sent) {
        $result['success'] = true;
        $result['message'] = 'Email di test inviata con successo a ' . $recipient_email;
        hic_log('Email test configuration sent successfully to ' . $recipient_email);
    } else {
        $result['message'] = 'Errore nell\'invio dell\'email di test a ' . $recipient_email;
        hic_log('Email test configuration failed for ' . $recipient_email . ' - Check server mail configuration');
    }
    
    return $result;
}

/* ============ Email Diagnostics and Troubleshooting ============ */
function hic_diagnose_email_issues() {
    $issues = array();
    $suggestions = array();
    
    // Check 1: Admin email configuration
    $admin_email = hic_get_admin_email();
    if (empty($admin_email)) {
        $issues[] = 'Email amministratore non configurato';
        $suggestions[] = 'Configura un indirizzo email nelle impostazioni HIC';
    } elseif (!hic_is_valid_email($admin_email)) {
        $issues[] = 'Email amministratore non valido: ' . $admin_email;
        $suggestions[] = 'Correggi l\'indirizzo email nelle impostazioni';
    }
    
    // Check 2: WordPress mail function
    if (!function_exists('wp_mail')) {
        $issues[] = 'Funzione wp_mail non disponibile';
        $suggestions[] = 'Problema critico di WordPress - contatta lo sviluppatore';
    }
    
    // Check 3: PHP mail function
    if (!function_exists('mail')) {
        $issues[] = 'Funzione mail() PHP non disponibile sul server';
        $suggestions[] = 'Contatta il provider hosting per abilitare la funzione mail()';
    }
    
    // Check 4: Server configuration
    if (function_exists('ini_get')) {
        $smtp_config = ini_get('SMTP');
        $sendmail_path = ini_get('sendmail_path');
        
        if (empty($smtp_config) && empty($sendmail_path)) {
            $issues[] = 'Configurazione email server non trovata';
            $suggestions[] = 'Installa un plugin SMTP (WP Mail SMTP, Easy WP SMTP) o contatta l\'hosting';
        }
    }
    
    // Check 5: Recent email sending attempts (if function exists)
    $email_errors = 0;
    $log_manager = function_exists('\\hic_get_log_manager') ? \hic_get_log_manager() : null;
    $recent_lines = $log_manager ? $log_manager->get_recent_logs(50) : array();

    foreach ($recent_lines as $line) {
        // Handle both raw string lines and parsed log entries
        if (is_array($line)) {
            $line = $line['message'] ?? '';
        }

        if (strpos($line, 'ERRORE invio email') !== false) {
            $email_errors++;
        }
    }
    
    if ($email_errors > 0) {
        $issues[] = "$email_errors errori email negli ultimi log";
        $suggestions[] = 'Controlla i log dettagliati nella sezione Diagnostics';
    }
    
    return array(
        'issues' => $issues,
        'suggestions' => $suggestions,
        'has_issues' => !empty($issues)
    );
}

/* ============ Email Enrichment Functions ============ */
function hic_mark_email_enriched($reservation_id, $real_email) {
    if (empty($reservation_id) || !is_scalar($reservation_id)) {
        hic_log('hic_mark_email_enriched: reservation_id is empty or not scalar');
        return false;
    }

    $normalized_id = hic_normalize_reservation_id((string) $reservation_id);
    if ($normalized_id === '') {
        hic_log('hic_mark_email_enriched: normalized reservation_id is empty');
        return false;
    }

    if (empty($real_email) || !is_string($real_email) || !hic_is_valid_email($real_email)) {
        hic_log('hic_mark_email_enriched: real_email is empty, not string, or invalid email format');
        return false;
    }

    $email_map = get_option('hic_res_email_map', array());
    if (!is_array($email_map)) {
        $email_map = array(); // Reset if corrupted
    }

    $normalized_map = array();

    foreach ($email_map as $key => $value) {
        if (!is_scalar($key)) {
            continue;
        }

        $candidate_key = hic_normalize_reservation_id((string) $key);
        if ($candidate_key === '') {
            continue;
        }

        $normalized_map[$candidate_key] = $value;
    }

    $normalized_map[$normalized_id] = $real_email;

    // Keep only last 5k entries (FIFO) to prevent bloat
    if (count($normalized_map) > 5000) {
        $normalized_map = array_slice($normalized_map, -5000, null, true);
    }

    $result = update_option('hic_res_email_map', $normalized_map, false); // autoload=false
    hic_clear_option_cache('hic_res_email_map');
    return $result;
}

function hic_get_reservation_email($reservation_id) {
    if (empty($reservation_id) || !is_scalar($reservation_id)) {
        return null;
    }

    $normalized_id = hic_normalize_reservation_id((string) $reservation_id);
    if ($normalized_id === '') {
        return null;
    }

    $email_map = get_option('hic_res_email_map', array());
    if (!is_array($email_map)) {
        return null; // Corrupted data
    }

    if (isset($email_map[$normalized_id]) && is_string($email_map[$normalized_id])) {
        return $email_map[$normalized_id];
    }

    foreach ($email_map as $key => $value) {
        if (!is_scalar($key) || !is_string($value)) {
            continue;
        }

        $candidate_key = hic_normalize_reservation_id((string) $key);
        if ($candidate_key === '') {
            continue;
        }

        if ($candidate_key === $normalized_id) {
            return $value;
        }
    }

    return null;
}

/**
 * Normalize stored reservation email mappings once during upgrade.
 */
function hic_upgrade_reservation_email_map() {
    $already_normalized = get_option('hic_res_email_map_normalized');
    if ($already_normalized === '1') {
        return;
    }

    $email_map = get_option('hic_res_email_map', array());
    $normalized_map = array();
    $changed = false;

    if (!is_array($email_map)) {
        $changed = true;
        $email_map = array();
    }

    foreach ($email_map as $key => $value) {
        if (!is_scalar($key) || !is_string($value)) {
            $changed = true;
            continue;
        }

        $original_key = (string) $key;
        $normalized_key = hic_normalize_reservation_id($original_key);
        if ($normalized_key === '') {
            $changed = true;
            continue;
        }

        if ($normalized_key !== $original_key) {
            $changed = true;
        }

        $normalized_map[$normalized_key] = $value;
    }

    if (count($normalized_map) > 5000) {
        $normalized_map = array_slice($normalized_map, -5000, null, true);
        $changed = true;
    }

    if ($changed) {
        update_option('hic_res_email_map', $normalized_map, false);
        hic_clear_option_cache('hic_res_email_map');
    }

    update_option('hic_res_email_map_normalized', '1', false);
}

/**
 * Normalize stored integration retry queue keys once during upgrade.
 */
function hic_upgrade_integration_retry_queue(): void {
    $already_normalized = get_option('hic_integration_retry_queue_normalized');
    if ($already_normalized === '1') {
        return;
    }

    $queue = get_option('hic_integration_retry_queue', []);
    $changed = false;

    if (!is_array($queue)) {
        $queue = [];
        $changed = true;
    }

    $normalized_queue = [];

    foreach ($queue as $key => $entry) {
        if (!is_scalar($key) || !is_array($entry)) {
            $changed = true;
            continue;
        }

        $normalized_key = hic_normalize_reservation_id((string) $key);
        if ($normalized_key === '') {
            $changed = true;
            continue;
        }

        if (!isset($normalized_queue[$normalized_key])) {
            $normalized_queue[$normalized_key] = [
                'integrations' => [],
                'context' => [],
                'last_updated' => 0,
            ];
        }

        $normalized_entry = &$normalized_queue[$normalized_key];

        if (isset($entry['integrations']) && is_array($entry['integrations'])) {
            foreach ($entry['integrations'] as $integration => $payload) {
                if (!is_scalar($integration) || !is_array($payload)) {
                    $changed = true;
                    continue;
                }

                $safe_integration = \sanitize_text_field((string) $integration);
                if ($safe_integration === '') {
                    $changed = true;
                    continue;
                }

                $sanitized_payload = [
                    'note' => '',
                    'last_failure' => 0,
                ];

                if (isset($payload['note']) && is_scalar($payload['note'])) {
                    $sanitized_payload['note'] = \sanitize_text_field((string) $payload['note']);
                }

                if (isset($payload['last_failure']) && is_numeric($payload['last_failure'])) {
                    $sanitized_payload['last_failure'] = max(0, (int) $payload['last_failure']);
                }

                if (isset($normalized_entry['integrations'][$safe_integration])) {
                    $existing_payload = $normalized_entry['integrations'][$safe_integration];
                    if (!is_array($existing_payload)) {
                        $existing_payload = [
                            'note' => '',
                            'last_failure' => 0,
                        ];
                    }

                    $existing_last_failure = isset($existing_payload['last_failure']) && is_numeric($existing_payload['last_failure'])
                        ? (int) $existing_payload['last_failure']
                        : 0;

                    if ($sanitized_payload['last_failure'] < $existing_last_failure) {
                        $sanitized_payload['last_failure'] = $existing_last_failure;
                    }

                    if ($sanitized_payload['note'] === '' && isset($existing_payload['note']) && is_scalar($existing_payload['note'])) {
                        $sanitized_payload['note'] = \sanitize_text_field((string) $existing_payload['note']);
                    }
                }

                if ($safe_integration !== (string) $integration) {
                    $changed = true;
                }

                $normalized_entry['integrations'][$safe_integration] = $sanitized_payload;
            }
        } else {
            $changed = true;
        }

        if (isset($entry['context']) && is_array($entry['context'])) {
            foreach ($entry['context'] as $context_key => $context_value) {
                if (!is_scalar($context_key) || !is_scalar($context_value)) {
                    $changed = true;
                    continue;
                }

                $safe_context_key = \sanitize_key((string) $context_key);
                $normalized_entry['context'][$safe_context_key] = \sanitize_text_field((string) $context_value);
            }
        } elseif (isset($entry['context'])) {
            $changed = true;
        }

        if (isset($entry['last_updated']) && is_numeric($entry['last_updated'])) {
            $normalized_entry['last_updated'] = max($normalized_entry['last_updated'], (int) $entry['last_updated']);
        } else {
            $changed = true;
        }

        unset($normalized_entry);
    }

    foreach ($normalized_queue as $key => &$entry) {
        if (empty($entry['integrations'])) {
            unset($normalized_queue[$key]);
            $changed = true;
            continue;
        }

        if (!isset($entry['context']) || !is_array($entry['context'])) {
            $entry['context'] = [];
        }
    }
    unset($entry);

    if (count($normalized_queue) > 200) {
        $normalized_queue = array_slice($normalized_queue, -200, null, true);
        $changed = true;
    }

    if ($changed || $normalized_queue !== $queue) {
        update_option('hic_integration_retry_queue', $normalized_queue, false);
        hic_clear_option_cache('hic_integration_retry_queue');
    }

    update_option('hic_integration_retry_queue_normalized', '1', false);
}

/* ================= DEDUPLICATION HELPER FUNCTIONS ================= */

/**
 * Extract reservation ID from webhook data for deduplication
 */
function hic_extract_reservation_id($data) {
    if (!is_array($data)) {
        return null;
    }

    // Try different field names in order of preference
    $id_fields = hic_candidate_reservation_id_fields(['transaction_id', 'reservation_id', 'id', 'booking_id']);

    foreach ($id_fields as $field) {
        if (!array_key_exists($field, $data)) {
            continue;
        }

        $value = $data[$field];
        if (!is_scalar($value)) {
            continue;
        }

        $candidate = trim((string) $value);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return null;
}

/**
 * Normalize stored timestamp values used for FIFO trimming.
 *
 * @param mixed $value
 */
function hic_normalize_processed_reservation_timestamp($value, int $fallback): int {
    if (is_int($value) || is_float($value)) {
        $normalized = (int) $value;
        return $normalized >= 0 ? $normalized : $fallback;
    }

    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed !== '' && ctype_digit($trimmed)) {
            $normalized = (int) $trimmed;
            return $normalized >= 0 ? $normalized : $fallback;
        }
    }

    return $fallback;
}

/**
 * Retrieve the processed reservation ID set as an associative array keyed by ID.
 *
 * @return array<string, int>
 */
function hic_get_processed_reservation_id_set(): array {
    $stored = get_option('hic_synced_res_ids', array());
    if (!is_array($stored)) {
        return array();
    }

    $normalized = array();
    $position = 0;

    foreach ($stored as $key => $value) {
        $position++;

        $id = '';
        $timestamp = $position;

        if (is_string($key) && $key !== '') {
            $id = hic_normalize_reservation_id($key);
            if ($id === '') {
                continue;
            }

            $timestamp = hic_normalize_processed_reservation_timestamp($value, $position);
        } elseif (is_scalar($value)) {
            $id = hic_normalize_reservation_id((string) $value);
            if ($id === '') {
                continue;
            }
        } else {
            continue;
        }

        if (!array_key_exists($id, $normalized)) {
            $normalized[$id] = $timestamp;
        } else {
            $normalized[$id] = min($normalized[$id], $timestamp);
        }
    }

    return $normalized;
}

/**
 * Persist the processed reservation ID set while enforcing FIFO trimming.
 *
 * @param array<string, int> $set
 */
function hic_store_processed_reservation_id_set(array $set): void {
    if (count($set) > 10000) {
        asort($set);
        $set = array_slice($set, -10000, null, true);
    }

    update_option('hic_synced_res_ids', $set, false);
    hic_clear_option_cache('hic_synced_res_ids');
}

/**
 * Mark reservation as processed by ID (for webhook deduplication)
 */
function hic_mark_reservation_processed_by_id($reservation_id) {
    if (empty($reservation_id)) return false;

    $ids = array();
    if (is_array($reservation_id)) {
        foreach ($reservation_id as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }

            $sanitized = hic_normalize_reservation_id((string) $candidate);
            if ($sanitized === '') {
                continue;
            }

            $ids[$sanitized] = true;
        }
    } elseif (is_scalar($reservation_id)) {
        $sanitized = hic_normalize_reservation_id((string) $reservation_id);
        if ($sanitized !== '') {
            $ids[$sanitized] = true;
        }
    }

    if (empty($ids)) {
        return false;
    }

    $existing = hic_get_processed_reservation_id_set();
    $changed = false;
    $next_value = empty($existing) ? time() : (max($existing) + 1);

    foreach (array_keys($ids) as $id) {
        if (!array_key_exists($id, $existing)) {
            $existing[$id] = $next_value++;
            $changed = true;
        }
    }

    if ($changed) {
        hic_store_processed_reservation_id_set($existing);

        $log_ids = array_keys($ids);
        if (count($log_ids) === 1) {
            hic_log('Marked reservation ' . $log_ids[0] . ' as processed for deduplication');
        } else {
            hic_log('Marked reservation aliases as processed for deduplication: ' . implode(', ', $log_ids));
        }
    }

    return $changed;
}

/* ================= TRANSACTION LOCKING FUNCTIONS ================= */

/**
 * Acquire a lock for processing a specific reservation to prevent concurrent processing
 */
function hic_acquire_reservation_lock($reservation_id, $timeout = 30) {
  if (empty($reservation_id)) return false;
  
  $lock_key = 'hic_processing_lock_' . md5($reservation_id);
  $lock_time = current_time('timestamp');
  
  // Check if there's already a recent lock
  $existing_lock = get_transient($lock_key);
  if ($existing_lock !== false) {
    $time_diff = $lock_time - $existing_lock;
    if ($time_diff < $timeout) {
      hic_log("Reservation $reservation_id: processing lock exists (age: {$time_diff}s), skipping");
      return false;
    } else {
      hic_log("Reservation $reservation_id: expired lock found (age: {$time_diff}s), acquiring new lock");
    }
  }
  
  // Set the lock with timeout
  set_transient($lock_key, $lock_time, $timeout);
  hic_log("Reservation $reservation_id: processing lock acquired");
  return true;
}

/**
 * Release the processing lock for a reservation
 */
function hic_release_reservation_lock($reservation_id) {
  if (empty($reservation_id)) return false;
  
  $lock_key = 'hic_processing_lock_' . md5($reservation_id);
  delete_transient($lock_key);
  hic_log("Reservation $reservation_id: processing lock released");
  return true;
}

/**
 * Check if reservation ID was already processed (shared with polling)
 */
function hic_is_reservation_already_processed($reservation_id) {
    if (empty($reservation_id)) return false;

    $ids = array();
    if (is_array($reservation_id)) {
        foreach ($reservation_id as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }

            $sanitized = hic_normalize_reservation_id((string) $candidate);
            if ($sanitized === '') {
                continue;
            }

            $ids[$sanitized] = true;
        }
    } elseif (is_scalar($reservation_id)) {
        $sanitized = hic_normalize_reservation_id((string) $reservation_id);
        if ($sanitized !== '') {
            $ids[$sanitized] = true;
        }
    }

    if (empty($ids)) {
        return false;
    }

    $synced = hic_get_processed_reservation_id_set();
    foreach (array_keys($ids) as $id) {
        if (array_key_exists($id, $synced)) {
            return true;
        }
    }

    return false;
}

/* ================= DIAGNOSTIC FUNCTIONS ================= */

/**
 * Get processing statistics for diagnostics
 */
function hic_get_processing_statistics() {
    $synced = hic_get_processed_reservation_id_set();
    $current_locks = array();
    
    // Check for active locks (this is just for diagnostics)
    // In production, locks are short-lived (30 seconds max)
    $lock_prefix = 'hic_processing_lock_';
    global $wpdb;
    
    $statistics = array(
        'total_processed_reservations' => count($synced),
        'last_webhook_processing' => get_option('hic_last_webhook_processing', 'never'),
        'last_polling_processing' => get_option('hic_last_api_poll', 'never'),
        'connection_type' => hic_get_connection_type(),
        'deduplication_enabled' => true,
        'transaction_locking_enabled' => true
    );
    
    return $statistics;
}


