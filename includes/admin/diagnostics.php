<?php
/**
 * HIC Plugin Diagnostics and Monitoring
 */

if (!defined('ABSPATH')) exit;

/* ============ Cron Diagnostics Functions ============ */

/**
 * Check internal scheduler status (uses WordPress Heartbeat API)
 */
function hic_get_internal_scheduler_status() {
    $status = array(
        'internal_scheduler' => array(
            'enabled' => hic_reliable_polling_enabled(),
            'conditions_met' => false,
            'last_poll' => null,
            'last_poll_human' => 'Mai eseguito',
            'lag_seconds' => 0,
            'next_run_estimate' => null,
            'next_run_human' => 'Sconosciuto'
        )
    );
    
    // Check if internal scheduler conditions are met
    $status['internal_scheduler']['conditions_met'] = 
        hic_reliable_polling_enabled() && 
        hic_get_connection_type() === 'api' && 
        hic_get_api_url() && 
        (hic_has_basic_auth_credentials() || hic_get_api_key());
    
    // Get stats from heartbeat scheduler if available
    if (class_exists('HIC_Booking_Poller')) {
        $poller = new HIC_Booking_Poller();
        $poller_stats = $poller->get_stats();
        
        if (!isset($poller_stats['error'])) {
            $status['internal_scheduler'] = array_merge($status['internal_scheduler'], array(
                'last_poll' => $poller_stats['last_poll'] ?? 0,
                'last_poll_human' => $poller_stats['last_poll_human'] ?? 'Mai eseguito',
                'lag_seconds' => $poller_stats['lag_seconds'] ?? 0,
                'polling_interval' => $poller_stats['polling_interval'] ?? 300
            ));
            
            // Calculate next run estimate (Heartbeat runs every 60 seconds)
            if ($status['internal_scheduler']['last_poll'] > 0) {
                $next_run_estimate = $status['internal_scheduler']['last_poll'] + $status['internal_scheduler']['polling_interval'];
                $status['internal_scheduler']['next_run_estimate'] = $next_run_estimate;
                
                if ($next_run_estimate > time()) {
                    $status['internal_scheduler']['next_run_human'] = human_time_diff($next_run_estimate, time()) . ' da ora';
                } else {
                    $status['internal_scheduler']['next_run_human'] = 'Ora (in attesa Heartbeat)';
                }
            }
        }
    }
    
    // Real-time sync stats (keep existing functionality)
    $realtime_table = $wpdb->prefix . 'hic_realtime_sync';
    if ($wpdb->get_var("SHOW TABLES LIKE '$realtime_table'") === $realtime_table) {
        $status['realtime_sync']['total_tracked'] = $wpdb->get_var("SELECT COUNT(*) FROM $realtime_table");
        $status['realtime_sync']['notified'] = $wpdb->get_var("SELECT COUNT(*) FROM $realtime_table WHERE sync_status = 'notified'");
        $status['realtime_sync']['failed'] = $wpdb->get_var("SELECT COUNT(*) FROM $realtime_table WHERE sync_status = 'failed'");
        $status['realtime_sync']['new'] = $wpdb->get_var("SELECT COUNT(*) FROM $realtime_table WHERE sync_status = 'new'");
    } else {
        $status['realtime_sync']['table_exists'] = false;
    }
    
    return $status;
}

/**
 * Check if main polling should be scheduled based on conditions
 */
function hic_should_schedule_poll_event() {
    if (hic_get_connection_type() !== 'api') {
        return false;
    }
    
    if (!hic_get_api_url()) {
        return false;
    }
    
    // Check if we have Basic Auth credentials or legacy API key
    $has_basic_auth = hic_has_basic_auth_credentials();
    $has_legacy_key = hic_get_api_key();
    
    return $has_basic_auth || $has_legacy_key;
}

/**
 * Check if updates polling should be scheduled based on conditions
 */
function hic_should_schedule_updates_event() {
    if (hic_get_connection_type() !== 'api') {
        return false;
    }
    
    if (!hic_get_api_url()) {
        return false;
    }
    
    if (!hic_updates_enrich_contacts()) {
        return false;
    }
    
    // Updates polling requires Basic Auth
    return hic_has_basic_auth_credentials();
}

/**
 * Get credentials and API status
 */
function hic_get_credentials_status() {
    return array(
        'connection_type' => hic_get_connection_type(),
        'api_url' => !empty(hic_get_api_url()),
        'property_id' => !empty(hic_get_property_id()),
        'api_email' => !empty(hic_get_api_email()),
        'api_password' => !empty(hic_get_api_password()),
        'api_key_legacy' => !empty(hic_get_api_key()),
        'updates_enrich_enabled' => hic_updates_enrich_contacts(),
        'ga4_configured' => !empty(hic_get_measurement_id()) && !empty(hic_get_api_secret()),
        'brevo_configured' => hic_is_brevo_enabled() && !empty(hic_get_brevo_api_key()),
        'facebook_configured' => !empty(hic_get_fb_pixel_id()) && !empty(hic_get_fb_access_token())
    );
}

/**
 * Get last execution times and stats
 */
function hic_get_execution_stats() {
    return array(
        'last_poll_time' => get_option('hic_last_api_poll', 0),
        'last_successful_poll' => get_option('hic_last_successful_poll', 0),
        'last_updates_time' => get_option('hic_last_updates_since', 0),
        'processed_reservations' => count(get_option('hic_synced_res_ids', array())),
        'enriched_emails' => count(get_option('hic_res_email_map', array())),
        'last_poll_reservations_found' => get_option('hic_last_poll_count', 0),
        'last_poll_skipped' => get_option('hic_last_poll_skipped', 0),
        'last_poll_duration' => get_option('hic_last_poll_duration', 0),
        'polling_interval' => hic_get_polling_interval(),
        'log_file_exists' => file_exists(hic_get_log_file()),
        'log_file_size' => file_exists(hic_get_log_file()) ? filesize(hic_get_log_file()) : 0
    );
}

/**
 * Get recent log entries (errors and important events)
 */
function hic_get_recent_log_entries($limit = 50) {
    $log_file = hic_get_log_file();
    if (!file_exists($log_file)) {
        return array();
    }
    
    $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return array();
    }
    
    // Get last $limit lines
    $recent_lines = array_slice($lines, -$limit);
    
    // Filter for important entries (errors, dispatches, etc.)
    $important_entries = array();
    foreach ($recent_lines as $line) {
        if (preg_match('/error|errore|fallita|HTTP [45]\d\d|dispatched|inviato/i', $line)) {
            $important_entries[] = $line;
        }
    }
    
    return array_reverse($important_entries); // Most recent first
}



/**
 * Test dispatch functions with sample data - Essential integrations only
 */
function hic_test_dispatch_functions() {
    // Test data for integrations
    $test_data = array(
        'reservation_id' => 'TEST_' . time(),
        'id' => 'TEST_' . time(),
        'amount' => 100.00,
        'currency' => 'EUR',
        'email' => 'test@example.com',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'lingua' => 'it',
        'room' => 'Standard Room',
        'checkin' => date('Y-m-d', strtotime('+7 days')),
        'checkout' => date('Y-m-d', strtotime('+10 days'))
    );
    
    $results = array();
    
    try {
        // Test with organic traffic (no tracking IDs)
        $gclid = null;
        $fbclid = null;
        
        // Test GA4
        if (!empty(hic_get_measurement_id()) && !empty(hic_get_api_secret())) {
            hic_send_to_ga4($test_data, $gclid, $fbclid);
            $results['ga4'] = 'Test event sent to GA4';
        } else {
            $results['ga4'] = 'GA4 not configured';
        }
        
        // Test Facebook
        if (!empty(hic_get_fb_pixel_id()) && !empty(hic_get_fb_access_token())) {
            hic_send_to_fb($test_data, $gclid, $fbclid);
            $results['facebook'] = 'Test event sent to Facebook';
        } else {
            $results['facebook'] = 'Facebook not configured';
        }
        
        // Test Brevo
        if (hic_is_brevo_enabled() && !empty(hic_get_brevo_api_key())) {
            hic_send_brevo_contact($test_data, $gclid, $fbclid);
            hic_send_brevo_event($test_data, $gclid, $fbclid);
            $results['brevo'] = 'Test contact and event sent to Brevo';
        } else {
            $results['brevo'] = 'Brevo not configured or disabled';
        }
        
        // Test Admin Email
        $admin_email = hic_get_admin_email();
        if (!empty($admin_email)) {
            hic_send_admin_email($test_data, $gclid, $fbclid, 'test_' . time());
            $results['admin_email'] = 'Test email sent to admin: ' . $admin_email;
        } else {
            $results['admin_email'] = 'Admin email not configured';
        }
        
        return array('success' => true, 'results' => $results);
        
    } catch (Exception $e) {
        return array('success' => false, 'message' => 'Error: ' . $e->getMessage());
    }
}



/**
 * Force restart of internal scheduler (replaces WP-Cron rescheduling)
 */
