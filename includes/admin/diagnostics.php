<?php
/**
 * HIC Plugin Diagnostics and Monitoring
 */

if (!defined('ABSPATH')) exit;

/* ============ Cron Diagnostics Functions ============ */

/**
 * Check internal scheduler status (uses WordPress WP-Cron)
 */
function hic_get_internal_scheduler_status() {
    global $wpdb;
    
    $status = array(
        'internal_scheduler' => array(
            'enabled' => hic_reliable_polling_enabled(),
            'conditions_met' => false,
            'last_poll' => null,
            'last_poll_human' => 'Mai eseguito',
            'lag_seconds' => 0,
            'next_run_estimate' => null,
            'next_run_human' => 'Sconosciuto'
        ),
        'realtime_sync' => array(
            'table_exists' => true,
            'total_tracked' => 0,
            'notified' => 0,
            'failed' => 0,
            'new' => 0
        )
    );
    
    // Check if internal scheduler conditions are met
    $status['internal_scheduler']['conditions_met'] = 
        hic_reliable_polling_enabled() && 
        hic_get_connection_type() === 'api' && 
        hic_get_api_url() && 
        hic_has_basic_auth_credentials();
    
    // Get stats from WP-Cron scheduler if available
    if (class_exists('HIC_Booking_Poller')) {
        $poller = new HIC_Booking_Poller();
        $poller_stats = $poller->get_stats();
        
        if (!isset($poller_stats['error'])) {
            $status['internal_scheduler'] = array_merge($status['internal_scheduler'], array(
                'last_poll' => $poller_stats['last_poll'] ?? 0,
                'last_poll_human' => $poller_stats['last_poll_human'] ?? 'Mai eseguito',
                'last_continuous_poll' => $poller_stats['last_continuous_poll'] ?? 0,
                'last_continuous_human' => $poller_stats['last_continuous_human'] ?? 'Mai eseguito',
                'last_deep_check' => $poller_stats['last_deep_check'] ?? 0,
                'last_deep_human' => $poller_stats['last_deep_human'] ?? 'Mai eseguito',
                'lag_seconds' => $poller_stats['lag_seconds'] ?? 0,
                'continuous_lag' => $poller_stats['continuous_lag'] ?? 0,
                'deep_lag' => $poller_stats['deep_lag'] ?? 0,
                'polling_interval' => $poller_stats['polling_interval'] ?? 60,
                'deep_check_interval' => $poller_stats['deep_check_interval'] ?? 600,
                'wp_cron_working' => $poller_stats['wp_cron_working'] ?? false,
                'scheduler_type' => $poller_stats['scheduler_type'] ?? 'Unknown',
                'should_poll' => $poller_stats['should_poll'] ?? false,
                'polling_conditions' => $poller_stats['polling_conditions'] ?? array()
            ));
            
            // Add detailed WP-Cron diagnostics
            $continuous_next = $poller_stats['next_continuous_scheduled'] ?? hic_safe_wp_next_scheduled('hic_continuous_poll_event');
            $deep_next = $poller_stats['next_deep_scheduled'] ?? hic_safe_wp_next_scheduled('hic_deep_check_event');
            $wp_cron_disabled = $poller_stats['wp_cron_disabled'] ?? (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON);
            
            $status['internal_scheduler']['cron_diagnostics'] = array(
                'continuous_scheduled' => $continuous_next,
                'continuous_scheduled_human' => $continuous_next ? date('Y-m-d H:i:s', $continuous_next) : 'Non programmato',
                'deep_scheduled' => $deep_next,
                'deep_scheduled_human' => $deep_next ? date('Y-m-d H:i:s', $deep_next) : 'Non programmato',
                'wp_cron_disabled' => $wp_cron_disabled,
                'current_time' => time(),
                'continuous_overdue' => $continuous_next && $continuous_next < (time() - 120),
                'deep_overdue' => $deep_next && $deep_next < (time() - 720)
            );
            
            // Calculate next run estimates for both continuous and deep check
            $scheduler_type = $poller_stats['scheduler_type'] ?? 'Unknown';
            
            if ($poller_stats['wp_cron_working'] ?? false) {
                // WP-Cron is working - show actual scheduled times
                $next_continuous = $poller_stats['next_continuous_scheduled'] ?? 0;
                $next_deep = $poller_stats['next_deep_scheduled'] ?? 0;
                
                if ($next_continuous > time()) {
                    $status['internal_scheduler']['next_continuous_human'] = 'Tra ' . human_time_diff(time(), $next_continuous);
                } else {
                    $status['internal_scheduler']['next_continuous_human'] = 'In ritardo o ora (WP-Cron)';
                }
                
                if ($next_deep > time()) {
                    $status['internal_scheduler']['next_deep_human'] = 'Tra ' . human_time_diff(time(), $next_deep);
                } else {
                    $status['internal_scheduler']['next_deep_human'] = 'In ritardo o ora (WP-Cron)';
                }
            } else {
                // WP-Cron non attivo
                $status['internal_scheduler']['next_continuous_human'] = 'WP-Cron non funzionante';
                $status['internal_scheduler']['next_deep_human'] = 'WP-Cron non funzionante';
            }
        }
    }
    
    // Real-time sync stats (keep existing functionality)  
    $realtime_table = $wpdb->prefix . 'hic_realtime_sync';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $realtime_table)) === $realtime_table) {
        $status['realtime_sync']['total_tracked'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . esc_sql($realtime_table)));
        $status['realtime_sync']['notified'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . esc_sql($realtime_table) . " WHERE sync_status = %s", 'notified'));
        $status['realtime_sync']['failed'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . esc_sql($realtime_table) . " WHERE sync_status = %s", 'failed'));
        $status['realtime_sync']['permanent_failure'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . esc_sql($realtime_table) . " WHERE sync_status = %s", 'permanent_failure'));
        $status['realtime_sync']['new'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . esc_sql($realtime_table) . " WHERE sync_status = %s", 'new'));
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
    
    // Check if we have Basic Auth credentials
    return hic_has_basic_auth_credentials();
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
        
        // Test GTM
        if (hic_is_gtm_enabled() && !empty(hic_get_gtm_container_id())) {
            hic_send_to_gtm_datalayer($test_data, $gclid, $fbclid);
            $results['gtm'] = 'Test event queued for GTM DataLayer';
        } else {
            $results['gtm'] = 'GTM not configured or disabled';
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
        hic_safe_wp_clear_scheduled_hook($event);
        $results['legacy_' . $event . '_cleared'] = 'Cleared all legacy cron events';
    }
    
    // Check if internal scheduler should be active
    $should_activate = hic_reliable_polling_enabled() && 
                      hic_get_connection_type() === 'api' && 
                      hic_get_api_url() && 
                      hic_has_basic_auth_credentials();
    
    if ($should_activate) {
        // Reset polling timestamps to trigger immediate execution
        delete_option('hic_last_api_poll');
        delete_option('hic_last_successful_poll');
        delete_option('hic_last_continuous_poll');
        delete_option('hic_last_deep_check');
        $results['polling_timestamps_reset'] = 'Timestamps reset for immediate execution';
        
        // Clear and reschedule WP-Cron events for fresh start
        if (class_exists('HIC_Booking_Poller')) {
            $poller = new HIC_Booking_Poller();
            $poller->clear_all_scheduled_events();
            
            // Wait a moment then reschedule
            sleep(1);
            $poller->ensure_scheduler_is_active();
            $results['scheduler_restarted'] = 'WP-Cron events cleared and rescheduled';
        }
        
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
 * Manual watchdog trigger function
 */
function hic_trigger_watchdog_check() {
    hic_log('Manual watchdog: Starting manual watchdog check');
    
    $results = array();
    
    if (class_exists('HIC_Booking_Poller')) {
        $poller = new HIC_Booking_Poller();
        $poller->run_watchdog_check();
        $results['watchdog_executed'] = 'Watchdog check completed';
        hic_log('Manual watchdog: Watchdog check completed');
    } else {
        $results['watchdog_error'] = 'HIC_Booking_Poller class not available';
        hic_log('Manual watchdog: HIC_Booking_Poller class not available');
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
    if (!hic_has_basic_auth_credentials()) {
        return new WP_Error('missing_credentials', 'Credenziali Basic Auth non configurate');
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
add_action('wp_ajax_hic_force_polling', 'hic_ajax_force_polling');
add_action('wp_ajax_hic_download_error_logs', 'hic_ajax_download_error_logs');
add_action('wp_ajax_hic_trigger_watchdog', 'hic_ajax_trigger_watchdog');
add_action('wp_ajax_hic_reset_timestamps', 'hic_ajax_reset_timestamps');
add_action('wp_ajax_hic_test_brevo_connectivity', 'hic_ajax_test_brevo_connectivity');
add_action('wp_ajax_hic_get_system_status', 'hic_ajax_get_system_status');



function hic_ajax_refresh_diagnostics() {
    // Set JSON content type
    header('Content-Type: application/json');
    
    // Verify nonce
    if (!check_ajax_referer('hic_diagnostics_nonce', 'nonce', false)) {
        wp_die(json_encode(array('success' => false, 'message' => 'Invalid nonce')));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(array('success' => false, 'message' => 'Insufficient permissions')));
    }
    
    try {
        $data = array(
            'scheduler_status' => hic_get_internal_scheduler_status(),
            'credentials_status' => hic_get_credentials_status(),
            'execution_stats' => hic_get_execution_stats(),
            'recent_logs' => hic_get_recent_log_entries(20),
            'error_stats' => hic_get_error_stats()
        );
        
        wp_die(json_encode(array('success' => true, 'data' => $data)));
    } catch (Exception $e) {
        hic_log('AJAX Refresh Diagnostics Error: ' . $e->getMessage());
        wp_die(json_encode(array('success' => false, 'message' => 'Errore durante il caricamento diagnostiche: ' . $e->getMessage())));
    } catch (Error $e) {
        hic_log('AJAX Refresh Diagnostics Fatal Error: ' . $e->getMessage());
        wp_die(json_encode(array('success' => false, 'message' => 'Errore fatale durante il caricamento diagnostiche: ' . $e->getMessage())));
    }
}

function hic_ajax_test_dispatch() {
    // Set JSON content type
    header('Content-Type: application/json');
    
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
    // Set JSON content type
    header('Content-Type: application/json');
    
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
    // Set JSON content type
    header('Content-Type: application/json');
    
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
                $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
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
    // Set JSON content type
    header('Content-Type: application/json');
    
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
    
    // Validate date type (based on API documentation: only checkin, checkout, presence are valid for /reservations endpoint)
    if (!in_array($date_type, array('checkin', 'checkout', 'presence'))) {
        wp_die(json_encode(array('success' => false, 'message' => 'Tipo di data non valido. Deve essere "checkin", "checkout" o "presence".')));
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
    // Set JSON content type
    header('Content-Type: application/json');
    
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
    // Set JSON content type
    header('Content-Type: application/json');
    
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

function hic_ajax_force_polling() {
    // Set JSON content type
    header('Content-Type: application/json');
    
    // Verify nonce
    if (!check_ajax_referer('hic_diagnostics_nonce', 'nonce', false)) {
        wp_die(json_encode(array('success' => false, 'message' => 'Invalid nonce')));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(array('success' => false, 'message' => 'Insufficient permissions')));
    }
    
    try {
        // Get force flag from request
        $force = isset($_POST['force']) && $_POST['force'] === 'true';
        
        // Check if poller class exists
        if (!class_exists('HIC_Booking_Poller')) {
            wp_die(json_encode(array(
                'success' => false, 
                'message' => 'HIC_Booking_Poller class not found'
            )));
        }
        
        $poller = new HIC_Booking_Poller();
        
        // Get diagnostics before polling
        $diagnostics_before = $poller->get_detailed_diagnostics();
        
        // Execute polling (force or normal)
        if ($force) {
            hic_log('Admin Force Polling: Starting force execution');
            $result = $poller->force_execute_poll();
        } else {
            hic_log('Admin Manual Polling: Starting normal execution');
            $result = $poller->execute_poll();
        }
        
        // Get stats after polling
        $stats_after = $poller->get_stats();
        
        // Prepare response
        $response = array_merge($result, array(
            'diagnostics_before' => $diagnostics_before,
            'stats_after' => $stats_after,
            'force_mode' => $force
        ));
        
        wp_die(json_encode($response));
        
    } catch (Exception $e) {
        hic_log('Admin Polling Error: ' . $e->getMessage());
        wp_die(json_encode(array(
            'success' => false, 
            'message' => 'Errore durante l\'esecuzione del polling: ' . $e->getMessage()
        )));
    }
}

/**
 * AJAX handler for triggering watchdog check
 */
function hic_ajax_trigger_watchdog() {
    // Set JSON content type
    header('Content-Type: application/json');
    
    // Verify nonce
    if (!check_ajax_referer('hic_admin_action', 'nonce', false)) {
        wp_die(json_encode(array('success' => false, 'message' => 'Invalid nonce')));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(array('success' => false, 'message' => 'Insufficient permissions')));
    }
    
    try {
        hic_log('Admin Watchdog: Manual watchdog trigger initiated');
        
        // Check if required functions exist
        if (!function_exists('hic_trigger_watchdog_check')) {
            throw new Exception('Function hic_trigger_watchdog_check not found');
        }
        
        if (!function_exists('hic_force_restart_internal_scheduler')) {
            throw new Exception('Function hic_force_restart_internal_scheduler not found');
        }
        
        // Execute watchdog check
        $watchdog_result = hic_trigger_watchdog_check();
        
        // Also force restart the scheduler for good measure
        $restart_result = hic_force_restart_internal_scheduler();
        
        $response = array(
            'success' => true,
            'message' => 'Watchdog check completed successfully',
            'watchdog_result' => $watchdog_result,
            'scheduler_restart' => $restart_result
        );
        
        hic_log('Admin Watchdog: Manual watchdog trigger completed successfully');
        wp_die(json_encode($response));
        
    } catch (Exception $e) {
        hic_log('Admin Watchdog Error: ' . $e->getMessage());
        wp_die(json_encode(array(
            'success' => false, 
            'message' => 'Errore durante l\'esecuzione del watchdog: ' . $e->getMessage()
        )));
    } catch (Error $e) {
        hic_log('Admin Watchdog Fatal Error: ' . $e->getMessage());
        wp_die(json_encode(array(
            'success' => false, 
            'message' => 'Errore fatale durante l\'esecuzione del watchdog: ' . $e->getMessage()
        )));
    }
}

/**
 * AJAX handler for resetting timestamps (emergency recovery)
 */
function hic_ajax_reset_timestamps() {
    // Set JSON content type
    header('Content-Type: application/json');
    
    // Verify nonce
    if (!check_ajax_referer('hic_admin_action', 'nonce', false)) {
        wp_die(json_encode(array('success' => false, 'message' => 'Invalid nonce')));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(array('success' => false, 'message' => 'Insufficient permissions')));
    }
    
    try {
        hic_log('Admin Timestamp Reset: Manual timestamp reset initiated');
        
        // Execute timestamp recovery using the new method
        if (class_exists('HIC_Booking_Poller')) {
            $poller = new HIC_Booking_Poller();
            
            // Check if the method exists
            if (!method_exists($poller, 'trigger_timestamp_recovery')) {
                throw new Exception('Method trigger_timestamp_recovery not found in HIC_Booking_Poller class');
            }
            
            $result = $poller->trigger_timestamp_recovery();
            
            $response = array(
                'success' => true,
                'message' => 'Timestamp reset completed successfully - all timestamps reset and scheduler restarted',
                'result' => $result
            );
        } else {
            $response = array(
                'success' => false,
                'message' => 'HIC_Booking_Poller class not available'
            );
        }
        
        wp_die(json_encode($response));
        
    } catch (Exception $e) {
        hic_log('Admin Timestamp Reset Error: ' . $e->getMessage());
        wp_die(json_encode(array(
            'success' => false, 
            'message' => 'Errore durante il reset dei timestamp: ' . $e->getMessage()
        )));
    } catch (Error $e) {
        hic_log('Admin Timestamp Reset Fatal Error: ' . $e->getMessage());
        wp_die(json_encode(array(
            'success' => false, 
            'message' => 'Errore fatale durante il reset dei timestamp: ' . $e->getMessage()
        )));
    }
}

/**
 * AJAX handler for getting system status updates
 */
function hic_ajax_get_system_status() {
    // Set JSON content type
    header('Content-Type: application/json');
    
    // Verify nonce
    if (!check_ajax_referer('hic_diagnostics_nonce', 'nonce', false)) {
        wp_die(json_encode(array('success' => false, 'message' => 'Invalid nonce')));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(array('success' => false, 'message' => 'Insufficient permissions')));
    }
    
    try {
        // Get current system status
        $scheduler_status = hic_get_internal_scheduler_status();
        
        $status_data = array(
            'polling_active' => $scheduler_status['internal_scheduler']['enabled'] && 
                               $scheduler_status['internal_scheduler']['conditions_met'],
            'last_execution' => $scheduler_status['internal_scheduler']['last_poll_human'] ?? 'Mai eseguito',
            'next_execution' => $scheduler_status['internal_scheduler']['next_run_human'] ?? 'Sconosciuto',
            'system_health' => 'ok' // Could be enhanced with more health checks
        );
        
        wp_die(json_encode(array(
            'success' => true,
            'data' => $status_data
        )));
        
    } catch (Exception $e) {
        wp_die(json_encode(array(
            'success' => false, 
            'message' => 'Errore nel recupero dello stato: ' . $e->getMessage()
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
        <h1>🏨 HIC Plugin Diagnostica</h1>
        
        <div class="hic-diagnostics-container">
            
            <!-- System Overview Section -->
            <div class="card hic-overview-card" id="system-overview">
                <h2>📊 Panoramica Sistema
                    <span class="hic-refresh-indicator" id="refresh-indicator"></span>
                </h2>
                
                <div class="hic-overview-grid">
                    <div class="hic-overview-section">
                        <h3>🔗 Connessione</h3>
                        <table class="hic-status-table">
                            <tr>
                                <td>Modalità</td>
                                <td><strong><?php echo esc_html(hic_get_connection_type()); ?></strong></td>
                                <td>
                                    <?php if (hic_get_connection_type() === 'api'): ?>
                                        <span class="status ok">✓ Polling Attivo</span>
                                    <?php else: ?>
                                        <span class="status warning">⚠ Webhook</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>API URL</td>
                                <td><span class="status <?php echo esc_attr($credentials_status['api_url'] ? 'ok' : 'error'); ?>">
                                    <?php echo esc_html($credentials_status['api_url'] ? 'Configurato' : 'Mancante'); ?>
                                </span></td>
                                <td><?php echo $credentials_status['api_url'] ? 'Connessione disponibile' : 'Configurazione richiesta'; ?></td>
                            </tr>
                            <tr>
                                <td>Credenziali</td>
                                <td><span class="status <?php echo esc_attr($credentials_status['api_email'] && $credentials_status['api_password'] ? 'ok' : 'error'); ?>">
                                    <?php echo esc_html($credentials_status['api_email'] && $credentials_status['api_password'] ? 'Complete' : 'Incomplete'); ?>
                                </span></td>
                                <td>Property ID + Email + Password</td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="hic-overview-section">
                        <h3>⚡ Stato Polling</h3>
                        <table class="hic-status-table">
                            <?php 
                            $polling_active = $scheduler_status['internal_scheduler']['enabled'] && $scheduler_status['internal_scheduler']['conditions_met'];
                            $last_poll = $execution_stats['last_successful_poll'];
                            ?>
                            <tr>
                                <td>Sistema</td>
                                <td>
                                    <?php if ($polling_active): ?>
                                        <span class="status ok">✓ Attivo</span>
                                    <?php else: ?>
                                        <span class="status error">✗ Inattivo</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $polling_active ? 'Polling automatico funzionante' : 'Richiede configurazione'; ?></td>
                            </tr>
                            <tr>
                                <td>Ultimo Successo</td>
                                <td>
                                    <?php if ($last_poll > 0): ?>
                                        <?php 
                                        $time_diff = time() - $last_poll;
                                        if ($time_diff < 900): // Less than 15 minutes
                                        ?>
                                            <span class="status ok"><?php echo esc_html(human_time_diff($last_poll, time())); ?> fa</span>
                                        <?php elseif ($time_diff < 3600): // Less than 1 hour ?>
                                            <span class="status warning"><?php echo esc_html(human_time_diff($last_poll, time())); ?> fa</span>
                                        <?php else: ?>
                                            <span class="status error"><?php echo esc_html(human_time_diff($last_poll, time())); ?> fa</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="status error">Mai</span>
                                    <?php endif; ?>
                                </td>
                                <td>Tempo dall'ultimo polling riuscito</td>
                            </tr>
                            <tr>
                                <td>Prenotazioni</td>
                                <td><strong><?php echo esc_html(number_format($execution_stats['processed_reservations'])); ?></strong></td>
                                <td>Totale prenotazioni elaborate</td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Quick Diagnostics Section -->
            <div class="card hic-quick-card">
                <h2>🔧 Diagnostica Rapida</h2>
                
                <div class="hic-quick-actions">
                    <div class="hic-action-group">
                        <h3>Test Sistema</h3>
                        <button class="button button-primary" id="force-polling">
                            <span class="dashicons dashicons-update"></span>
                            Test Polling
                        </button>
                        <button class="button button-secondary" id="test-connectivity">
                            <span class="dashicons dashicons-cloud"></span>
                            Test Connessione
                        </button>
                    </div>
                    
                    <div class="hic-action-group">
                        <h3>Risoluzione Problemi</h3>
                        <button class="button button-secondary" id="trigger-watchdog">
                            <span class="dashicons dashicons-shield"></span>
                            Watchdog
                        </button>
                        <button class="button button-link-delete" id="reset-timestamps">
                            <span class="dashicons dashicons-warning"></span>
                            Reset Emergenza
                        </button>
                    </div>
                    
                    <div class="hic-action-group">
                        <h3>Logs & Export</h3>
                        <button class="button button-secondary" id="download-error-logs">
                            <span class="dashicons dashicons-download"></span>
                            Scarica Log
                        </button>
                    </div>
                </div>
                
                <div id="quick-results" class="hic-results-container" style="display: none;">
                    <div id="quick-results-content"></div>
                </div>
                
                <div id="quick-status" class="hic-status-message"></div>
            </div>
            
            <!-- Integration Status Section -->
            <div class="card hic-integrations-card">
                <h2>🔌 Stato Integrazioni</h2>
                
                <div class="hic-integrations-grid">
                    <div class="hic-integration-item">
                        <div class="hic-integration-header">
                            <span class="hic-integration-icon">📊</span>
                            <h3>Google Analytics 4</h3>
                            <?php if (!empty(hic_get_measurement_id()) && !empty(hic_get_api_secret())): ?>
                                <span class="status ok">✓ Attivo</span>
                            <?php else: ?>
                                <span class="status error">✗ Inattivo</span>
                            <?php endif; ?>
                        </div>
                        <div class="hic-integration-details">
                            <p>Tracking conversioni e eventi booking</p>
                            <?php if (!empty(hic_get_measurement_id())): ?>
                                <small>ID: <?php echo esc_html(substr(hic_get_measurement_id(), 0, 8)); ?>...</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="hic-integration-item">
                        <div class="hic-integration-header">
                            <span class="hic-integration-icon">📧</span>
                            <h3>Brevo</h3>
                            <?php if (hic_is_brevo_enabled() && !empty(hic_get_brevo_api_key())): ?>
                                <span class="status ok">✓ Attivo</span>
                            <?php else: ?>
                                <span class="status error">✗ Inattivo</span>
                            <?php endif; ?>
                        </div>
                        <div class="hic-integration-details">
                            <p>Email marketing e automazioni</p>
                            <?php if (hic_realtime_brevo_sync_enabled()): ?>
                                <small>Real-time sync: ✓</small>
                            <?php endif; ?>
                        </div>
                        <?php if (hic_is_brevo_enabled() && !empty(hic_get_brevo_api_key())): ?>
                        <div class="hic-integration-actions">
                            <button class="button button-small" id="test-brevo-connectivity">Test API</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="hic-integration-item">
                        <div class="hic-integration-header">
                            <span class="hic-integration-icon">📱</span>
                            <h3>Meta/Facebook</h3>
                            <?php if (!empty(hic_get_fb_pixel_id()) && !empty(hic_get_fb_access_token())): ?>
                                <span class="status ok">✓ Attivo</span>
                            <?php else: ?>
                                <span class="status error">✗ Inattivo</span>
                            <?php endif; ?>
                        </div>
                        <div class="hic-integration-details">
                            <p>Facebook Pixel e Conversions API</p>
                            <?php if (!empty(hic_get_fb_pixel_id())): ?>
                                <small>Pixel: <?php echo esc_html(substr(hic_get_fb_pixel_id(), 0, 8)); ?>...</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <?php 
                $downloaded_ids = hic_get_downloaded_booking_ids();
                $downloaded_count = count($downloaded_ids);
                ?>
                
                <div class="hic-integration-actions-section">
                    <h3>🚀 Azioni Rapide</h3>
                    <div class="hic-quick-actions">
                        <button class="button button-primary" id="download-latest-bookings">
                            <span class="dashicons dashicons-upload"></span>
                            Invia Ultime 5 Prenotazioni
                        </button>
                        <?php if ($downloaded_count > 0): ?>
                            <button class="button button-secondary" id="reset-download-tracking">
                                <span class="dashicons dashicons-update-alt"></span>
                                Reset Tracking (<?php echo $downloaded_count; ?> inviate)
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <div id="download-results" class="hic-results-container" style="display: none;">
                        <div id="download-results-content"></div>
                    </div>
                    
                    <div id="download-status" class="hic-status-message"></div>
                </div>
            </div>
            
            <!-- Activity Monitor Section -->
            <div class="card hic-activity-card">
                <h2>📈 Monitor Attività</h2>
                
                <div class="hic-activity-grid">
                    <div class="hic-activity-section">
                        <h3>🔄 Statistiche Esecuzione</h3>
                        <table class="hic-stats-table">
                            <tr>
                                <td>Ultimo Polling</td>
                                <td><?php echo $execution_stats['last_poll_time'] ? esc_html(date('Y-m-d H:i:s', $execution_stats['last_poll_time'])) : 'Mai'; ?></td>
                            </tr>
                            <tr>
                                <td>Prenotazioni Elaborate</td>
                                <td><strong><?php echo esc_html(number_format($execution_stats['processed_reservations'])); ?></strong></td>
                            </tr>
                            <tr>
                                <td>Ultimo Polling - Trovate</td>
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
                                <td>Durata Ultimo Polling</td>
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
                                <td>Errori Recenti</td>
                                <td><span class="status <?php echo esc_attr($error_stats['error_count'] > 0 ? 'error' : 'ok'); ?>">
                                    <?php echo esc_html(number_format($error_stats['error_count'])); ?>
                                </span></td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="hic-activity-section">
                        <h3>📝 Log Recenti</h3>
                        <div class="hic-logs-container">
                            <?php if (empty($recent_logs)): ?>
                                <p class="hic-no-logs">Nessun log recente disponibile.</p>
                            <?php else: ?>
                                <?php foreach (array_slice($recent_logs, 0, 8) as $log_line): ?>
                                    <div class="hic-log-entry"><?php echo esc_html($log_line); ?></div>
                                <?php endforeach; ?>
                                <?php if (count($recent_logs) > 8): ?>
                                    <div class="hic-log-more">... e altri <?php echo count($recent_logs) - 8; ?> eventi</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Advanced Tools Section -->
            <div class="card hic-advanced-card">
                <h2>🛠️ Strumenti Avanzati</h2>
                
                <details class="hic-advanced-details">
                    <summary>
                        <span class="hic-advanced-summary">
                            <span class="dashicons dashicons-admin-tools"></span>
                            Mostra Strumenti Avanzati
                        </span>
                    </summary>
                    
                    <div class="hic-advanced-content">
                        <div class="hic-advanced-section">
                            <h3>📦 Backfill Storico</h3>
                            <p>Recupera prenotazioni da un intervallo temporale specifico.</p>
                            
                            <div class="hic-backfill-form">
                                <div class="hic-form-row">
                                    <label for="backfill-from-date">Da:</label>
                                    <input type="date" id="backfill-from-date" value="<?php echo esc_attr(date('Y-m-d', strtotime('-7 days'))); ?>" />
                                    
                                    <label for="backfill-to-date">A:</label>
                                    <input type="date" id="backfill-to-date" value="<?php echo esc_attr(date('Y-m-d')); ?>" />
                                    
                                    <label for="backfill-date-type">Tipo:</label>
                                    <select id="backfill-date-type">
                                        <option value="checkin">Check-in</option>
                                        <option value="checkout">Check-out</option>
                                        <option value="presence">Presenza</option>
                                    </select>
                                    
                                    <input type="number" id="backfill-limit" placeholder="Limite (opz.)" min="1" max="1000" />
                                </div>
                                
                                <div class="hic-form-actions">
                                    <button class="button button-primary" id="start-backfill">
                                        <span class="dashicons dashicons-download"></span>
                                        Avvia Backfill
                                    </button>
                                    <span id="backfill-status" class="hic-status-message"></span>
                                </div>
                                
                                <div id="backfill-results" class="hic-results-container" style="display: none;">
                                    <div id="backfill-results-content"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="hic-advanced-section">
                            <h3>⚠️ Strumenti di Emergenza</h3>
                            <p class="hic-warning-text">
                                <span class="dashicons dashicons-warning"></span>
                                Usa solo in caso di problemi gravi del sistema.
                            </p>
                            
                            <div class="hic-emergency-tools">
                                <button class="button button-secondary" id="reset-timestamps">
                                    <span class="dashicons dashicons-update"></span>
                                    Reset Timestamp
                                </button>
                                
                                <div class="hic-brevo-test" <?php echo (hic_is_brevo_enabled() && !empty(hic_get_brevo_api_key())) ? '' : 'style="display:none;"'; ?>>
                                    <button class="button button-secondary" id="test-brevo-connectivity">
                                        <span class="dashicons dashicons-cloud"></span>
                                        Test Brevo API
                                    </button>
                                    <div id="brevo-test-results" class="hic-results-container" style="display: none;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </details>
            </div>
            
        </div>
        
        <!-- Test Results -->
        <div id="hic-test-results" style="margin-top: 20px;"></div>
    </div>
    
    <style>
        /* Modern, Clean Diagnostic Interface */
        .wrap {
            max-width: none !important;
            margin: 0 20px 0 0;
            width: calc(100% - 20px) !important;
        }
        
        .wrap h1 {
            color: #1d2327;
            font-size: 28px;
            margin-bottom: 20px;
        }
        
        .hic-diagnostics-container {
            max-width: none;
            width: 100%;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        /* Card Styling */
        .hic-diagnostics-container .card {
            background: #fff;
            border: 1px solid #e1e5e8;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.04);
            padding: 24px;
            width: 100%;
            box-sizing: border-box;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .hic-diagnostics-container .card:hover {
            box-shadow: 0 6px 16px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }
        
        .hic-diagnostics-container .card h2 {
            margin: 0 0 20px 0;
            font-size: 20px;
            font-weight: 600;
            color: #1d2327;
            border-bottom: 2px solid #f0f0f1;
            padding-bottom: 12px;
            position: relative;
        }
        
        /* Auto-refresh indicator */
        .hic-refresh-indicator {
            position: absolute;
            top: 0;
            right: 0;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #00a32a;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .hic-refresh-indicator.active {
            opacity: 1;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(1.2); }
        }
        
        /* Loading States */
        .hic-loading {
            position: relative;
            pointer-events: none;
            opacity: 0.7;
        }
        
        .hic-loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #0073aa;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }
        
        .hic-diagnostics-container .card h3 {
            margin: 20px 0 12px 0;
            font-size: 16px;
            font-weight: 600;
            color: #1d2327;
        }
        
        /* Overview Grid */
        .hic-overview-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-top: 16px;
        }
        
        .hic-overview-section h3 {
            margin-top: 0;
            margin-bottom: 16px;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            color: #646970;
            border-bottom: 1px solid #e1e5e8;
            padding-bottom: 8px;
        }
        
        /* Status Tables */
        .hic-status-table, .hic-stats-table {
            width: 100%;
            border-collapse: collapse;
            background: #f8f9fa;
            border-radius: 6px;
            overflow: hidden;
        }
        
        .hic-status-table td, .hic-stats-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #e1e5e8;
            vertical-align: top;
        }
        
        .hic-status-table td:first-child, .hic-stats-table td:first-child {
            font-weight: 600;
            width: 140px;
            background: #f1f3f4;
        }
        
        .hic-status-table td:last-child {
            color: #646970;
            font-size: 13px;
        }
        
        .hic-status-table tr:last-child td, .hic-stats-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Status Indicators */
        .status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-weight: 600;
            font-size: 13px;
        }
        
        .status.ok { color: #00a32a; }
        .status.error { color: #d63638; }
        .status.warning { color: #dba617; }
        .status.neutral { color: #646970; }
        
        /* Quick Actions */
        .hic-quick-actions {
            display: flex;
            gap: 32px;
            margin-top: 16px;
            flex-wrap: wrap;
        }
        
        .hic-action-group {
            flex: 1;
            min-width: 200px;
        }
        
        .hic-action-group h3 {
            margin: 0 0 12px 0;
            font-size: 14px;
            font-weight: 600;
            color: #646970;
            text-transform: uppercase;
        }
        
        .hic-action-group .button {
            display: block;
            width: 100%;
            margin-bottom: 8px;
            text-align: center;
            padding: 10px 16px;
            border-radius: 6px;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        
        .hic-action-group .button:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .hic-action-group .button:active {
            transform: translateY(0);
        }
        
        .hic-action-group .button:disabled {
            transform: none;
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .hic-action-group .button .dashicons {
            margin-right: 6px;
            margin-top: 0;
            transition: transform 0.2s ease;
        }
        
        .hic-action-group .button:hover .dashicons {
            transform: scale(1.1);
        }
        
        /* Enhanced button states */
        .hic-action-group .button.loading::after {
            content: '';
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            width: 12px;
            height: 12px;
            border: 1px solid currentColor;
            border-top: 1px solid transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        /* Integration Grid */
        .hic-integrations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 16px;
        }
        
        .hic-integration-item {
            background: #f8f9fa;
            border: 1px solid #e1e5e8;
            border-radius: 8px;
            padding: 20px;
            position: relative;
        }
        
        .hic-integration-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }
        
        .hic-integration-icon {
            font-size: 24px;
        }
        
        .hic-integration-header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
            flex: 1;
        }
        
        .hic-integration-details p {
            margin: 0 0 8px 0;
            color: #646970;
            font-size: 14px;
        }
        
        .hic-integration-details small {
            color: #646970;
            font-size: 12px;
        }
        
        .hic-integration-actions {
            margin-top: 12px;
        }
        
        .hic-integration-actions .button {
            font-size: 12px;
            padding: 4px 12px;
        }
        
        .hic-integration-actions-section {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e1e5e8;
        }
        
        .hic-integration-actions-section h3 {
            margin-top: 0;
        }
        
        .hic-integration-actions-section .hic-quick-actions {
            margin-top: 12px;
        }
        
        .hic-integration-actions-section .button {
            margin-right: 12px;
            margin-bottom: 8px;
        }
        
        /* Activity Grid */
        .hic-activity-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-top: 16px;
        }
        
        .hic-activity-section h3 {
            margin-top: 0;
            margin-bottom: 16px;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            color: #646970;
            border-bottom: 1px solid #e1e5e8;
            padding-bottom: 8px;
        }
        
        /* Logs Container */
        .hic-logs-container {
            max-height: 280px;
            overflow-y: auto;
            background: #f8f9fa;
            border: 1px solid #e1e5e8;
            border-radius: 6px;
            padding: 12px;
            font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.4;
        }
        
        .hic-log-entry {
            padding: 4px 0;
            border-bottom: 1px solid #e1e5e8;
            word-break: break-word;
        }
        
        .hic-log-entry:last-child {
            border-bottom: none;
        }
        
        .hic-log-more {
            color: #646970;
            font-style: italic;
            margin-top: 8px;
            text-align: center;
        }
        
        .hic-no-logs {
            color: #646970;
            text-align: center;
            font-style: italic;
            margin: 20px 0;
        }
        
        /* Advanced Tools */
        .hic-advanced-details {
            border: 1px solid #e1e5e8;
            border-radius: 6px;
            margin-top: 16px;
        }
        
        .hic-advanced-details summary {
            padding: 16px;
            cursor: pointer;
            background: #f8f9fa;
            border-radius: 6px 6px 0 0;
            user-select: none;
        }
        
        .hic-advanced-details[open] summary {
            border-bottom: 1px solid #e1e5e8;
            border-radius: 6px 6px 0 0;
        }
        
        .hic-advanced-summary {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: #1d2327;
        }
        
        .hic-advanced-content {
            padding: 20px;
        }
        
        .hic-advanced-section {
            margin-bottom: 24px;
            padding-bottom: 24px;
            border-bottom: 1px solid #e1e5e8;
        }
        
        .hic-advanced-section:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        
        .hic-advanced-section h3 {
            margin-top: 0;
            margin-bottom: 12px;
        }
        
        .hic-advanced-section p {
            margin-bottom: 16px;
            color: #646970;
        }
        
        /* Backfill Form */
        .hic-backfill-form {
            background: #f8f9fa;
            border: 1px solid #e1e5e8;
            border-radius: 6px;
            padding: 16px;
        }
        
        .hic-form-row {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        
        .hic-form-row label {
            font-weight: 600;
            font-size: 14px;
            color: #1d2327;
            min-width: 40px;
        }
        
        .hic-form-row input, .hic-form-row select {
            flex: 1;
            min-width: 120px;
            padding: 6px 12px;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
        }
        
        .hic-form-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        /* Emergency Tools */
        .hic-emergency-tools {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .hic-warning-text {
            color: #d63638;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 12px;
        }
        
        .hic-brevo-test {
            margin-top: 12px;
        }
        
        /* Results and Status */
        .hic-results-container {
            margin-top: 16px;
            padding: 16px;
            background: #f8f9fa;
            border: 1px solid #e1e5e8;
            border-radius: 6px;
            border-left: 4px solid #0073aa;
        }
        
        .hic-status-message {
            margin-left: 12px;
            font-weight: 600;
        }
        
        /* Toast Notifications */
        .hic-toast-container {
            position: fixed;
            top: 32px;
            right: 20px;
            z-index: 9999;
            pointer-events: none;
        }
        
        .hic-toast {
            background: white;
            border: 1px solid #e1e5e8;
            border-radius: 6px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
            padding: 16px 20px;
            margin-bottom: 12px;
            min-width: 300px;
            pointer-events: auto;
            transform: translateX(100%);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .hic-toast.show {
            transform: translateX(0);
        }
        
        .hic-toast.success {
            border-left: 4px solid #00a32a;
        }
        
        .hic-toast.error {
            border-left: 4px solid #d63638;
        }
        
        .hic-toast.warning {
            border-left: 4px solid #dba617;
        }
        
        .hic-toast.info {
            border-left: 4px solid #0073aa;
        }
        
        .hic-toast-content {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .hic-toast-icon {
            font-size: 20px;
            line-height: 1;
        }
        
        .hic-toast-message {
            flex: 1;
            font-size: 14px;
            line-height: 1.4;
        }
        
        .hic-toast-close {
            background: none;
            border: none;
            font-size: 16px;
            cursor: pointer;
            color: #646970;
            padding: 0;
            margin-left: 8px;
        }
        
        .hic-toast-close:hover {
            color: #1d2327;
        }
        
        /* Progress bars for long operations */
        .hic-progress-bar {
            width: 100%;
            height: 4px;
            background: #f0f0f1;
            border-radius: 2px;
            overflow: hidden;
            margin-top: 12px;
        }
        
        .hic-progress-fill {
            height: 100%;
            background: #0073aa;
            border-radius: 2px;
            transition: width 0.3s ease;
            width: 0%;
        }
        
        /* Copy to clipboard functionality */
        .hic-copy-button {
            background: none;
            border: 1px solid #e1e5e8;
            border-radius: 4px;
            padding: 4px 8px;
            font-size: 12px;
            cursor: pointer;
            color: #646970;
            margin-left: 8px;
            transition: all 0.2s ease;
        }
        
        .hic-copy-button:hover {
            background: #f8f9fa;
            color: #1d2327;
        }
        
        .hic-copy-button.copied {
            background: #00a32a;
            color: white;
            border-color: #00a32a;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .hic-overview-grid,
            .hic-activity-grid {
                grid-template-columns: 1fr;
            }
            
            .hic-integrations-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 782px) {
            .wrap {
                width: 100% !important;
                margin-right: 0;
            }
            
            .hic-diagnostics-container .card {
                padding: 16px;
                margin-bottom: 12px;
            }
            
            .hic-quick-actions {
                flex-direction: column;
                gap: 16px;
            }
            
            .hic-action-group {
                min-width: auto;
            }
            
            .hic-form-row {
                flex-direction: column;
                align-items: stretch;
            }
            
            .hic-form-row label {
                min-width: auto;
            }
            
            .hic-emergency-tools {
                flex-direction: column;
            }
            
            .hic-toast {
                min-width: 280px;
                max-width: calc(100vw - 40px);
            }
        }
        
        @media (max-width: 480px) {
            .hic-action-group .button {
                padding: 12px 16px;
                font-size: 14px;
                min-height: 44px; /* Better touch targets */
            }
            
            .hic-overview-section h3 {
                font-size: 12px;
            }
            
            .hic-status-table td {
                padding: 8px 12px;
                font-size: 13px;
            }
        }
        
        /* Accessibility Improvements */
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        
        /* High contrast mode support */
        .high-contrast .card {
            border: 2px solid #000;
            box-shadow: none;
        }
        
        .high-contrast .button {
            border: 2px solid #000;
            font-weight: bold;
        }
        
        .high-contrast .status.ok {
            color: #000;
            background: #fff;
            padding: 2px 4px;
            border: 1px solid #000;
        }
        
        .high-contrast .status.error {
            color: #fff;
            background: #000;
            padding: 2px 4px;
        }
        
        /* Enhanced focus indicators for keyboard navigation */
        .button:focus,
        .hic-copy-button:focus,
        .hic-advanced-details summary:focus {
            outline: 3px solid #0073aa;
            outline-offset: 2px;
            box-shadow: 0 0 0 3px rgba(0, 115, 170, 0.3);
        }
        
        /* Improved touch targets for mobile accessibility */
        @media (max-width: 768px) {
            .button,
            .hic-copy-button,
            .hic-toast-close {
                min-height: 44px;
                min-width: 44px;
            }
        }
        
        /* Button Improvements */
        .button {
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        
        .button:hover {
            transform: translateY(-1px);
        }
        
        .button-primary {
            background: #0073aa;
            border-color: #0073aa;
        }
        
        .button-primary:hover {
            background: #005a87;
            border-color: #005a87;
        }
        
        .button-link-delete {
            color: #d63638;
            border-color: #d63638;
        }
        
        .button-link-delete:hover {
            background: #d63638;
            color: white;
        }
        
        /* Tables */
        .widefat {
            border-collapse: collapse;
            width: 100%;
            background: #fff;
            border: 1px solid #e1e5e8;
            border-radius: 6px;
            overflow: hidden;
        }
        
        .widefat th,
        .widefat td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #e1e5e8;
        }
        
        .widefat th {
            background: #f8f9fa;
            font-weight: 600;
            color: #1d2327;
        }
        
        .widefat tr:last-child td {
            border-bottom: none;
        }
        
        /* Notices */
        .notice.inline {
            margin: 16px 0;
            padding: 12px 16px;
            border-radius: 6px;
        }
        
        .notice-success {
            background: #f0f8f0;
            border-left-color: #00a32a;
        }
        
        .notice-error {
            background: #fef7f7;
            border-left-color: #d63638;
        }
        
        .notice-warning {
            background: #fef8f0;
            border-left-color: #dba617;
        }
        
        .notice-info {
            background: #f0f8ff;
            border-left-color: #0073aa;
        }
    </style>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        
        // Enhanced UI functionality
        
        // Toast notification system
        function showToast(message, type = 'info', duration = 5000) {
            const toastContainer = $('#hic-toast-container');
            if (toastContainer.length === 0) {
                $('body').append('<div id="hic-toast-container" class="hic-toast-container"></div>');
            }
            
            const icons = {
                success: '✓',
                error: '✗',
                warning: '⚠',
                info: 'ℹ'
            };
            
            const toast = $(`
                <div class="hic-toast ${type}">
                    <div class="hic-toast-content">
                        <span class="hic-toast-icon">${icons[type] || icons.info}</span>
                        <span class="hic-toast-message">${message}</span>
                        <button class="hic-toast-close">&times;</button>
                    </div>
                </div>
            `);
            
            $('#hic-toast-container').append(toast);
            
            // Show toast
            setTimeout(() => toast.addClass('show'), 100);
            
            // Auto remove
            const autoRemove = setTimeout(() => {
                toast.removeClass('show');
                setTimeout(() => toast.remove(), 300);
            }, duration);
            
            // Manual close
            toast.find('.hic-toast-close').click(function() {
                clearTimeout(autoRemove);
                toast.removeClass('show');
                setTimeout(() => toast.remove(), 300);
            });
        }
        
        // Auto-refresh system status every 30 seconds
        let refreshInterval;
        function startAutoRefresh() {
            refreshInterval = setInterval(function() {
                $('#refresh-indicator').addClass('active');
                
                // Refresh connection status and key metrics
                $.post(ajaxurl, {
                    action: 'hic_get_system_status',
                    nonce: '<?php echo wp_create_nonce('hic_diagnostics_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        // Update polling status
                        const pollingStatus = response.data.polling_active ? 
                            '<span class="status ok">✓ Attivo</span>' : 
                            '<span class="status error">✗ Inattivo</span>';
                        
                        // Find and update the polling status
                        $('#system-overview').find('td').each(function() {
                            if ($(this).text().includes('Polling Attivo') || $(this).text().includes('Polling Inattivo')) {
                                $(this).html(pollingStatus);
                            }
                        });
                        
                        // Update last execution time if available
                        if (response.data.last_execution) {
                            $('#system-overview').find('td').each(function() {
                                if ($(this).prev().text() === 'Ultimo Polling') {
                                    $(this).text(response.data.last_execution);
                                }
                            });
                        }
                    }
                }).always(function() {
                    setTimeout(() => $('#refresh-indicator').removeClass('active'), 1000);
                });
            }, 30000);
        }
        
        // Enhanced button interactions
        function enhanceButton($button, loadingText = null) {
            const originalText = $button.html();
            const originalClass = $button.attr('class');
            
            return {
                setLoading: function() {
                    $button.addClass('loading').prop('disabled', true);
                    if (loadingText) {
                        $button.text(loadingText);
                    }
                },
                setSuccess: function(message = null) {
                    $button.removeClass('loading').addClass('success');
                    if (message) {
                        showToast(message, 'success');
                    }
                    setTimeout(() => {
                        $button.removeClass('success').prop('disabled', false).html(originalText);
                    }, 2000);
                },
                setError: function(message = null) {
                    $button.removeClass('loading').addClass('error');
                    if (message) {
                        showToast(message, 'error');
                    }
                    setTimeout(() => {
                        $button.removeClass('error').prop('disabled', false).html(originalText);
                    }, 3000);
                },
                reset: function() {
                    $button.removeClass('loading success error').prop('disabled', false).html(originalText);
                }
            };
        }
        
        // Copy to clipboard functionality
        function addCopyButton(selector, textSelector = null) {
            $(selector).each(function() {
                const $element = $(this);
                const $copyBtn = $('<button class="hic-copy-button" title="Copia negli appunti">📋</button>');
                
                $copyBtn.click(function() {
                    const text = textSelector ? $element.find(textSelector).text() : $element.text();
                    navigator.clipboard.writeText(text).then(function() {
                        $copyBtn.addClass('copied').text('✓');
                        showToast('Copiato negli appunti!', 'success', 2000);
                        setTimeout(() => {
                            $copyBtn.removeClass('copied').text('📋');
                        }, 2000);
                    }).catch(function() {
                        showToast('Errore nella copia', 'error');
                    });
                });
                
                $element.append($copyBtn);
            });
        }
        
        // Initialize enhanced features
        startAutoRefresh();
        addCopyButton('.hic-log-entry');
        
        // Add progress bar to long operations
        function createProgressBar() {
            return $('<div class="hic-progress-bar"><div class="hic-progress-fill"></div></div>');
        }
        
        function updateProgress($progressBar, percent) {
            $progressBar.find('.hic-progress-fill').css('width', percent + '%');
        }
        
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
            message += '\nTipo data: ' + (dateType === 'checkin' ? 'Check-in' : dateType === 'checkout' ? 'Check-out' : 'Presenza');
            
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
            
            <?php if (!hic_has_basic_auth_credentials()): ?>
            alert('Credenziali Basic Auth non configurate. Verifica le impostazioni.');
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
        
        // Force Polling handler (updated for new design with enhanced UX)
        $('#force-polling').click(function() {
            var $btn = $(this);
            var buttonController = enhanceButton($btn, 'Eseguendo...');
            var $status = $('#quick-status');
            var $results = $('#quick-results');
            var $resultsContent = $('#quick-results-content');
            
            buttonController.setLoading();
            $status.text('Test polling in corso...').css('color', '#0073aa');
            $results.hide();
            
            // Add progress bar
            var $progressBar = createProgressBar();
            $status.after($progressBar);
            updateProgress($progressBar, 20);
            
            $.post(ajaxurl, {
                action: 'hic_force_polling',
                force: 'true',
                nonce: '<?php echo wp_create_nonce('hic_diagnostics_nonce'); ?>'
            }, function(response) {
                updateProgress($progressBar, 80);
                var result = JSON.parse(response);
                
                if (result.success) {
                    updateProgress($progressBar, 100);
                    buttonController.setSuccess('Test completato con successo!');
                    $status.text('✓ Test completato!').css('color', '#00a32a');
                    
                    var html = '<div class="notice notice-success inline"><p><strong>Test Polling Completato:</strong><br>';
                    html += result.message + '<br>';
                    if (result.execution_time) {
                        html += 'Tempo esecuzione: ' + result.execution_time + ' secondi<br>';
                    }
                    html += '</p></div>';
                    
                    $resultsContent.html(html);
                    $results.show();
                    
                    // Refresh page after 3 seconds
                    setTimeout(function() {
                        showToast('Aggiornamento dati...', 'info', 2000);
                        location.reload();
                    }, 3000);
                    
                } else {
                    buttonController.setError('Test fallito: ' + (result.message || 'Errore sconosciuto'));
                    $status.text('✗ Test fallito').css('color', '#d63638');
                    
                    var html = '<div class="notice notice-error inline"><p><strong>Errore Test:</strong><br>';
                    html += result.message || 'Errore sconosciuto';
                    html += '</p></div>';
                    
                    $resultsContent.html(html);
                    $results.show();
                }
                
                // Remove progress bar
                setTimeout(() => $progressBar.remove(), 1000);
                
            }).fail(function() {
                buttonController.setError('Errore di comunicazione con il server');
                $status.text('✗ Errore comunicazione').css('color', '#d63638');
                $progressBar.remove();
            });
        });
        
        // Test Connectivity handler (enhanced with better UX)
        $('#test-connectivity').click(function() {
            var $btn = $(this);
            var buttonController = enhanceButton($btn, 'Testando...');
            var $status = $('#quick-status');
            var $results = $('#quick-results');
            var $resultsContent = $('#quick-results-content');
            
            buttonController.setLoading();
            $status.text('Test connessione in corso...').css('color', '#0073aa');
            $results.hide();
            
            // Use the existing force polling but without force flag for normal test
            $.post(ajaxurl, {
                action: 'hic_force_polling',
                force: 'false',
                nonce: '<?php echo wp_create_nonce('hic_diagnostics_nonce'); ?>'
            }, function(response) {
                var result = JSON.parse(response);
                
                if (result.success) {
                    buttonController.setSuccess('Connessione verificata!');
                    $status.text('✓ Connessione OK').css('color', '#00a32a');
                    
                    var html = '<div class="notice notice-success inline"><p><strong>Test Connessione Riuscito:</strong><br>';
                    html += result.message + '</p></div>';
                    
                } else {
                    buttonController.setError('Connessione fallita');
                    $status.text('✗ Connessione fallita').css('color', '#d63638');
                    
                    var html = '<div class="notice notice-warning inline"><p><strong>Test Connessione Fallito:</strong><br>';
                    html += result.message || 'Errore sconosciuto';
                    html += '</p></div>';
                }
                
                $resultsContent.html(html);
                $results.show();
                
            }).fail(function() {
                buttonController.setError('Errore di comunicazione');
                $status.text('✗ Errore comunicazione').css('color', '#d63638');
            });
        });
        
        // Trigger Watchdog handler (enhanced with better UX)
        $('#trigger-watchdog').click(function() {
            var $btn = $(this);
            var buttonController = enhanceButton($btn, 'Eseguendo...');
            var $status = $('#quick-status');
            var $results = $('#quick-results');
            var $resultsContent = $('#quick-results-content');
            
            buttonController.setLoading();
            $status.text('Watchdog in corso...').css('color', '#0073aa');
            $results.hide();
            
            $.post(ajaxurl, {
                action: 'hic_trigger_watchdog',
                nonce: '<?php echo wp_create_nonce('hic_admin_action'); ?>'
            }).done(function(response) {
                if (response.success) {
                    buttonController.setSuccess('Watchdog completato con successo!');
                    $status.text('✓ Watchdog completato').css('color', '#00a32a');
                    
                    var html = '<div class="notice notice-success inline"><p><strong>Watchdog Completato:</strong><br>';
                    html += response.message + '</p></div>';
                    
                    $resultsContent.html(html);
                    $results.show();
                } else {
                    buttonController.setError('Watchdog fallito: ' + (response.message || 'Errore sconosciuto'));
                    $status.text('✗ Watchdog fallito').css('color', '#d63638');
                    
                    var html = '<div class="notice notice-warning inline"><p><strong>Watchdog Fallito:</strong><br>';
                    html += response.message || 'Errore sconosciuto';
                    html += '</p></div>';
                    
                    $resultsContent.html(html);
                    $results.show();
                }
                
                setTimeout(function() {
                    showToast('Aggiornamento dati...', 'info', 2000);
                    location.reload();
                }, 3000);
                
            }).fail(function() {
                buttonController.setError('Errore di comunicazione con il server');
                $status.text('✗ Errore comunicazione').css('color', '#d63638');
            });
        });
        
        // Reset Timestamps handler (enhanced with better UX and warnings)
        $('#reset-timestamps').click(function() {
            var $btn = $(this);
            var buttonController = enhanceButton($btn, 'Resettando...');
            var $status = $('#quick-status');
            var $results = $('#quick-results');
            var $resultsContent = $('#quick-results-content');
            
            // Enhanced confirmation with more details
            var confirmMessage = 'ATTENZIONE: Reset Timestamp di Emergenza\n\n' +
                               'Questa azione resetterà TUTTI i timestamp del sistema:\n' +
                               '• Ultimo polling eseguito\n' +
                               '• Orari di scheduling\n' +
                               '• Cache delle prenotazioni\n\n' +
                               'Utilizzare SOLO se il polling è completamente bloccato.\n\n' +
                               'Sei sicuro di voler procedere?';
            
            if (!confirm(confirmMessage)) {
                return;
            }
            
            // Second confirmation for safety
            if (!confirm('Ultima conferma: procedere con il reset di emergenza?')) {
                return;
            }
            
            buttonController.setLoading();
            $status.text('Reset emergenza in corso...').css('color', '#d63638');
            $results.hide();
            
            showToast('Reset di emergenza avviato...', 'warning');
            
            $.post(ajaxurl, {
                action: 'hic_reset_timestamps',
                nonce: '<?php echo wp_create_nonce('hic_admin_action'); ?>'
            }).done(function(response) {
                if (response.success) {
                    buttonController.setSuccess('Reset completato!');
                    $status.text('✓ Reset completato').css('color', '#00a32a');
                    
                    var html = '<div class="notice notice-success inline"><p><strong>Reset Timestamp Completato:</strong><br>';
                    html += response.message + '<br><br>';
                    html += '<em>Il sistema dovrebbe riprendere il polling normalmente.</em></p></div>';
                    
                    $resultsContent.html(html);
                    $results.show();
                    
                    showToast('Sistema ripristinato! La pagina si aggiornerà automaticamente.', 'success');
                } else {
                    buttonController.setError('Reset fallito: ' + (response.message || 'Errore sconosciuto'));
                    $status.text('✗ Reset fallito').css('color', '#d63638');
                    
                    var html = '<div class="notice notice-error inline"><p><strong>Reset Fallito:</strong><br>';
                    html += response.message || 'Errore sconosciuto';
                    html += '</p></div>';
                    
                    $resultsContent.html(html);
                    $results.show();
                }
                
                setTimeout(function() {
                    showToast('Aggiornamento dati...', 'info', 2000);
                    location.reload();
                }, 4000);
                
            }).fail(function() {
                buttonController.setError('Errore di comunicazione con il server');
                $status.text('✗ Errore comunicazione').css('color', '#d63638');
                showToast('Errore di comunicazione durante il reset', 'error');
            });
        });
                
                setTimeout(function() {
                    location.reload();
                }, 3000);
                
            }).fail(function() {
                $status.text('✗ Errore comunicazione').css('color', '#d63638');
            }).always(function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-warning"></span> Reset Emergenza');
            });
        });
        
        // Log download handler (same functionality)
        $('#download-error-logs').click(function() {
            var $btn = $(this);
            
            if (!confirm('Vuoi scaricare il file di log degli errori?')) {
                return;
            }
            
            // Create hidden form for download
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = ajaxurl;
            form.style.display = 'none';
            
            // Add action parameter
            var actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'hic_download_error_logs';
            form.appendChild(actionInput);
            
            // Add nonce parameter
            var nonceInput = document.createElement('input');
            nonceInput.type = 'hidden';
            nonceInput.name = 'nonce';
            nonceInput.value = '<?php echo wp_create_nonce('hic_diagnostics_nonce'); ?>';
            form.appendChild(nonceInput);
            
            // Submit form for download
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        
        });
        
        // Brevo connectivity test handler (same functionality, using existing structure)
        $('#test-brevo-connectivity').click(function() {
            var $btn = $(this);
            var $results = $('#brevo-test-results');
            
            $btn.prop('disabled', true).text('Testando...');
            $results.hide();
            
            $.post(ajaxurl, {
                action: 'hic_test_brevo_connectivity',
                nonce: '<?php echo wp_create_nonce('hic_admin_action'); ?>'
            }).done(function(response) {
                var html = '';
                
                if (response.success) {
                    html += '<div class="notice notice-success inline"><p><strong>Test Connettività Brevo Completato</strong></p>';
                    
                    // Contact API results
                    html += '<h4>API Contatti:</h4>';
                    if (response.contact_api.success) {
                        html += '<p><span class="status ok">✓ Successo</span> - HTTP ' + response.contact_api.http_code + '</p>';
                    } else {
                        html += '<p><span class="status error">✗ Errore</span> - ' + response.contact_api.error + '</p>';
                    }
                    
                    // Event API results
                    html += '<h4>API Eventi:</h4>';
                    if (response.event_api.success) {
                        html += '<p><span class="status ok">✓ Successo</span> - HTTP ' + response.event_api.http_code + '</p>';
                    } else {
                        html += '<p><span class="status error">✗ Errore</span> - ' + response.event_api.error + '</p>';
                    }
                    
                    html += '</div>';
                } else {
                    html = '<div class="notice notice-error inline"><p><strong>Test Fallito:</strong><br>' + response.message + '</p></div>';
                }
                
                $results.html(html).show();
                
            }).fail(function() {
                $results.html('<div class="notice notice-error inline"><p><strong>Errore di comunicazione con il server</strong></p></div>').show();
            }).always(function() {
                $btn.prop('disabled', false).text('Test API');
            });
        });
        
        // Accessibility and keyboard navigation improvements
        
        // Add ARIA labels to buttons and status indicators
        $('.hic-action-group .button').each(function() {
            const $btn = $(this);
            const text = $btn.text().trim();
            $btn.attr('aria-label', 'Azione: ' + text);
        });
        
        // Add role attributes for status indicators
        $('.status').attr('role', 'status').attr('aria-live', 'polite');
        
        // Enhanced keyboard navigation
        $(document).on('keydown', function(e) {
            // ESC to close any open details/dialogs
            if (e.key === 'Escape') {
                $('.hic-advanced-details[open]').removeAttr('open');
                $('.hic-toast').removeClass('show');
            }
            
            // Ctrl+R to refresh (prevent default and use our auto-refresh)
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                showToast('Aggiornamento automatico attivo ogni 30 secondi', 'info');
            }
        });
        
        // Add high contrast mode toggle for accessibility
        if (window.matchMedia && window.matchMedia('(prefers-contrast: high)').matches) {
            $('body').addClass('high-contrast');
        }
        
        // Add loading states for better screen reader support
        function announceToScreenReader(message) {
            const announcement = $('<div>')
                .attr('aria-live', 'assertive')
                .attr('aria-atomic', 'true')
                .addClass('sr-only')
                .text(message);
            
            $('body').append(announcement);
            setTimeout(() => announcement.remove(), 1000);
        }
        
        // Enhanced error handling with better user feedback
        $(document).ajaxError(function(event, xhr, settings, thrownError) {
            if (settings.url && settings.url.includes('admin-ajax.php')) {
                const action = settings.data && settings.data.includes('action=') ? 
                    settings.data.match(/action=([^&]*)/)[1] : 'unknown';
                
                showToast(`Errore durante l'operazione ${action}. Riprova.`, 'error');
                announceToScreenReader(`Errore durante l'operazione ${action}`);
            }
        });
        
        // Add confirmation dialogs for destructive actions
        $('.button-link-delete, #reset-timestamps').on('click', function(e) {
            const action = $(this).text().trim();
            announceToScreenReader(`Azione di emergenza: ${action} richiede conferma`);
        });
    });
    </script>

<?php
} // End of hic_diagnostics_page() function

/**
 * AJAX handler for downloading error logs
 */
function hic_ajax_download_error_logs() {
    // Verify nonce for security
    if (!check_ajax_referer('hic_diagnostics_nonce', 'nonce', false)) {
        wp_die('Nonce verification failed');
    }
    
    // Check if user has permission
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }
    
    $log_file = hic_get_log_file();
    
    if (!file_exists($log_file) || !is_readable($log_file)) {
        wp_die('Log file not found or not readable');
    }
    
    // Set headers for file download
    $filename = 'hic-error-log-' . date('Y-m-d-H-i-s') . '.txt';
    
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($log_file));
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Output the file content
    readfile($log_file);
    
    wp_die(); // Stop execution after download
}

/**
 * AJAX handler for testing Brevo API connectivity
 */
function hic_ajax_test_brevo_connectivity() {
    // Set JSON content type
    header('Content-Type: application/json');
    
    check_admin_referer('hic_admin_action', 'nonce');
    
    if (!hic_get_brevo_api_key()) {
        wp_die(json_encode(array(
            'success' => false,
            'message' => 'API key Brevo mancante. Configura prima l\'API key nelle impostazioni.'
        )));
    }
    
    // Test contact API
    $contact_test = hic_test_brevo_contact_api();
    
    // Test event API
    $event_test = hic_test_brevo_event_api();
    
    wp_die(json_encode(array(
        'success' => true,
        'message' => 'Test connettività Brevo completato',
        'contact_api' => $contact_test,
        'event_api' => $event_test
    )));
}