function hic_force_restart_internal_scheduler() {
    hic_log('Force restart: Starting internal scheduler restart process');
    
    $results = array();
    
    // Clear any existing WP-Cron events (cleanup legacy events)
    $legacy_events = array('hic_api_poll_event', 'hic_api_updates_event', 'hic_retry_failed_notifications_event', 'hic_reliable_poll_event');
    foreach ($legacy_events as $event) {
        wp_clear_scheduled_hook($event);
        $results['legacy_' . $event . '_cleared'] = 'Cleared all legacy cron events';
    }
    
    // Check if internal scheduler should be active
    $should_activate = hic_reliable_polling_enabled() && 
                      hic_get_connection_type() === 'api' && 
                      hic_get_api_url() && 
                      (hic_has_basic_auth_credentials() || hic_get_api_key());
    
    if ($should_activate) {
        // Reset polling timestamps to trigger immediate execution
        delete_option('hic_last_api_poll');
        delete_option('hic_last_successful_poll');
        $results['polling_timestamps_reset'] = 'Timestamps reset for immediate execution';
        
        // Trigger an immediate poll if the poller is available
        if (function_exists('hic_api_poll_bookings')) {
            hic_log('Force restart: Triggering immediate polling');
            hic_api_poll_bookings();
            $results['immediate_poll_triggered'] = 'Polling executed immediately';
        }
        
        $results['internal_scheduler'] = 'Restarted and ready';
        hic_log('Force restart: Internal scheduler restart completed successfully');
    } else {
        $results['internal_scheduler'] = 'Conditions not met for activation';
        hic_log('Force restart: Conditions not met for internal scheduler');
    }
    
    return $results;
}



/**
 * Get recent error count from logs
 */
function hic_get_error_stats() {
    $log_lines_to_check = 1000; // Configurable number of recent log lines to analyze
    
    $log_file = hic_get_log_file();
    if (!file_exists($log_file)) {
        return array('error_count' => 0, 'last_error' => null);
    }
    
    $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return array('error_count' => 0, 'last_error' => null);
    }
    
    // Count errors in recent lines
    $recent_lines = array_slice($lines, -$log_lines_to_check);
    $error_count = 0;
    $last_error = null;
    
    foreach (array_reverse($recent_lines) as $line) {
        if (preg_match('/(error|errore|fallita|failed|HTTP [45]\d\d)/i', $line)) {
            $error_count++;
            if (!$last_error) {
                $last_error = $line;
            }
        }
    }
    
    return array(
        'error_count' => $error_count,
        'last_error' => $last_error
    );
}

/* ============ AJAX Handlers ============ */

/**
 * Get the downloaded booking IDs to avoid duplicates
 */
function hic_get_downloaded_booking_ids() {
    return get_option('hic_downloaded_booking_ids', array());
}

/**
 * Add booking IDs to the downloaded list
 */
function hic_mark_bookings_as_downloaded($booking_ids) {
    $downloaded_ids = hic_get_downloaded_booking_ids();
    
    foreach ($booking_ids as $id) {
        if (!in_array($id, $downloaded_ids)) {
            $downloaded_ids[] = $id;
        }
    }
    
    // Keep only the last 100 downloaded IDs to prevent the list from growing indefinitely
    if (count($downloaded_ids) > 100) {
        $downloaded_ids = array_slice($downloaded_ids, -100);
    }
    
    update_option('hic_downloaded_booking_ids', $downloaded_ids);
    
    hic_log("Marked " . count($booking_ids) . " bookings as downloaded. Total tracked: " . count($downloaded_ids));
}

/**
 * Reset the downloaded bookings tracking (admin function)
 */
function hic_reset_downloaded_bookings() {
    delete_option('hic_downloaded_booking_ids');
    hic_log("Reset downloaded bookings tracking");
}

/**
 * Get the latest bookings from the API (with duplicate prevention)
 */
function hic_get_latest_bookings($limit = 5, $skip_downloaded = true) {
    $prop_id = hic_get_property_id();
    
    if (!$prop_id) {
        return new WP_Error('missing_prop_id', 'Property ID non configurato');
    }
    
    // Check API connection type
    if (hic_get_connection_type() !== 'api') {
        return new WP_Error('wrong_connection', 'Sistema configurato per webhook, non API');
    }
    
    // Validate credentials
    if (!hic_has_basic_auth_credentials() && !hic_get_api_key()) {
        return new WP_Error('missing_credentials', 'Credenziali API non configurate');
    }
    
    // Get downloaded booking IDs for filtering
    $downloaded_ids = $skip_downloaded ? hic_get_downloaded_booking_ids() : array();
    
    // Get bookings from the last 30 days to ensure we get recent ones
    $to_date = date('Y-m-d');
    $from_date = date('Y-m-d', strtotime('-30 days'));
    
    hic_log("Fetching latest $limit bookings for property $prop_id from $from_date to $to_date" . 
            ($skip_downloaded ? " (skipping " . count($downloaded_ids) . " already downloaded)" : ""));
    
    // Get more bookings to account for filtering out downloaded ones
    $fetch_limit = $skip_downloaded ? $limit * 3 : $limit * 2;
    
    // Use the existing fetch function but without processing
    $result = hic_fetch_reservations_raw($prop_id, 'checkin', $from_date, $to_date, $fetch_limit);
    
    if (is_wp_error($result)) {
        return $result;
    }
    
    if (!is_array($result)) {
        return new WP_Error('invalid_response', 'Risposta API non valida');
    }
    
    // Sort by creation date (newest first)
    usort($result, function($a, $b) {
        $date_a = isset($a['created_at']) ? $a['created_at'] : $a['from_date'];
        $date_b = isset($b['created_at']) ? $b['created_at'] : $b['from_date'];
        return strtotime($date_b) - strtotime($date_a);
    });
    
    // Filter out already downloaded bookings if skip_downloaded is true
    if ($skip_downloaded && !empty($downloaded_ids)) {
        $result = array_filter($result, function($booking) use ($downloaded_ids) {
            $booking_id = $booking['id'] ?? '';
            return !in_array($booking_id, $downloaded_ids);
        });
        
        // Re-index the array after filtering
        $result = array_values($result);
        
        hic_log("After filtering downloaded bookings: " . count($result) . " bookings remain");
    }
    
    // Return only the requested number
    return array_slice($result, 0, $limit);
}

/**
 * Format bookings as CSV
 */
function hic_format_bookings_as_csv($bookings) {
    if (empty($bookings)) {
        return '';
    }
    
    // CSV headers
    $headers = array(
        'ID Prenotazione',
        'Nome',
        'Cognome', 
        'Email',
        'Telefono',
        'Camera/Alloggio',
        'Check-in',
        'Check-out',
        'Importo',
        'Valuta',
        'Stato',
        'Presenza',
        'Data Creazione',
        'Note'
    );
    
    $csv_lines = array();
    $csv_lines[] = '"' . implode('","', $headers) . '"';
    
    foreach ($bookings as $booking) {
        $row = array(
            $booking['id'] ?? '',
            $booking['client_first_name'] ?? $booking['first_name'] ?? '',
            $booking['client_last_name'] ?? $booking['last_name'] ?? '',
            $booking['client_email'] ?? $booking['email'] ?? '',
            $booking['client_phone'] ?? $booking['phone'] ?? '',
            $booking['accommodation_name'] ?? $booking['room'] ?? '',
            $booking['from_date'] ?? $booking['checkin'] ?? '',
            $booking['to_date'] ?? $booking['checkout'] ?? '',
            $booking['amount'] ?? $booking['total'] ?? '',
            $booking['currency'] ?? 'EUR',
            $booking['status'] ?? '',
            $booking['presence'] ?? '',
            $booking['created_at'] ?? '',
            $booking['notes'] ?? $booking['description'] ?? ''
        );
        
        // Escape and quote each field
        $escaped_row = array_map(function($field) {
            return '"' . str_replace('"', '""', $field) . '"';
        }, $row);
        
        $csv_lines[] = implode(',', $escaped_row);
    }
    
    return implode("\n", $csv_lines);
}

/* ============ AJAX Handlers ============ */

// Add AJAX handlers
add_action('wp_ajax_hic_refresh_diagnostics', 'hic_ajax_refresh_diagnostics');
add_action('wp_ajax_hic_test_dispatch', 'hic_ajax_test_dispatch');
add_action('wp_ajax_hic_force_reschedule', 'hic_ajax_force_reschedule');
add_action('wp_ajax_hic_create_tables', 'hic_ajax_create_tables');
add_action('wp_ajax_hic_backfill_reservations', 'hic_ajax_backfill_reservations');
add_action('wp_ajax_hic_download_latest_bookings', 'hic_ajax_download_latest_bookings');
add_action('wp_ajax_hic_reset_download_tracking', 'hic_ajax_reset_download_tracking');



function hic_ajax_refresh_diagnostics() {
    // Verify nonce
    if (!check_ajax_referer('hic_diagnostics_nonce', 'nonce', false)) {
        wp_die(json_encode(array('success' => false, 'message' => 'Invalid nonce')));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(array('success' => false, 'message' => 'Insufficient permissions')));
    }
    
    $data = array(
        'scheduler_status' => hic_get_internal_scheduler_status(),
        'credentials_status' => hic_get_credentials_status(),
        'execution_stats' => hic_get_execution_stats(),
        'recent_logs' => hic_get_recent_log_entries(20),
        'error_stats' => hic_get_error_stats()
    );
    
    wp_die(json_encode(array('success' => true, 'data' => $data)));
}

function hic_ajax_test_dispatch() {
    // Verify nonce
    if (!check_ajax_referer('hic_diagnostics_nonce', 'nonce', false)) {
        wp_die(json_encode(array('success' => false, 'message' => 'Invalid nonce')));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(array('success' => false, 'message' => 'Insufficient permissions')));
    }
    
    $result = hic_test_dispatch_functions();
    wp_die(json_encode($result));
}

function hic_ajax_force_reschedule() {
    // Verify nonce
    if (!check_ajax_referer('hic_diagnostics_nonce', 'nonce', false)) {
        wp_die(json_encode(array('success' => false, 'message' => 'Invalid nonce')));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(array('success' => false, 'message' => 'Insufficient permissions')));
    }
    
    $results = hic_force_restart_internal_scheduler();
    wp_die(json_encode(array('success' => true, 'results' => $results)));
}

function hic_ajax_create_tables() {
    // Verify nonce
    if (!check_ajax_referer('hic_diagnostics_nonce', 'nonce', false)) {
        wp_die(json_encode(array('success' => false, 'message' => 'Invalid nonce')));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(array('success' => false, 'message' => 'Insufficient permissions')));
    }
    
    try {
        // Call the database table creation function
        $result = hic_create_database_table();
        
        if ($result) {
            // Check which tables now exist
            global $wpdb;
            $tables_status = array();
            $expected_tables = array(
                'gclids' => $wpdb->prefix . 'hic_gclids',
                'realtime_sync' => $wpdb->prefix . 'hic_realtime_sync',
                'booking_events' => $wpdb->prefix . 'hic_booking_events'
            );
            
            $all_exist = true;
            foreach ($expected_tables as $name => $table) {
                $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
                $tables_status[$name] = $exists;
                if (!$exists) $all_exist = false;
            }
            
            $details = array();
            foreach ($tables_status as $name => $exists) {
                $details[] = $name . ': ' . ($exists ? 'OK' : 'MANCANTE');
            }
            
            wp_die(json_encode(array(
                'success' => $all_exist,
                'message' => $all_exist ? 'Tutte le tabelle sono state create/verificate con successo.' : 'Alcune tabelle potrebbero non essere state create.',
                'details' => implode(', ', $details)
            )));
            
        } else {
            wp_die(json_encode(array(
                'success' => false,
                'message' => 'Errore durante la creazione delle tabelle. Controlla i log per maggiori dettagli.'
            )));
        }
        
    } catch (Exception $e) {
        wp_die(json_encode(array(
            'success' => false,
            'message' => 'Errore: ' . $e->getMessage()
        )));
    }
}

function hic_ajax_backfill_reservations() {
    // Verify nonce
    if (!check_ajax_referer('hic_diagnostics_nonce', 'nonce', false)) {
        wp_die(json_encode(array('success' => false, 'message' => 'Invalid nonce')));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(array('success' => false, 'message' => 'Insufficient permissions')));
    }
    
    // Get and validate input parameters
    $from_date = sanitize_text_field($_POST['from_date'] ?? '');
    $to_date = sanitize_text_field($_POST['to_date'] ?? '');
    $date_type = sanitize_text_field($_POST['date_type'] ?? 'checkin');
    $limit = isset($_POST['limit']) ? intval($_POST['limit']) : null;
    
    // Validate date type (based on API documentation: checkin, checkout, presence for /reservations, created for /reservations_updates)
    if (!in_array($date_type, array('checkin', 'checkout', 'presence', 'created'))) {
        wp_die(json_encode(array('success' => false, 'message' => 'Tipo di data non valido. Deve essere "checkin", "checkout", "presence" o "created".')));
    }
    
    // Validate required fields
    if (empty($from_date) || empty($to_date)) {
        wp_die(json_encode(array('success' => false, 'message' => 'Date di inizio e fine sono obbligatorie')));
    }
    
    // Call the backfill function
    $result = hic_backfill_reservations($from_date, $to_date, $date_type, $limit);
    
    wp_die(json_encode($result));
}

/**
 * Convert API booking format to processor format
 */
function hic_convert_api_booking_to_processor_format($api_booking) {
    return array(
        'reservation_id' => $api_booking['id'] ?? '',
        'id' => $api_booking['id'] ?? '',
        'amount' => $api_booking['amount'] ?? $api_booking['total'] ?? 0,
        'currency' => $api_booking['currency'] ?? 'EUR',
        'email' => $api_booking['client_email'] ?? $api_booking['email'] ?? '',
        'first_name' => $api_booking['client_first_name'] ?? $api_booking['first_name'] ?? '',
        'last_name' => $api_booking['client_last_name'] ?? $api_booking['last_name'] ?? '',
        'lingua' => 'it', // Default to Italian
        'room' => $api_booking['accommodation_name'] ?? $api_booking['room'] ?? '',
        'checkin' => $api_booking['from_date'] ?? $api_booking['checkin'] ?? '',
        'checkout' => $api_booking['to_date'] ?? $api_booking['checkout'] ?? '',
        'phone' => $api_booking['client_phone'] ?? $api_booking['phone'] ?? '',
        'status' => $api_booking['status'] ?? '',
        'presence' => $api_booking['presence'] ?? '',
        'created_at' => $api_booking['created_at'] ?? '',
        'notes' => $api_booking['notes'] ?? $api_booking['description'] ?? '',
        // Add sid as null since these are manual downloads
        'sid' => null
    );
}

function hic_ajax_download_latest_bookings() {
    // Verify nonce
    if (!check_ajax_referer('hic_diagnostics_nonce', 'nonce', false)) {
        wp_die(json_encode(array('success' => false, 'message' => 'Invalid nonce')));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(array('success' => false, 'message' => 'Insufficient permissions')));
    }
    
    try {
        // Get latest bookings (with duplicate prevention)
        $result = hic_get_latest_bookings(5, true);
        
        if (is_wp_error($result)) {
            wp_die(json_encode(array(
                'success' => false, 
                'message' => 'Errore nel recupero prenotazioni: ' . $result->get_error_message()
            )));
        }
        
        if (empty($result)) {
            // Check if we have any bookings at all (without filtering)
            $all_bookings = hic_get_latest_bookings(5, false);
            if (is_wp_error($all_bookings) || empty($all_bookings)) {
                wp_die(json_encode(array(
                    'success' => false, 
                    'message' => 'Nessuna prenotazione trovata nell\'API'
                )));
            } else {
                wp_die(json_encode(array(
                    'success' => false, 
                    'message' => 'Tutte le ultime 5 prenotazioni sono già state inviate. Usa il bottone "Reset Download Tracking" per reinviarle.',
                    'already_downloaded' => true
                )));
            }
        }
        
        // Process bookings through the normal integration pipeline
        $processing_results = array();
        $success_count = 0;
        $error_count = 0;
        $booking_ids = array();
        
        foreach ($result as $booking) {
            // Extract booking ID for tracking
            if (isset($booking['id']) && !empty($booking['id'])) {
                $booking_ids[] = $booking['id'];
            }
            
            // Convert API booking format to processor format
            $processed_data = hic_convert_api_booking_to_processor_format($booking);
            
            // Process the booking through normal integration pipeline
            hic_log("Processing downloaded booking ID: " . ($booking['id'] ?? 'N/A') . " for integrations");
            
            $processing_success = hic_process_booking_data($processed_data);
            
            $processing_results[] = array(
                'booking_id' => $booking['id'] ?? 'N/A',
                'email' => $processed_data['email'] ?? 'N/A',
                'success' => $processing_success,
                'amount' => $processed_data['amount'] ?? 'N/A'
            );
            
            if ($processing_success) {
                $success_count++;
            } else {
                $error_count++;
            }
        }
        
        // Mark these bookings as processed
        if (!empty($booking_ids)) {
            hic_mark_bookings_as_downloaded($booking_ids);
        }
        
        // Get integration status for report
        $integration_status = array(
            'ga4_configured' => !empty(hic_get_measurement_id()) && !empty(hic_get_api_secret()),
            'brevo_configured' => hic_is_brevo_enabled() && !empty(hic_get_brevo_api_key()),
            'facebook_configured' => !empty(hic_get_fb_pixel_id()) && !empty(hic_get_fb_access_token())
        );
        
        wp_die(json_encode(array(
            'success' => true,
            'message' => "Prenotazioni inviate alle integrazioni configurate",
            'count' => count($result),
            'success_count' => $success_count,
            'error_count' => $error_count,
            'booking_ids' => $booking_ids,
            'integration_status' => $integration_status,
            'processing_results' => $processing_results
        )));
        
    } catch (Exception $e) {
        wp_die(json_encode(array(
            'success' => false, 
            'message' => 'Errore: ' . $e->getMessage()
        )));
    }
}

function hic_ajax_reset_download_tracking() {
    // Verify nonce
    if (!check_ajax_referer('hic_diagnostics_nonce', 'nonce', false)) {
        wp_die(json_encode(array('success' => false, 'message' => 'Invalid nonce')));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(array('success' => false, 'message' => 'Insufficient permissions')));
    }
    
    try {
        // Reset the download tracking
        hic_reset_downloaded_bookings();
        
        wp_die(json_encode(array(
            'success' => true,
            'message' => 'Tracking degli invii resettato con successo. Ora puoi inviare nuovamente tutte le prenotazioni alle integrazioni.'
        )));
        
    } catch (Exception $e) {
        wp_die(json_encode(array(
            'success' => false, 
            'message' => 'Errore durante il reset: ' . $e->getMessage()
        )));
    }
}

/* ============ Diagnostics Admin Page ============ */

/**
 * HIC Diagnostics Admin Page
 */
function hic_diagnostics_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    // Get initial data
    $scheduler_status = hic_get_internal_scheduler_status();
    $credentials_status = hic_get_credentials_status();
    $execution_stats = hic_get_execution_stats();
    $recent_logs = hic_get_recent_log_entries(20);
    $schedules = wp_get_schedules();
    $error_stats = hic_get_error_stats();
    
    // Note: Updates polling and all cron dependencies removed - system uses internal scheduler only
    
    ?>
    <div class="wrap">
        <h1>HIC Plugin Diagnostics</h1>
        
        <div class="hic-diagnostics-container">
            
            <!-- System Status Section -->
            <div class="card">
                <h2>Stato Sistema</h2>
                <p>
                    <button class="button button-secondary" id="refresh-diagnostics">Aggiorna Dati</button>
                    <button class="button" id="force-reschedule">Riavvia Sistema Interno</button>
                    <button class="button" id="create-tables">Crea/Verifica Tabelle DB</button>
                    <button class="button" id="test-dispatch">Test Dispatch Funzioni</button>
                </p>
            </div>
            
            <!-- Backfill Section -->
            <div class="card">
                <h2>Scarico Storico Prenotazioni (Backfill)</h2>
                <p>Usa questa funzione per scaricare prenotazioni di un intervallo temporale specifico. Utile per recuperare dati persi o per il primo setup.</p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="backfill-from-date">Data Inizio</label>
                        </th>
                        <td>
                            <input type="date" id="backfill-from-date" name="backfill_from_date" 
                                   value="<?php echo esc_attr(date('Y-m-d', strtotime('-7 days'))); ?>" />
                            <p class="description">Data di inizio per il recupero prenotazioni</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="backfill-to-date">Data Fine</label>
                        </th>
                        <td>
                            <input type="date" id="backfill-to-date" name="backfill_to_date" 
                                   value="<?php echo esc_attr(date('Y-m-d')); ?>" />
                            <p class="description">Data di fine per il recupero prenotazioni</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="backfill-date-type">Tipo Data</label>
                        </th>
                        <td>
                            <select id="backfill-date-type" name="backfill_date_type">
                                <option value="checkin">Data Check-in</option>
                                <option value="checkout">Data Check-out</option>
                                <option value="presence">Periodo di presenza</option>
                                <option value="created">Data Creazione</option>
                            </select>
                            <p class="description">
                                <strong>Check-in:</strong> Prenotazioni per arrivi in questo periodo<br>
                                <strong>Check-out:</strong> Prenotazioni per partenze in questo periodo<br>
                                <strong>Presenza:</strong> Prenotazioni con soggiorno in questo periodo<br>
                                <strong>Creazione:</strong> Prenotazioni create in questo periodo (usa endpoint /reservations_updates/)
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="backfill-limit">Limite (opzionale)</label>
                        </th>
                        <td>
                            <input type="number" id="backfill-limit" name="backfill_limit" 
                                   min="1" max="1000" placeholder="Nessun limite" />
                            <p class="description">Numero massimo di prenotazioni da recuperare (lascia vuoto per tutte)</p>
                        </td>
                    </tr>
                </table>
                
                <p>
                    <button class="button button-primary" id="start-backfill">
                        <span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
                        Avvia Backfill
                    </button>
                    <span id="backfill-status" style="margin-left: 10px; font-weight: bold;"></span>
                </p>
                
                <div id="backfill-results" style="display: none; margin-top: 15px; padding: 10px; background: #f7f7f7; border-left: 4px solid #0073aa;">
                    <h4>Risultati Backfill:</h4>
                    <div id="backfill-results-content"></div>
                </div>
                
                <div class="notice notice-info inline" style="margin-top: 15px;">
                    <p><strong>Note:</strong></p>
                    <ul>
                        <li>Il backfill elabora solo prenotazioni non già presenti nel sistema</li>
                        <li>L'intervallo massimo consentito è di 6 mesi</li>
                        <li>Le prenotazioni duplicate vengono automaticamente saltate</li>
                        <li>Tutti gli eventi di backfill vengono registrati nei log</li>
                    </ul>
                </div>
            </div>
            
            <!-- Send Latest Bookings to Integrations Section -->
            <div class="card">
                <h2>Invia Ultime Prenotazioni alle Integrazioni</h2>
                <p>Scarica le ultime 5 prenotazioni da Hotel in Cloud e inviale ai sistemi GA4 e Brevo configurati. Le prenotazioni già inviate vengono automaticamente saltate.</p>
                
                <?php 
                $downloaded_ids = hic_get_downloaded_booking_ids();
                $downloaded_count = count($downloaded_ids);
                ?>
                
                <!-- Download Tracking Status -->
                <div class="notice notice-info inline" style="margin-bottom: 15px;">
                    <p><strong>Status Tracking Invii:</strong></p>
                    <ul>
                        <li>Prenotazioni già inviate: <strong><?php echo esc_html($downloaded_count); ?></strong></li>
                        <li>Sistema anti-duplicazione: <span class="status ok">✓ Attivo</span></li>
                        <?php if ($downloaded_count > 0): ?>
                            <li>Ultime ID inviate: <code><?php echo esc_html(implode(', ', array_slice($downloaded_ids, -3))); ?></code></li>
                        <?php endif; ?>
                    </ul>
                </div>
                
                <!-- Integration Status -->
                <div class="notice notice-info inline" style="margin-bottom: 15px;">
                    <p><strong>Integrazioni Configurate:</strong></p>
                    <ul>
                        <li>GA4: 
                            <?php if (!empty(hic_get_measurement_id()) && !empty(hic_get_api_secret())): ?>
                                <span class="status ok">✓ Configurato</span>
                            <?php else: ?>
                                <span class="status error">✗ Non configurato</span>
                            <?php endif; ?>
                        </li>
                        <li>Brevo: 
                            <?php if (hic_is_brevo_enabled() && !empty(hic_get_brevo_api_key())): ?>
                                <span class="status ok">✓ Configurato</span>
                            <?php else: ?>
                                <span class="status error">✗ Non configurato</span>
                            <?php endif; ?>
                        </li>
                        <li>Facebook: 
                            <?php if (!empty(hic_get_fb_pixel_id()) && !empty(hic_get_fb_access_token())): ?>
                                <span class="status ok">✓ Configurato</span>
                            <?php else: ?>
                                <span class="status error">✗ Non configurato</span>
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
                
                <p>
                    <button class="button button-primary" id="download-latest-bookings">
                        <span class="dashicons dashicons-upload" style="margin-top: 3px;"></span>
                        Invia Ultime 5 Prenotazioni a GA4 e Brevo
                    </button>
                    <?php if ($downloaded_count > 0): ?>
                        <button class="button button-secondary" id="reset-download-tracking" style="margin-left: 10px;">
                            <span class="dashicons dashicons-update-alt" style="margin-top: 3px;"></span>
                            Reset Tracking Invii
                        </button>
                    <?php endif; ?>
                    <span id="download-status" style="margin-left: 10px; font-weight: bold;"></span>
                </p>
                
                <div id="download-results" style="display: none; margin-top: 15px; padding: 10px; background: #f7f7f7; border-left: 4px solid #0073aa;">
                    <h4>Risultati Invio:</h4>
                    <div id="download-results-content"></div>
                </div>
                
                <div class="notice notice-info inline" style="margin-top: 15px;">
                    <p><strong>Note:</strong></p>
                    <ul>
                        <li><strong>Anti-duplicazione:</strong> Vengono inviate solo le prenotazioni mai inviate prima</li>
                        <li><strong>Ordinamento:</strong> Le ultime 5 prenotazioni basate sulla data di creazione (più recenti)</li>
                        <li><strong>Tracking automatico:</strong> Il sistema ricorda quali prenotazioni sono state inviate</li>
                        <li><strong>Reset tracking:</strong> Usa il bottone "Reset" per consentire il nuovo invio delle stesse prenotazioni</li>
                        <li><strong>Elaborazione completa:</strong> Include invio a GA4, Brevo, Facebook (se configurati) ed email</li>
                        <li>Richiede connessione API configurata (non funziona in modalità webhook)</li>
                    </ul>
                </div>
            </div>
            
            <!-- Manual Booking Diagnostics Section -->
            <div class="card">
                <h2>Diagnostica Prenotazioni Manuali</h2>
                <?php 
                $connection_type = hic_get_connection_type();
                $webhook_token = hic_get_webhook_token();
                $manual_booking_issues = array();
                
                // Check for manual booking configuration issues
                if ($connection_type === 'webhook') {
                    if (empty($webhook_token)) {
                        $manual_booking_issues[] = array(
                            'type' => 'error',
                            'message' => 'Webhook token non configurato: le prenotazioni manuali potrebbero non essere inviate automaticamente.'
                        );
                    }
                    $manual_booking_issues[] = array(
                        'type' => 'warning', 
                        'message' => 'Modalità Webhook: Hotel in Cloud deve inviare webhook per TUTTE le prenotazioni, incluse quelle manuali.'
                    );
                } elseif ($connection_type === 'api') {
                    if (!$scheduler_status['internal_scheduler']['enabled'] || !$scheduler_status['internal_scheduler']['conditions_met']) {
                        $manual_booking_issues[] = array(
                            'type' => 'error',
                            'message' => 'Sistema di polling interno non attivo: le prenotazioni manuali non verranno recuperate automaticamente.'
                        );
                    } else {
                        $manual_booking_issues[] = array(
                            'type' => 'info',
                            'message' => 'Sistema di polling interno attivo: le prenotazioni manuali vengono recuperate automaticamente ogni 5 minuti.'
                        );
                    }
                }
                ?>
                
                <table class="widefat">
                    <tr>
                        <td>Modalità Connessione</td>
                        <td>
                            <strong><?php echo ucfirst(esc_html($connection_type)); ?></strong>
                            <?php if ($connection_type === 'api'): ?>
                                <span class="status ok">✓ Migliore per prenotazioni manuali</span>
                            <?php else: ?>
                                <span class="status warning">⚠ Dipende dalla configurazione webhook</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <?php if ($connection_type === 'webhook'): ?>
                    <tr>
                        <td>Webhook Token</td>
                        <td>
                            <?php if (!empty($webhook_token)): ?>
                                <span class="status ok">✓ Configurato</span>
                            <?php else: ?>
                                <span class="status error">✗ NON configurato</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>URL Webhook</td>
                        <td>
                            <code><?php 
                            if (!empty($webhook_token)) {
                                echo esc_url(home_url('/wp-json/hic/v1/conversion?token=***')); 
                            } else {
                                echo esc_url(home_url('/wp-json/hic/v1/conversion?token=CONFIGURA_TOKEN_PRIMA'));
                            }
                            ?></code>
                            <?php if (empty($webhook_token)): ?>
                                <br><small style="color: #dc3232;">⚠ Configura il token nelle impostazioni prima di usare questo URL</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php if ($connection_type === 'api'): ?>
                    <tr>
                        <td>Sistema Polling</td>
                        <td>
                            <?php if ($scheduler_status['internal_scheduler']['enabled'] && $scheduler_status['internal_scheduler']['conditions_met']): ?>
                                <span class="status ok">
                                    <?php 
                                    echo 'Attivo (Heartbeat API)';
                                    if ($scheduler_status['internal_scheduler']['last_poll_human'] !== 'Mai eseguito') {
                                        echo ' - Ultimo: ' . esc_html($scheduler_status['internal_scheduler']['last_poll_human']);
                                    }
                                    ?>
                                </span>
                            <?php else: ?>
                                <span class="status error">Non attivo</span>
                                <br><small style="color: #dc3232;">
                                    <?php
                                    $polling_issues = array();
                                    if (!hic_reliable_polling_enabled()) {
                                        $polling_issues[] = "Polling affidabile disabilitato nelle impostazioni";
                                    }
                                    if (hic_get_connection_type() !== 'api') {
                                        $polling_issues[] = "Tipo connessione non è 'API Polling' (attuale: " . hic_get_connection_type() . ")";
                                    }
                                    if (!hic_get_api_url()) {
                                        $polling_issues[] = "URL API non configurato";
                                    }
                                    if (!hic_has_basic_auth_credentials() && !hic_get_api_key()) {
                                        $polling_issues[] = "Credenziali API mancanti (serve Property ID + Email + Password oppure API Key)";
                                    }
                                    echo implode('<br>', $polling_issues);
                                    ?>
                                </small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
                
                <?php if (!empty($manual_booking_issues)): ?>
                <div class="manual-booking-alerts">
                    <h3>Avvisi e Raccomandazioni</h3>
                    <?php foreach ($manual_booking_issues as $issue): ?>
                        <div class="notice notice-<?php echo esc_attr($issue['type'] === 'error' ? 'error' : ($issue['type'] === 'warning' ? 'warning' : 'info')); ?> inline">
                            <p><?php echo esc_html($issue['message']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <div class="manual-booking-recommendations">
                    <h3>Raccomandazioni per Prenotazioni Manuali</h3>
                    <ul>
                        <?php if ($connection_type === 'webhook'): ?>
                            <li><strong>Verifica configurazione Hotel in Cloud:</strong> Assicurati che i webhook siano configurati per inviare TUTTE le prenotazioni</li>
                            <li><strong>Considera API Polling:</strong> Per maggiore affidabilità, valuta il passaggio alla modalità "API Polling"</li>
                            <li><strong>Test webhook:</strong> Usa il pulsante "Test Dispatch Funzioni" per verificare che le integrazioni funzionino</li>
                        <?php else: ?>
                            <li><strong>Modalità consigliata:</strong> API Polling è la modalità migliore per catturare automaticamente le prenotazioni manuali</li>
                            <li><strong>Frequenza polling:</strong> Il sistema utilizza Heartbeat API per controllare nuove prenotazioni ogni 60 secondi (indipendente dal traffico)</li>
                            <li><strong>Verifica credenziali:</strong> Assicurati che le credenziali API siano corrette</li>
                        <?php endif; ?>
                        <li><strong>Monitoraggio log:</strong> Controlla la sezione "Log Recenti" per errori o problemi</li>
                    </ul>
                </div>
                
                <?php if ($connection_type === 'api' && (!$scheduler_status['internal_scheduler']['enabled'] || !$scheduler_status['internal_scheduler']['conditions_met'])): ?>
                <div class="polling-troubleshoot">
                    <h3 style="color: #d63638;">⚠ Risoluzione Problemi Polling</h3>
                    <div class="notice notice-error inline">
                        <p><strong>Il sistema di polling non è attivo!</strong> Per far funzionare il monitoraggio automatico:</p>
                        <ol>
                            <li><strong>Verifica tipo connessione:</strong> Vai su <em>Impostazioni → HIC Monitoring</em> e assicurati che "Tipo Connessione" sia impostato su "<strong>API Polling</strong>"</li>
                            <li><strong>Configura credenziali API:</strong> Inserisci i seguenti dati nelle impostazioni:
                                <ul>
                                    <li>API URL (fornito da Hotel in Cloud)</li>
                                    <li>ID Struttura (Property ID)</li>
                                    <li>Email e Password API <em>oppure</em> API Key</li>
                                </ul>
                            </li>
                            <li><strong>Abilita Polling Affidabile:</strong> Assicurati che l'opzione "Polling Affidabile" sia attivata</li>
                            <li><strong>Test connessione:</strong> Usa il pulsante "Test Connessione API" per verificare che tutto funzioni</li>
                        </ol>
                        <p><strong>Nota:</strong> Senza queste configurazioni, il contatore rimarrà sempre a 0 perché il sistema non può scaricare le prenotazioni da Hotel in Cloud.</p>
                        <p><strong>Scheduler Interno Heartbeat:</strong> Il sistema utilizza lo scheduler interno di WordPress (Heartbeat API) che si attiva automaticamente ogni 60 secondi quando le condizioni sono soddisfatte. Non è necessario configurare WordPress Cron o dipendere da traffico del sito.</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- System Configuration Section -->
            <div class="card">
                <h2>Configurazione Sistema</h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Parametro</th>
                            <th>Valore</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Tipo Connessione</td>
                            <td><strong><?php echo esc_html(hic_get_connection_type()); ?></strong></td>
                            <td>Modalità di comunicazione con Hotel in Cloud</td>
                        </tr>
                        <tr>
                            <td>Sistema Polling Interno</td>
                            <td>
                                <?php if (hic_reliable_polling_enabled()): ?>
                                    <span class="status ok">✓ Abilitato</span>
                                <?php else: ?>
                                    <span class="status warning">⚠ Disabilitato</span>
                                <?php endif; ?>
                            </td>
                            <td>Sistema di polling interno con Heartbeat API</td>
                        </tr>
                        <tr>
                            <td>API URL</td>
                            <td><span class="status <?php echo esc_attr($credentials_status['api_url'] ? 'ok' : 'error'); ?>">
                                <?php echo esc_html($credentials_status['api_url'] ? 'Configurato' : 'Mancante'); ?>
                            </span></td>
                            <td>URL base per le chiamate API</td>
                        </tr>
                        <tr>
                            <td>Property ID</td>
                            <td><span class="status <?php echo esc_attr($credentials_status['property_id'] ? 'ok' : 'error'); ?>">
                                <?php echo esc_html($credentials_status['property_id'] ? 'Configurato' : 'Mancante'); ?>
                            </span></td>
                            <td>ID della struttura alberghiera</td>
                        </tr>
                        <tr>
                            <td>Credenziali API</td>
                            <td><span class="status <?php echo esc_attr($credentials_status['api_email'] && $credentials_status['api_password'] ? 'ok' : 'error'); ?>">
                                <?php echo esc_html($credentials_status['api_email'] && $credentials_status['api_password'] ? 'Configurate' : 'Mancanti'); ?>
                            </span></td>
                            <td>Email e password per autenticazione API</td>
                        </tr>
                    </tbody>
                </table>
                
                <h3>Integrazioni Configurate</h3>
                <table class="widefat">
                    <tbody>
                        <tr>
                            <td>GA4</td>
                            <td><span class="status <?php echo esc_attr($credentials_status['ga4_configured'] ? 'ok' : 'error'); ?>">
                                <?php echo esc_html($credentials_status['ga4_configured'] ? '✓ Configurato' : '✗ Non configurato'); ?>
                            </span></td>
                        </tr>
                        <tr>
                            <td>Brevo</td>
                            <td><span class="status <?php echo esc_attr($credentials_status['brevo_configured'] ? 'ok' : 'error'); ?>">
                                <?php echo esc_html($credentials_status['brevo_configured'] ? '✓ Configurato' : '✗ Non configurato'); ?>
                            </span></td>
                        </tr>
                        <tr>
                            <td>Facebook</td>
                            <td><span class="status <?php echo esc_attr($credentials_status['facebook_configured'] ? 'ok' : 'error'); ?>">
                                <?php echo esc_html($credentials_status['facebook_configured'] ? '✓ Configurato' : '✗ Non configurato'); ?>
                            </span></td>
                        </tr>
                    </tbody>
                </table>
                
                <h3>Real-time Sync</h3>
                <table class="widefat">
                    <tbody>
                        <tr>
                            <td>Real-time Sync Brevo</td>
                            <td>
                                <?php if (hic_realtime_brevo_sync_enabled()): ?>
                                    <span class="status ok">✓ Abilitato</span>
                                <?php else: ?>
                                    <span class="status error">✗ Disabilitato</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Updates Enrichment</td>
                            <td>
                                <?php if (hic_updates_enrich_contacts()): ?>
                                    <span class="status ok">✓ Abilitato</span>
                                <?php else: ?>
                                    <span class="status error">✗ Disabilitato</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (isset($scheduler_status['realtime_sync']['table_exists']) && $scheduler_status['realtime_sync']['table_exists'] !== false): ?>
                        <tr>
                            <td>Prenotazioni Tracciate</td>
                            <td><?php echo isset($scheduler_status['realtime_sync']['total_tracked']) ? intval($scheduler_status['realtime_sync']['total_tracked']) : '0'; ?></td>
                        </tr>
                        <tr>
                            <td>Sync Riuscite / Fallite</td>
                            <td>
                                <?php 
                                $notified = isset($scheduler_status['realtime_sync']['notified']) ? intval($scheduler_status['realtime_sync']['notified']) : 0;
                                $failed = isset($scheduler_status['realtime_sync']['failed']) ? intval($scheduler_status['realtime_sync']['failed']) : 0;
                                ?>
                                <span class="status ok"><?php echo $notified; ?></span> / 
                                <span class="status <?php echo $failed > 0 ? 'error' : 'ok'; ?>"><?php echo $failed; ?></span>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            

            <!-- Execution Stats Section -->
            <div class="card">
                <h2>Statistiche Esecuzione</h2>
                <table class="widefat" id="hic-execution-stats">
                    <tr>
                        <td>Ultimo Polling</td>
                        <td><?php echo $execution_stats['last_poll_time'] ? esc_html(date('Y-m-d H:i:s', $execution_stats['last_poll_time'])) : 'Mai'; ?></td>
                    </tr>
                    <tr>
                        <td>Ultimo Updates Polling</td>
                        <td><?php echo $execution_stats['last_updates_time'] ? esc_html(date('Y-m-d H:i:s', $execution_stats['last_updates_time'])) : 'Mai'; ?></td>
                    </tr>
                    <tr>
                        <td>Prenotazioni Elaborate</td>
                        <td><?php echo esc_html(number_format($execution_stats['processed_reservations'])); ?></td>
                    </tr>
                    <tr>
                        <td>Ultimo Polling - Prenotazioni Trovate</td>
                        <td>
                            <?php 
                            $last_count = $execution_stats['last_poll_reservations_found'];
                            if ($last_count > 0) {
                                echo '<span class="status ok">' . esc_html(number_format($last_count)) . '</span>';
                            } else {
                                echo '<span class="status warning">0</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Ultimo Polling - Prenotazioni Saltate</td>
                        <td><?php echo esc_html(number_format($execution_stats['last_poll_skipped'])); ?></td>
                    </tr>
                    <tr>
                        <td>Ultimo Polling - Durata</td>
                        <td>
                            <?php 
                            $duration = $execution_stats['last_poll_duration'];
                            if ($duration > 0) {
                                echo '<span class="status ' . ($duration > 10000 ? 'warning' : 'ok') . '">' . esc_html($duration) . ' ms</span>';
                            } else {
                                echo '<span class="status neutral">N/A</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Ultimo Polling Riuscito</td>
                        <td>
                            <?php 
                            $last_successful = $execution_stats['last_successful_poll'];
                            if ($last_successful > 0) {
                                $time_diff = time() - $last_successful;
                                if ($time_diff < 300) { // Less than 5 minutes
                                    echo '<span class="status ok">' . esc_html(human_time_diff($last_successful, time())) . ' fa</span>';
                                } elseif ($time_diff < 3600) { // Less than 1 hour
                                    echo '<span class="status warning">' . esc_html(human_time_diff($last_successful, time())) . ' fa</span>';
                                } else {
                                    echo '<span class="status error">' . esc_html(human_time_diff($last_successful, time())) . ' fa</span>';
                                }
                            } else {
                                echo '<span class="status error">Mai</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Email Arricchite</td>
                        <td><?php echo esc_html(number_format($execution_stats['enriched_emails'])); ?></td>
                    </tr>
                    <tr>
                        <td>File di Log</td>
                        <td><?php echo $execution_stats['log_file_exists'] ? esc_html('Esiste (' . size_format($execution_stats['log_file_size']) . ')') : 'Non trovato'; ?></td>
                    </tr>
                </table>
            </div>
            
            <!-- Reliable Polling Diagnostics -->
            <div class="card">
                <h2>Sistema Polling Affidabile</h2>
                <?php 
                $reliable_stats = array();
                if (class_exists('HIC_Booking_Poller')) {
                    $poller = new HIC_Booking_Poller();
                    $reliable_stats = $poller->get_stats();
                }
                ?>
                
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Parametro</th>
                            <th>Stato</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Scheduler Interno</td>
                            <td>
                                <?php if ($scheduler_status['internal_scheduler']['enabled'] && $scheduler_status['internal_scheduler']['conditions_met']): ?>
                                    <span class="status ok">✓ Attivo</span><br>
                                    <small>
                                        Ultimo polling: <?php echo esc_html($scheduler_status['internal_scheduler']['last_poll_human']); ?><br>
                                        Prossimo polling: <?php echo esc_html($scheduler_status['internal_scheduler']['next_run_human']); ?>
                                    </small>
                                <?php else: ?>
                                    <span class="status error">✗ Non attivo</span><br>
                                    <small>Verificare configurazione API</small>
                                <?php endif; ?>
                            </td>
                            <td>Scheduler interno che utilizza WordPress Heartbeat API</td>
                        </tr>
                    </tbody>
                </table>
                
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Parametro</th>
                            <th>Valore</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Sistema Attivo</td>
                            <td>
                                <?php if (class_exists('HIC_Booking_Poller')): ?>
                                    <span class="status ok">✓ Attivo</span>
                                <?php else: ?>
                                    <span class="status error">✗ Non Caricato</span>
                                <?php endif; ?>
                            </td>
                            <td>Sistema di polling interno con Heartbeat API</td>
                        </tr>
                        
                        <tr>
                            <td>Tabella Queue</td>
                            <td>
                                <?php 
                                global $wpdb;
                                $queue_table = $wpdb->prefix . 'hic_booking_events';
                                $queue_exists = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'") === $queue_table;
                                ?>
                                <?php if ($queue_exists): ?>
                                    <span class="status ok">✓ Trovata</span>
                                <?php else: ?>
                                    <span class="status error">✗ Queue table not found</span>
                                <?php endif; ?>
                            </td>
                            <td>Tabella per la gestione degli eventi e deduplicazione</td>
                        </tr>
                        
                        <?php if (!empty($reliable_stats) && !isset($reliable_stats['error'])): ?>
                        <tr>
                            <td>Ultimo Polling</td>
                            <td>
                                <?php if ($reliable_stats['last_poll'] > 0): ?>
                                    <span class="status ok"><?php echo esc_html(date('Y-m-d H:i:s', $reliable_stats['last_poll'])); ?></span><br>
                                    <small><?php echo esc_html($reliable_stats['last_poll_human']); ?></small>
                                <?php else: ?>
                                    <span class="status warning">Mai</span>
                                <?php endif; ?>
                            </td>
                            <td>Ultimo tentativo di polling eseguito</td>
                        </tr>
                        
                        <tr>
                            <td>Lag Polling</td>
                            <td>
                                <?php 
                                $lag = $reliable_stats['lag_seconds'];
                                if ($lag < 600): // Less than 10 minutes ?>
                                    <span class="status ok"><?php echo esc_html($lag); ?> secondi</span>
                                <?php elseif ($lag < 1800): // Less than 30 minutes ?>
                                    <span class="status warning"><?php echo esc_html($lag); ?> secondi</span>
                                <?php else: ?>
                                    <span class="status error"><?php echo esc_html($lag); ?> secondi</span>
                                <?php endif; ?>
                            </td>
                            <td>Tempo trascorso dall'ultimo polling (watchdog attivo oltre 15 min)</td>
                        </tr>
                        
                        <tr>
                            <td>Lock Attivo</td>
                            <td>
                                <?php if ($reliable_stats['lock_active']): ?>
                                    <span class="status warning">🔒 Attivo</span>
                                    <?php if (isset($reliable_stats['lock_age'])): ?>
                                        <br><small>Da <?php echo esc_html($reliable_stats['lock_age']); ?> secondi</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="status ok">🔓 Libero</span>
                                <?php endif; ?>
                            </td>
                            <td>Lock TTL anti-overlap (max 4 minuti)</td>
                        </tr>
                        
                        <tr>
                            <td>Eventi in Coda</td>
                            <td>
                                <span class="status <?php echo $reliable_stats['total_events'] > 0 ? 'ok' : 'warning'; ?>">
                                    <?php echo esc_html(number_format($reliable_stats['total_events'])); ?>
                                </span>
                            </td>
                            <td>Totale eventi prenotazioni nella tabella queue</td>
                        </tr>
                        
                        <tr>
                            <td>Eventi Processati</td>
                            <td>
                                <span class="status ok">
                                    <?php echo esc_html(number_format($reliable_stats['processed_events'])); ?>
                                </span>
                            </td>
                            <td>Eventi elaborati con successo</td>
                        </tr>
                        
                        <tr>
                            <td>Eventi in Attesa</td>
                            <td>
                                <span class="status <?php echo $reliable_stats['pending_events'] > 0 ? 'warning' : 'ok'; ?>">
                                    <?php echo esc_html(number_format($reliable_stats['pending_events'])); ?>
                                </span>
                            </td>
                            <td>Eventi non ancora processati</td>
                        </tr>
                        
                        <tr>
                            <td>Eventi con Errore</td>
                            <td>
                                <span class="status <?php echo $reliable_stats['error_events'] > 0 ? 'error' : 'ok'; ?>">
                                    <?php echo esc_html(number_format($reliable_stats['error_events'])); ?>
                                </span>
                            </td>
                            <td>Eventi con errori di processamento</td>
                        </tr>
                        
                        <tr>
                            <td>Attività 24h</td>
                            <td>
                                <span class="status <?php echo $reliable_stats['events_24h'] > 0 ? 'ok' : 'warning'; ?>">
                                    <?php echo esc_html(number_format($reliable_stats['events_24h'])); ?>
                                </span>
                            </td>
                            <td>Eventi ricevuti nelle ultime 24 ore</td>
                        </tr>
                        
                        <?php else: ?>
                        <tr>
                            <td colspan="3">
                                <span class="status error">
                                    <?php if (isset($reliable_stats['error'])): ?>
                                        Errore: <?php echo esc_html($reliable_stats['error']); ?>
                                    <?php else: ?>
                                        Statistiche non disponibili
                                    <?php endif; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Error Summary Section -->
            <div class="card">
                <h2>Riepilogo Errori</h2>
                <table class="widefat">
                    <tr>
                        <td>Errori Recenti (ultimi log)</td>
                        <td><span class="status <?php echo esc_attr($error_stats['error_count'] > 0 ? 'error' : 'ok'); ?>">
                            <?php echo esc_html(number_format($error_stats['error_count'])); ?>
                        </span></td>
                    </tr>
                    <?php if ($error_stats['last_error']): ?>
                    <tr>
                        <td>Ultimo Errore</td>
                        <td><small><?php echo esc_html($error_stats['last_error']); ?></small></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            
            <!-- Recent Logs Section -->
            <div class="card">
                <h2>Log Recenti (Errori e Eventi Importanti)</h2>
                <div id="hic-recent-logs" style="max-height: 300px; overflow-y: auto; background: #f9f9f9; padding: 10px; font-family: monospace; font-size: 12px;">
                    <?php if (empty($recent_logs)): ?>
                        <p>Nessun log recente trovato.</p>
                    <?php else: ?>
                        <?php foreach ($recent_logs as $log_line): ?>
                            <div><?php echo esc_html($log_line); ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
        </div>
        
        <!-- Test Results -->
        <div id="hic-test-results" style="margin-top: 20px;"></div>
    </div>
    
    <style>
        /* Fix width issues - ensure full width usage */
        .wrap {
            max-width: none !important;
            margin: 0 20px 0 0;
            width: calc(100% - 20px) !important;
        }
        
        .hic-diagnostics-container {
            max-width: none;
            width: 100%;
            margin: 0;
            padding: 0;
        }
        
        .hic-diagnostics-container .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            margin-bottom: 20px;
            padding: 20px;
            width: 100%;
            box-sizing: border-box;
        }
        
        .hic-diagnostics-container .card h2 {
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            font-size: 18px;
        }
        
        .hic-diagnostics-container .card h3 {
            margin-top: 20px;
            margin-bottom: 15px;
            font-size: 16px;
            color: #1d2327;
        }
        
        .hic-diagnostics-container .widefat {
            width: 100%;
            max-width: none;
            margin-bottom: 15px;
            table-layout: fixed;
        }
        
        .hic-diagnostics-container .widefat td,
        .hic-diagnostics-container .widefat th {
            padding: 12px 15px;
            vertical-align: top;
            word-wrap: break-word;
        }
        
        .hic-diagnostics-container .form-table {
            width: 100%;
            max-width: none;
        }
        
        .hic-diagnostics-container .form-table th {
            width: 200px;
            padding: 15px 10px 15px 0;
        }
        
        .hic-diagnostics-container .form-table td {
            padding: 15px 10px;
        }
        
        .status.ok { color: #46b450; font-weight: bold; }
        .status.error { color: #dc3232; font-weight: bold; }
        .status.warning { color: #ffb900; font-weight: bold; }
        .status.neutral { color: #666; font-weight: normal; }
        
        .manual-booking-alerts {
            margin-top: 15px;
        }
        .manual-booking-alerts h3 {
            margin-bottom: 10px;
            font-size: 14px;
            font-weight: 600;
        }
        .manual-booking-recommendations {
            margin-top: 15px;
            padding: 15px;
            background: #f7f7f7;
            border-left: 4px solid #0073aa;
        }
        .manual-booking-recommendations h3 {
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 14px;
            font-weight: 600;
        }
        .manual-booking-recommendations ul {
            margin: 0;
            padding-left: 20px;
        }
        .manual-booking-recommendations li {
            margin-bottom: 8px;
        }
        
        #hic-recent-logs { 
            max-height: 300px; 
            overflow-y: auto; 
            background: #f9f9f9; 
            padding: 15px; 
            font-family: 'Courier New', Courier, monospace; 
            font-size: 12px;
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        #hic-recent-logs div { 
            margin-bottom: 3px; 
            padding: 2px 0;
            border-bottom: 1px solid #eee;
        }
        
        .button {
            margin-right: 10px;
            margin-bottom: 5px;
        }
        
        /* Better responsive design */
        @media (max-width: 1200px) {
            .wrap {
                width: calc(100% - 10px) !important;
                margin-right: 10px;
            }
        }
        
        /* Responsive improvements */
        @media (max-width: 782px) {
            .wrap {
                width: 100% !important;
                margin-right: 0;
            }
            
            .hic-diagnostics-container .card {
                padding: 15px;
                margin-bottom: 15px;
            }
            
            .hic-diagnostics-container .widefat {
                font-size: 14px;
            }
            
            .hic-diagnostics-container .widefat td,
            .hic-diagnostics-container .widefat th {
                padding: 8px 10px;
            }
            
            .hic-diagnostics-container .form-table th {
                width: 150px;
            }
        }
        
        /* Ensure tables don't overflow and use full width */
        .hic-diagnostics-container table {
            table-layout: fixed;
            width: 100%;
            word-wrap: break-word;
        }
        
        .hic-diagnostics-container code {
            word-break: break-all;
            background: #f1f1f1;
            padding: 2px 4px;
            border-radius: 3px;
        }
        
        /* Better styling for notices */
        .notice.inline {
            margin: 15px 0;
            padding: 12px;
        }
    </style>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Refresh diagnostics handler
        $('#refresh-diagnostics').click(function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Aggiornando...');
            
            $.post(ajaxurl, {
                action: 'hic_refresh_diagnostics',
                nonce: '<?php echo wp_create_nonce('hic_diagnostics_nonce'); ?>'
            }, function(response) {
                var result = JSON.parse(response);
                if (result.success) {
                    location.reload(); // Simple refresh for now
                } else {
                    alert('Errore nell\'aggiornamento dati');
                }
                $btn.prop('disabled', false).text('Aggiorna Dati');
            });
        });
        
        // Create tables handler (for fixing "Queue table not found" errors)
        $('#create-tables').click(function() {
            var $btn = $(this);
            var $results = $('#hic-test-results');
            
            if (!confirm('Vuoi creare/verificare le tabelle del database? Questa operazione è sicura e non cancella dati esistenti.')) {
                return;
            }
            
            $btn.prop('disabled', true).text('Creando tabelle...');
            
            $.post(ajaxurl, {
                action: 'hic_create_tables',
                nonce: '<?php echo wp_create_nonce('hic_diagnostics_nonce'); ?>'
            }, function(response) {
                var result = JSON.parse(response);
                var messageClass = result.success ? 'notice-success' : 'notice-error';
                var html = '<div class="notice ' + messageClass + ' inline"><p><strong>Creazione Tabelle:</strong><br>';
                
                if (result.success) {
                    html += result.message;
                    if (result.details) {
                        html += '<br><em>Dettagli: ' + result.details + '</em>';
                    }
                } else {
                    html += 'Errore: ' + (result.message || 'Unknown error');
                }
                
                html += '</p></div>';
                $results.html(html);
                $btn.prop('disabled', false).text('Crea/Verifica Tabelle DB');
                
                // Refresh page after 3 seconds on success
                if (result.success) {
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                }
            });
        });
        
        // Force reschedule handler
        $('#force-reschedule').click(function() {
            var $btn = $(this);
            var $results = $('#hic-test-results');
            
            if (!confirm('Vuoi forzare la rischedulazione dei cron jobs?')) {
                return;
            }
            
            $btn.prop('disabled', true).text('Rischedulando...');
            
            $.post(ajaxurl, {
                action: 'hic_force_reschedule',
                nonce: '<?php echo wp_create_nonce('hic_diagnostics_nonce'); ?>'
            }, function(response) {
                var result = JSON.parse(response);
                var html = '<div class="notice notice-info inline"><p><strong>Risultati Rischedulazione:</strong><br>';
                
                if (result.success) {
                    Object.keys(result.results).forEach(function(key) {
                        html += key + ': ' + result.results[key] + '<br>';
                    });
                } else {
                    html += 'Errore: ' + (result.message || 'Unknown error');
                }
                
                html += '</p></div>';
                $results.html(html);
                $btn.prop('disabled', false).text('Riavvia Sistema Interno');
                
                // Refresh page after 2 seconds
                setTimeout(function() {
                    location.reload();
                }, 2000);
            });
        });
        
        // Test dispatch handler
        $('#test-dispatch').click(function() {
            var $btn = $(this);
            var $results = $('#hic-test-results');
            
            if (!confirm('Vuoi testare le funzioni di dispatch con dati di esempio?')) {
                return;
            }
            
            $btn.prop('disabled', true).text('Testando...');
            
            $.post(ajaxurl, {
                action: 'hic_test_dispatch',
                nonce: '<?php echo wp_create_nonce('hic_diagnostics_nonce'); ?>'
            }, function(response) {
                var result = JSON.parse(response);
                var messageClass = result.success ? 'notice-success' : 'notice-error';
                var html = '<div class="notice ' + messageClass + ' inline"><p><strong>Test Dispatch:</strong><br>';
                
                if (result.success) {
                    Object.keys(result.results).forEach(function(key) {
                        html += key.toUpperCase() + ': ' + result.results[key] + '<br>';
                    });
                    html += '<br><em>Controlla i log per i dettagli.</em>';
                } else {
                    html += 'Errore: ' + (result.message || 'Unknown error');
                }
                
                html += '</p></div>';
                $results.html(html);
                $btn.prop('disabled', false).text('Test Dispatch Funzioni');
            });
        });
        
        // Backfill handler
        $('#start-backfill').click(function() {
            var $btn = $(this);
            var $status = $('#backfill-status');
            var $results = $('#backfill-results');
            var $resultsContent = $('#backfill-results-content');
            
            // Get form values
            var fromDate = $('#backfill-from-date').val();
            var toDate = $('#backfill-to-date').val();
            var dateType = $('#backfill-date-type').val();
            var limit = $('#backfill-limit').val();
            
            // Validate form
            if (!fromDate || !toDate) {
                alert('Inserisci entrambe le date di inizio e fine.');
                return;
            }
            
            if (new Date(fromDate) > new Date(toDate)) {
                alert('La data di inizio deve essere precedente alla data di fine.');
                return;
            }
            
            // Confirmation
            var message = 'Vuoi avviare il backfill delle prenotazioni dal ' + fromDate + ' al ' + toDate + '?';
            if (limit) {
                message += '\nLimite: ' + limit + ' prenotazioni';
            }
            message += '\nTipo data: ' + (dateType === 'checkin' ? 'Check-in' : dateType === 'checkout' ? 'Check-out' : dateType === 'presence' ? 'Presenza' : 'Creazione');
            
            if (!confirm(message)) {
                return;
            }
            
            // Start backfill
            $btn.prop('disabled', true);
            $status.text('Avviando backfill...').css('color', '#0073aa');
            $results.hide();
            
            var postData = {
                action: 'hic_backfill_reservations',
                nonce: '<?php echo wp_create_nonce('hic_diagnostics_nonce'); ?>',
                from_date: fromDate,
                to_date: toDate,
                date_type: dateType
            };
            
            if (limit) {
                postData.limit = parseInt(limit);
            }
            
            $.post(ajaxurl, postData, function(response) {
                var result = JSON.parse(response);
                
                if (result.success) {
                    $status.text('Backfill completato!').css('color', '#46b450');
                    
                    var stats = result.stats;
                    var html = '<p><strong>' + result.message + '</strong></p>' +
                              '<ul>' +
                              '<li>Prenotazioni trovate: <strong>' + stats.total_found + '</strong></li>' +
                              '<li>Prenotazioni processate: <strong>' + stats.total_processed + '</strong></li>' +
                              '<li>Prenotazioni saltate: <strong>' + stats.total_skipped + '</strong></li>' +
                              '<li>Errori: <strong>' + stats.total_errors + '</strong></li>' +
                              '<li>Tempo di esecuzione: <strong>' + stats.execution_time + ' secondi</strong></li>' +
                              '<li>Intervallo date: <strong>' + stats.date_range + '</strong></li>' +
                              '<li>Tipo data: <strong>' + stats.date_type + '</strong></li>' +
                              '</ul>';
                    
                    $resultsContent.html(html);
                    $results.show();
                    
                } else {
                    $status.text('Errore durante il backfill').css('color', '#dc3232');
                    
                    var html = '<p><strong>Errore:</strong> ' + result.message + '</p>';
                    if (result.stats && Object.keys(result.stats).length > 0) {
                        html += '<p><strong>Statistiche parziali:</strong></p><ul>';
                        Object.keys(result.stats).forEach(function(key) {
                            if (result.stats[key] !== null && result.stats[key] !== '') {
                                html += '<li>' + key + ': ' + result.stats[key] + '</li>';
                            }
                        });
                        html += '</ul>';
                    }
                    
                    $resultsContent.html(html);
                    $results.show();
                }
                
                $btn.prop('disabled', false);
                
            }).fail(function() {
                $status.text('Errore di comunicazione con il server').css('color', '#dc3232');
                $btn.prop('disabled', false);
            });
        });
        
        // Download latest bookings handler (now sends to integrations)
        $('#download-latest-bookings').click(function() {
            var $btn = $(this);
            var $status = $('#download-status');
            var $results = $('#download-results');
            var $resultsContent = $('#download-results-content');
            
            // Validate API configuration
            <?php if (hic_get_connection_type() !== 'api'): ?>
            alert('Questa funzione richiede la modalità API. Il sistema è configurato per webhook.');
            return;
            <?php endif; ?>
            
            <?php if (!hic_has_basic_auth_credentials() && !hic_get_api_key()): ?>
            alert('Credenziali API non configurate. Verifica le impostazioni.');
            return;
            <?php endif; ?>
            
            <?php if (!hic_get_property_id()): ?>
            alert('Property ID non configurato. Verifica le impostazioni.');
            return;
            <?php endif; ?>
            
            // Confirmation
            if (!confirm('Vuoi scaricare le ultime 5 prenotazioni da HIC e inviarle alle integrazioni configurate (GA4, Brevo, etc.)?')) {
                return;
            }
            
            // Start process
            $btn.prop('disabled', true);
            $status.text('Scaricando e inviando prenotazioni...').css('color', '#0073aa');
            $results.hide();
            
            $.post(ajaxurl, {
                action: 'hic_download_latest_bookings',
                nonce: '<?php echo wp_create_nonce('hic_diagnostics_nonce'); ?>'
            }, function(response) {
                var result = JSON.parse(response);
                
                if (result.success) {
                    $status.text('Invio completato!').css('color', '#46b450');
                    
                    // Build results HTML
                    var html = '<p><strong>' + result.message + '</strong></p>' +
                              '<ul>' +
                              '<li>Prenotazioni elaborate: <strong>' + result.count + '</strong></li>' +
                              '<li>Invii riusciti: <strong class="status ok">' + result.success_count + '</strong></li>' +
                              '<li>Invii falliti: <strong class="status ' + (result.error_count > 0 ? 'error' : 'ok') + '">' + result.error_count + '</strong></li>' +
                              '</ul>';
                    
                    // Add integration status
                    html += '<h4>Integrazioni Attive:</h4><ul>';
                    if (result.integration_status.ga4_configured) {
                        html += '<li><span class="status ok">✓ GA4</span> - Eventi inviati</li>';
                    } else {
                        html += '<li><span class="status error">✗ GA4</span> - Non configurato</li>';
                    }
                    if (result.integration_status.brevo_configured) {
                        html += '<li><span class="status ok">✓ Brevo</span> - Contatti ed eventi inviati</li>';
                    } else {
                        html += '<li><span class="status error">✗ Brevo</span> - Non configurato</li>';
                    }
                    if (result.integration_status.facebook_configured) {
                        html += '<li><span class="status ok">✓ Facebook</span> - Eventi inviati</li>';
                    } else {
                        html += '<li><span class="status error">✗ Facebook</span> - Non configurato</li>';
                    }
                    html += '<li><span class="status ok">✓ Email</span> - Notifiche admin inviate</li>';
                    html += '</ul>';
                    
                    // Add booking details if available
                    if (result.processing_results && result.processing_results.length > 0) {
                        html += '<h4>Dettaglio Prenotazioni Elaborate:</h4>';
                        html += '<table style="width: 100%; border-collapse: collapse;">';
                        html += '<tr style="background: #f1f1f1; font-weight: bold;"><th style="padding: 8px; border: 1px solid #ddd;">ID</th><th style="padding: 8px; border: 1px solid #ddd;">Email</th><th style="padding: 8px; border: 1px solid #ddd;">Importo</th><th style="padding: 8px; border: 1px solid #ddd;">Stato</th></tr>';
                        
                        result.processing_results.forEach(function(booking) {
                            var statusIcon = booking.success ? '<span class="status ok">✓</span>' : '<span class="status error">✗</span>';
                            html += '<tr>' +
                                   '<td style="padding: 8px; border: 1px solid #ddd;">' + booking.booking_id + '</td>' +
                                   '<td style="padding: 8px; border: 1px solid #ddd;">' + booking.email + '</td>' +
                                   '<td style="padding: 8px; border: 1px solid #ddd;">' + booking.amount + '</td>' +
                                   '<td style="padding: 8px; border: 1px solid #ddd;">' + statusIcon + '</td>' +
                                   '</tr>';
                        });
                        html += '</table>';
                    }
                    
                    $resultsContent.html(html);
                    $results.show();
                    
                } else {
                    $status.text('Errore durante l\'invio').css('color', '#dc3232');
                    if (result.already_downloaded) {
                        // Special handling for already downloaded message
                        alert(result.message);
                    } else {
                        alert('Errore: ' + result.message);
                    }
                }
                
                $btn.prop('disabled', false);
                
            }).fail(function() {
                $status.text('Errore di comunicazione con il server').css('color', '#dc3232');
                $btn.prop('disabled', false);
            });
        });
        
        // Reset download tracking handler (now tracks sending to integrations)
        $('#reset-download-tracking').click(function() {
            var $btn = $(this);
            var $status = $('#download-status');
            
            if (!confirm('Vuoi resettare il tracking degli invii? Dopo il reset potrai inviare nuovamente tutte le prenotazioni alle integrazioni.')) {
                return;
            }
            
            $btn.prop('disabled', true);
            $status.text('Resettando tracking...').css('color', '#0073aa');
            
            $.post(ajaxurl, {
                action: 'hic_reset_download_tracking',
                nonce: '<?php echo wp_create_nonce('hic_diagnostics_nonce'); ?>'
            }, function(response) {
                var result = JSON.parse(response);
                
                if (result.success) {
                    $status.text('Tracking resettato!').css('color', '#46b450');
                    
                    // Refresh the page after 2 seconds to update the UI
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                    
                } else {
                    $status.text('Errore durante il reset').css('color', '#dc3232');
                    alert('Errore: ' + result.message);
                }
                
                $btn.prop('disabled', false);
                
            }).fail(function() {
                $status.text('Errore di comunicazione con il server').css('color', '#dc3232');
                $btn.prop('disabled', false);
            });
        });
    });
    </script>
    <?php
}