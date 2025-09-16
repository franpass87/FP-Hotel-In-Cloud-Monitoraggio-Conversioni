<?php declare(strict_types=1);
/**
 * HIC Plugin Diagnostics and Monitoring
 */

use function FpHic\hic_send_to_ga4;
use function FpHic\hic_send_to_gtm_datalayer;
use function FpHic\hic_send_to_fb;
use function FpHic\hic_send_brevo_contact;
use function FpHic\hic_send_brevo_event;
use function FpHic\hic_api_poll_bookings;
use function FpHic\hic_fetch_reservations_raw;
use function FpHic\hic_process_booking_data;
use function FpHic\hic_backfill_reservations;
use function FpHic\hic_test_brevo_contact_api;
use function FpHic\hic_test_brevo_event_api;

if (!defined('ABSPATH')) exit;

\FpHic\Helpers\hic_safe_add_hook(
    'action',
    'admin_enqueue_scripts',
    function ($hook) {
        if ($hook === 'hic-monitoring_page_hic-diagnostics') {
            wp_set_script_translations(
                'hic-diagnostics',
                'hotel-in-cloud',
                plugin_dir_path(__FILE__) . '../../languages'
            );
        }
    },
    20
);

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
                'polling_interval' => $poller_stats['polling_interval'] ?? 30,
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
                'continuous_scheduled_human' => $continuous_next ? wp_date('Y-m-d H:i:s', $continuous_next) : 'Non programmato',
                'deep_scheduled' => $deep_next,
                'deep_scheduled_human' => $deep_next ? wp_date('Y-m-d H:i:s', $deep_next) : 'Non programmato',
                'wp_cron_disabled' => $wp_cron_disabled,
                'current_time' => time(),
                'continuous_overdue' => $continuous_next && $continuous_next < (time() - 120),
                'deep_overdue' => $deep_next && $deep_next < (time() - 2100) // 35 minutes
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
    // Get the most recent polling timestamp from various sources
    $last_api_poll = get_option('hic_last_api_poll', 0);
    $last_continuous_poll = get_option('hic_last_continuous_poll', 0);
    $last_successful_poll = get_option('hic_last_successful_poll', 0);
    
    // Use the most recent timestamp as the "last poll time"
    $last_poll_time = max($last_api_poll, $last_continuous_poll, $last_successful_poll);
    
    return array(
        'last_poll_time' => $last_poll_time,
        'last_successful_poll' => $last_successful_poll,
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
 * Test dispatch functions with sample data - Essential integrations only
 */
function hic_test_dispatch_functions() {
    // Test data for integrations
    $test_data = array(
        'reservation_id' => 'TEST_' . current_time('timestamp'),
        'id' => 'TEST_' . current_time('timestamp'),
        'amount' => 100.00,
        'currency' => 'EUR',
        'email' => 'test@example.com',
        'guest_first_name' => 'John',
        'guest_last_name' => 'Doe',
        'language' => 'it',
        'phone' => '+39 3331234567',
        'room' => 'Standard Room',
        'checkin' => wp_date('Y-m-d', strtotime('+7 days', current_time('timestamp'))),
        'checkout' => wp_date('Y-m-d', strtotime('+10 days', current_time('timestamp')))
    );
    
    $results = array();
    
    try {
        // Test with organic traffic (no tracking IDs)
        $gclid = null;
        $fbclid = null;
        
        // Test GA4
        if (!empty(hic_get_measurement_id()) && !empty(hic_get_api_secret())) {
            $sid = $test_data['sid'] ?? null;
            hic_send_to_ga4($test_data, $gclid, $fbclid, '', '', $sid);
            $results['ga4'] = 'Test event sent to GA4';
        } else {
            $results['ga4'] = 'GA4 not configured';
        }
        
        // Test GTM
        if (hic_is_gtm_enabled() && !empty(hic_get_gtm_container_id())) {
            $sid = $test_data['sid'] ?? null;
            hic_send_to_gtm_datalayer($test_data, $gclid, $fbclid, $sid);
            $results['gtm'] = 'Test event queued for GTM DataLayer';
        } else {
            $results['gtm'] = 'GTM not configured or disabled';
        }
        
        // Test Facebook
        if (!empty(hic_get_fb_pixel_id()) && !empty(hic_get_fb_access_token())) {
            hic_send_to_fb($test_data, $gclid, $fbclid, '', '');
            $results['facebook'] = 'Test event sent to Facebook';
        } else {
            $results['facebook'] = 'Facebook not configured';
        }
        
        // Test Brevo
        if (hic_is_brevo_enabled() && !empty(hic_get_brevo_api_key())) {
            hic_send_brevo_contact($test_data, $gclid, $fbclid, '', '');
            hic_send_brevo_event($test_data, $gclid, $fbclid, '', '');
            $results['brevo'] = 'Test contact and event sent to Brevo';
        } else {
            $results['brevo'] = 'Brevo not configured or disabled';
        }
        
        // Test Admin Email
        $admin_email = hic_get_admin_email();
        if (!empty($admin_email)) {
            hic_send_admin_email($test_data, $gclid, $fbclid, 'test_' . current_time('timestamp'));
            $results['admin_email'] = 'Test email sent to admin: ' . $admin_email;
        } else {
            $results['admin_email'] = 'Admin email not configured';
        }
        
        return array('success' => true, 'results' => $results);
        
    } catch (Exception $e) {
        return array('success' => false, 'message' => sprintf( __( 'Errore: %s', 'hotel-in-cloud' ), $e->getMessage() ) );
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
    
    // Get last N log lines without loading entire file
    $has_log_manager = function_exists('hic_get_log_manager');
    $log_manager = $has_log_manager ? hic_get_log_manager() : null;
    $lines = array();

    if ($log_manager && method_exists($log_manager, 'get_recent_logs')) {
        // Use log manager which already reads only the last N lines
        $entries = $log_manager->get_recent_logs($log_lines_to_check);

        foreach ($entries as $entry) {
            $lines[] = sprintf(
                '[%s] [%s] [%s] %s',
                $entry['timestamp'] ?? '',
                $entry['level'] ?? '',
                $entry['memory'] ?? '',
                $entry['message'] ?? ''
            );
        }
    } else {
        // Fallback: efficiently read last N lines by reading from end of file in chunks
        $fp = fopen($log_file, 'rb');
        if ($fp) {
            $buffer = '';
            $lines_found = 0;
            $pos = -1;
            $lines = array();
            fseek($fp, 0, SEEK_END);
            $filesize = ftell($fp);
            $chunk_size = 4096;
            while ($filesize > 0 && $lines_found < $log_lines_to_check) {
                $read_size = ($filesize >= $chunk_size) ? $chunk_size : $filesize;
                $filesize -= $read_size;
                fseek($fp, $filesize, SEEK_SET);
                $chunk = fread($fp, $read_size);
                $buffer = $chunk . $buffer;
                $lines_in_buffer = explode("\n", $buffer);
                // If not at start of file, the first line may be incomplete
                if ($filesize > 0) {
                    $buffer = array_shift($lines_in_buffer);
                } else {
                    $buffer = '';
                }
                while (!empty($lines_in_buffer)) {
                    $line = array_pop($lines_in_buffer);
                    $line = trim($line);
                    if ($line !== '') {
                        $lines[] = $line;
                        $lines_found++;
                        if ($lines_found >= $log_lines_to_check) {
                            break 2;
                        }
                    }
                }
            }
            fclose($fp);
        }
        // $lines already has most recent lines first
    }

    if (!$lines) {
        return array('error_count' => 0, 'last_error' => null);
    }

    // Count errors in last lines (already most recent first)
    $error_count = 0;
    $last_error = null;

    foreach ($lines as $line) {
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
    
    update_option('hic_downloaded_booking_ids', $downloaded_ids, false);
    hic_clear_option_cache('hic_downloaded_booking_ids');
    
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
        return new \WP_Error('missing_prop_id', 'Property ID non configurato');
    }
    
    // Check API connection type
    if (hic_get_connection_type() !== 'api') {
        return new \WP_Error('wrong_connection', 'Sistema configurato per webhook, non API');
    }
    
    // Validate credentials
    if (!hic_has_basic_auth_credentials()) {
        return new \WP_Error('missing_credentials', 'Credenziali Basic Auth non configurate');
    }
    
    // Get downloaded booking IDs for filtering
    $downloaded_ids = $skip_downloaded ? hic_get_downloaded_booking_ids() : array();
    
    // Get bookings from the last 30 days to ensure we get recent ones
    $to_date = wp_date('Y-m-d');
    $from_date = wp_date('Y-m-d', strtotime('-30 days', current_time('timestamp')));
    
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
        return new \WP_Error('invalid_response', 'Risposta API non valida');
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

// Add AJAX handlers (using safe hook registration)
\FpHic\Helpers\hic_safe_add_hook('action', 'wp_ajax_hic_refresh_diagnostics', 'hic_ajax_refresh_diagnostics');
\FpHic\Helpers\hic_safe_add_hook('action', 'wp_ajax_hic_test_dispatch', 'hic_ajax_test_dispatch');
\FpHic\Helpers\hic_safe_add_hook('action', 'wp_ajax_hic_force_reschedule', 'hic_ajax_force_reschedule');
\FpHic\Helpers\hic_safe_add_hook('action', 'wp_ajax_hic_create_tables', 'hic_ajax_create_tables');
\FpHic\Helpers\hic_safe_add_hook('action', 'wp_ajax_hic_backfill_reservations', 'hic_ajax_backfill_reservations');
\FpHic\Helpers\hic_safe_add_hook('action', 'wp_ajax_hic_download_latest_bookings', 'hic_ajax_download_latest_bookings');
\FpHic\Helpers\hic_safe_add_hook('action', 'wp_ajax_hic_reset_download_tracking', 'hic_ajax_reset_download_tracking');
\FpHic\Helpers\hic_safe_add_hook('action', 'wp_ajax_hic_force_polling', 'hic_ajax_force_polling');
\FpHic\Helpers\hic_safe_add_hook('action', 'wp_ajax_hic_get_error_log_info', 'hic_ajax_get_error_log_info');
\FpHic\Helpers\hic_safe_add_hook('action', 'wp_ajax_hic_download_error_logs', 'hic_ajax_download_error_logs');
\FpHic\Helpers\hic_safe_add_hook('action', 'wp_ajax_hic_trigger_watchdog', 'hic_ajax_trigger_watchdog');
\FpHic\Helpers\hic_safe_add_hook('action', 'wp_ajax_hic_reset_timestamps', 'hic_ajax_reset_timestamps');
\FpHic\Helpers\hic_safe_add_hook('action', 'wp_ajax_hic_test_brevo_connectivity', 'hic_ajax_test_brevo_connectivity');
\FpHic\Helpers\hic_safe_add_hook('action', 'wp_ajax_hic_get_system_status', 'hic_ajax_get_system_status');
\FpHic\Helpers\hic_safe_add_hook('action', 'wp_ajax_hic_test_web_traffic_monitoring', 'hic_ajax_test_web_traffic_monitoring');
\FpHic\Helpers\hic_safe_add_hook('action', 'wp_ajax_hic_get_web_traffic_stats', 'hic_ajax_get_web_traffic_stats');



function hic_ajax_refresh_diagnostics() {
    if ( ! current_user_can('hic_manage') ) {
        wp_send_json_error( [ 'message' => __( 'Permessi insufficienti', 'hotel-in-cloud' ) ] );
    }

    if ( ! check_ajax_referer( 'hic_diagnostics_nonce', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => __( 'Nonce non valido', 'hotel-in-cloud' ) ] );
    }

    try {
        $data = array(
            'scheduler_status' => hic_get_internal_scheduler_status(),
            'credentials_status' => hic_get_credentials_status(),
            'execution_stats' => hic_get_execution_stats(),
            'recent_logs' => current_user_can('hic_view_logs') ? hic_get_log_manager()->get_recent_logs(20) : array(),
            'error_stats' => hic_get_error_stats()
        );

        wp_send_json_success($data);
    } catch (Exception $e) {
        hic_log('AJAX Refresh Diagnostics Error: ' . $e->getMessage());
        wp_send_json_error( [ 'message' => sprintf( __( 'Errore durante il caricamento diagnostiche: %s', 'hotel-in-cloud' ), $e->getMessage() ) ] );
    } catch (Error $e) {
        hic_log('AJAX Refresh Diagnostics Fatal Error: ' . $e->getMessage());
        wp_send_json_error( [ 'message' => sprintf( __( 'Errore fatale durante il caricamento diagnostiche: %s', 'hotel-in-cloud' ), $e->getMessage() ) ] );
    }
}

function hic_ajax_test_dispatch() {
    if ( ! current_user_can('hic_manage') ) {
        wp_send_json_error( [ 'message' => __( 'Permessi insufficienti', 'hotel-in-cloud' ) ] );
    }

    if ( ! check_ajax_referer( 'hic_diagnostics_nonce', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => __( 'Nonce non valido', 'hotel-in-cloud' ) ] );
    }

    $result = hic_test_dispatch_functions();
    if (!empty($result['success'])) {
        unset($result['success']);
        wp_send_json_success($result);
    } else {
        unset($result['success']);
        wp_send_json_error($result);
    }
}

function hic_ajax_force_reschedule() {
    if ( ! current_user_can('hic_manage') ) {
        wp_send_json_error( [ 'message' => __( 'Permessi insufficienti', 'hotel-in-cloud' ) ] );
    }

    if ( ! check_ajax_referer( 'hic_diagnostics_nonce', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => __( 'Nonce non valido', 'hotel-in-cloud' ) ] );
    }

    $results = hic_force_restart_internal_scheduler();
    wp_send_json_success( [ 'results' => $results ] );
}

function hic_ajax_create_tables() {
    if ( ! current_user_can('hic_manage') ) {
        wp_send_json_error( [ 'message' => __( 'Permessi insufficienti', 'hotel-in-cloud' ) ] );
    }

    if ( ! check_ajax_referer( 'hic_diagnostics_nonce', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => __( 'Nonce non valido', 'hotel-in-cloud' ) ] );
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
                if (!$exists) {
                    $all_exist = false;
                }
            }

            $details = array();
            foreach ($tables_status as $name => $exists) {
                $details[] = $name . ': ' . ($exists ? 'OK' : 'MANCANTE');
            }

            $payload = array(
                'message' => $all_exist ? __( 'Tutte le tabelle sono state create/verificate con successo.', 'hotel-in-cloud' ) : __( 'Alcune tabelle potrebbero non essere state create.', 'hotel-in-cloud' ),
                'details' => implode(', ', $details)
            );

            if ($all_exist) {
                wp_send_json_success($payload);
            } else {
                wp_send_json_error($payload);
            }
        } else {
            wp_send_json_error( [ 'message' => __( 'Errore durante la creazione delle tabelle. Controlla i log per maggiori dettagli.', 'hotel-in-cloud' ) ] );
        }
    } catch (Exception $e) {
        wp_send_json_error( [ 'message' => sprintf( __( 'Errore: %s', 'hotel-in-cloud' ), $e->getMessage() ) ] );
    }
}

function hic_ajax_backfill_reservations() {
    if ( ! current_user_can('hic_manage') ) {
        wp_send_json_error( [ 'message' => __( 'Permessi insufficienti', 'hotel-in-cloud' ) ] );
    }

    if ( ! check_ajax_referer( 'hic_diagnostics_nonce', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => __( 'Nonce non valido', 'hotel-in-cloud' ) ] );
    }

    // Get and validate input parameters using enhanced validator
    $raw_params = [
        'from_date' => wp_unslash( $_POST['from_date'] ?? '' ),
        'to_date' => wp_unslash( $_POST['to_date'] ?? '' ),
        'date_type' => wp_unslash( $_POST['date_type'] ?? 'checkin' ),
        'limit' => isset($_POST['limit']) ? intval( wp_unslash( $_POST['limit'] ) ) : null
    ];
    
    // Validate dates
    $from_date_validation = \FpHic\HIC_Input_Validator::validate_date($raw_params['from_date']);
    if (is_wp_error($from_date_validation)) {
        wp_send_json_error(['message' => 'Data inizio non valida: ' . $from_date_validation->get_error_message()]);
    }
    
    $to_date_validation = \FpHic\HIC_Input_Validator::validate_date($raw_params['to_date']);
    if (is_wp_error($to_date_validation)) {
        wp_send_json_error(['message' => 'Data fine non valida: ' . $to_date_validation->get_error_message()]);
    }
    
    $from_date = $from_date_validation;
    $to_date = $to_date_validation;
    $date_type = sanitize_text_field($raw_params['date_type']);
    $limit = $raw_params['limit'];

    if (null !== $limit) {
        $limit = min( max( 1, $limit ), 200 );
    }

    // Validate date type (based on API documentation: only checkin, checkout, presence are valid for /reservations endpoint)
    if (!in_array($date_type, array('checkin', 'checkout', 'presence'))) {
        wp_send_json_error( [ 'message' => __( 'Tipo di data non valido. Deve essere "checkin", "checkout" o "presence".', 'hotel-in-cloud' ) ] );
    }

    // Validate required fields
    if (empty($from_date) || empty($to_date)) {
        wp_send_json_error( [ 'message' => __( 'Date di inizio e fine sono obbligatorie', 'hotel-in-cloud' ) ] );
    }

    // Call the backfill function
    $result = hic_backfill_reservations($from_date, $to_date, $date_type, $limit);

    if (!empty($result['success'])) {
        unset($result['success']);
        wp_send_json_success($result);
    } else {
        unset($result['success']);
        wp_send_json_error($result);
    }
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
        'language' => 'it', // Default to Italian
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
    if ( ! current_user_can('hic_manage') ) {
        wp_send_json_error( [ 'message' => __( 'Permessi insufficienti', 'hotel-in-cloud' ) ] );
    }

    if ( ! check_ajax_referer( 'hic_diagnostics_nonce', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => __( 'Nonce non valido', 'hotel-in-cloud' ) ] );
    }

    try {
        // Get latest bookings (with duplicate prevention)
        $result = hic_get_latest_bookings(5, true);

        if (is_wp_error($result)) {
            wp_send_json_error( [ 'message' => sprintf( __( 'Errore nel recupero prenotazioni: %s', 'hotel-in-cloud' ), $result->get_error_message() ) ] );
        }

        if (empty($result)) {
            // Check if we have any bookings at all (without filtering)
            $all_bookings = hic_get_latest_bookings(5, false);
            if (is_wp_error($all_bookings) || empty($all_bookings)) {
                wp_send_json_error( [ 'message' => __( 'Nessuna prenotazione trovata nell\'API', 'hotel-in-cloud' ) ] );
            } else {
                wp_send_json_error( [
                    'message' => __( 'Tutte le ultime 5 prenotazioni sono già state inviate. Usa il bottone "Reset Download Tracking" per reinviarle.', 'hotel-in-cloud' ),
                    'already_downloaded' => true
                ] );
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

        wp_send_json_success( array(
            'message' => __( 'Prenotazioni inviate alle integrazioni configurate', 'hotel-in-cloud' ),
            'count' => count($result),
            'success_count' => $success_count,
            'error_count' => $error_count,
            'booking_ids' => $booking_ids,
            'integration_status' => $integration_status,
            'processing_results' => $processing_results
        ) );
    } catch (Exception $e) {
        wp_send_json_error( [ 'message' => sprintf( __( 'Errore: %s', 'hotel-in-cloud' ), $e->getMessage() ) ] );
    }
}

function hic_ajax_reset_download_tracking() {
    if ( ! current_user_can('hic_manage') ) {
        wp_send_json_error( [ 'message' => __( 'Permessi insufficienti', 'hotel-in-cloud' ) ] );
    }

    if ( ! check_ajax_referer( 'hic_diagnostics_nonce', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => __( 'Nonce non valido', 'hotel-in-cloud' ) ] );
    }

    try {
        // Reset the download tracking
        hic_reset_downloaded_bookings();

        wp_send_json_success( array(
            'message' => __( 'Tracking degli invii resettato con successo. Ora puoi inviare nuovamente tutte le prenotazioni alle integrazioni.', 'hotel-in-cloud' )
        ) );
    } catch (Exception $e) {
        wp_send_json_error( [ 'message' => sprintf( __( 'Errore durante il reset: %s', 'hotel-in-cloud' ), $e->getMessage() ) ] );
    }
}

function hic_ajax_force_polling() {
    if ( ! current_user_can('hic_manage') ) {
        wp_send_json_error( [ 'message' => __( 'Permessi insufficienti', 'hotel-in-cloud' ) ] );
    }

    if ( ! check_ajax_referer( 'hic_diagnostics_nonce', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => __( 'Nonce non valido', 'hotel-in-cloud' ) ] );
    }

    try {
        // Get force flag from request
        $force = isset($_POST['force']) && wp_unslash( $_POST['force'] ) === 'true';

        // Check if poller class exists
        if (!class_exists('HIC_Booking_Poller')) {
            wp_send_json_error( [ 'message' => __( 'Classe HIC_Booking_Poller non trovata', 'hotel-in-cloud' ) ] );
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
        $response = array(
            'polling_result' => $result,
            'diagnostics_before' => $diagnostics_before,
            'stats_after' => $stats_after,
            'force_mode' => $force
        );

        if (!empty($result['success'])) {
            wp_send_json_success($response);
        } else {
            wp_send_json_error($response);
        }
    } catch (Exception $e) {
        hic_log('Admin Polling Error: ' . $e->getMessage());
        wp_send_json_error( [ 'message' => sprintf( __( 'Errore durante l\'esecuzione del polling: %s', 'hotel-in-cloud' ), $e->getMessage() ) ] );
    }
}

/**
 * AJAX handler for triggering watchdog check
 */
function hic_ajax_trigger_watchdog() {
    if ( ! current_user_can('hic_manage') ) {
        wp_send_json_error( [ 'message' => __( 'Permessi insufficienti', 'hotel-in-cloud' ) ] );
    }

    if ( ! check_ajax_referer( 'hic_admin_action', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => __( 'Nonce non valido', 'hotel-in-cloud' ) ] );
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
            'message' => __( 'Watchdog check completed successfully', 'hotel-in-cloud' ),
            'watchdog_result' => $watchdog_result,
            'scheduler_restart' => $restart_result
        );

        hic_log('Admin Watchdog: Manual watchdog trigger completed successfully');
        wp_send_json_success($response);
    } catch (Exception $e) {
        hic_log('Admin Watchdog Error: ' . $e->getMessage());
        wp_send_json_error( [ 'message' => sprintf( __( 'Errore durante l\'esecuzione del watchdog: %s', 'hotel-in-cloud' ), $e->getMessage() ) ] );
    } catch (Error $e) {
        hic_log('Admin Watchdog Fatal Error: ' . $e->getMessage());
        wp_send_json_error( [ 'message' => sprintf( __( 'Errore fatale durante l\'esecuzione del watchdog: %s', 'hotel-in-cloud' ), $e->getMessage() ) ] );
    }
}

/**
 * AJAX handler for resetting timestamps (emergency recovery)
 */
function hic_ajax_reset_timestamps() {
    if ( ! current_user_can('hic_manage') ) {
        wp_send_json_error( [ 'message' => __( 'Permessi insufficienti', 'hotel-in-cloud' ) ] );
    }

    if ( ! check_ajax_referer( 'hic_admin_action', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => __( 'Nonce non valido', 'hotel-in-cloud' ) ] );
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

            wp_send_json_success( array(
                'message' => __( 'Timestamp reset completed successfully - all timestamps reset and scheduler restarted', 'hotel-in-cloud' ),
                'result' => $result
            ) );
        } else {
            wp_send_json_error( [ 'message' => __( 'Classe HIC_Booking_Poller non disponibile', 'hotel-in-cloud' ) ] );
        }
    } catch (Exception $e) {
        hic_log('Admin Timestamp Reset Error: ' . $e->getMessage());
        wp_send_json_error( [ 'message' => sprintf( __( 'Errore durante il reset dei timestamp: %s', 'hotel-in-cloud' ), $e->getMessage() ) ] );
    } catch (Error $e) {
        hic_log('Admin Timestamp Reset Fatal Error: ' . $e->getMessage());
        wp_send_json_error( [ 'message' => sprintf( __( 'Errore fatale durante il reset dei timestamp: %s', 'hotel-in-cloud' ), $e->getMessage() ) ] );
    }
}

/**
 * AJAX handler for getting system status updates
 */
function hic_ajax_get_system_status() {
    if ( ! current_user_can('hic_manage') ) {
        wp_send_json_error( [ 'message' => __( 'Permessi insufficienti', 'hotel-in-cloud' ) ] );
    }

    if ( ! check_ajax_referer( 'hic_diagnostics_nonce', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => __( 'Nonce non valido', 'hotel-in-cloud' ) ] );
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

        wp_send_json_success($status_data);
    } catch (Exception $e) {
        wp_send_json_error( [ 'message' => sprintf( __( 'Errore nel recupero dello stato: %s', 'hotel-in-cloud' ), $e->getMessage() ) ] );
    }
}

/* ============ Diagnostics Admin Page ============ */

/**
 * HIC Diagnostics Admin Page
 */
function hic_diagnostics_page() {
    if (!current_user_can('hic_manage')) {
        wp_die( __( 'Non hai i permessi necessari per accedere a questa pagina.', 'hotel-in-cloud' ) );
    }
    
    // Get initial data
    $scheduler_status = hic_get_internal_scheduler_status();
    $credentials_status = hic_get_credentials_status();
    $execution_stats = hic_get_execution_stats();
    $recent_logs = current_user_can('hic_view_logs') ? hic_get_log_manager()->get_recent_logs(20) : array();
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
                                <td><?php echo esc_html($credentials_status['api_url'] ? 'Connessione disponibile' : 'Configurazione richiesta'); ?></td>
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
                                <td><?php echo esc_html($polling_active ? 'Polling automatico funzionante' : 'Richiede configurazione'); ?></td>
                            </tr>
                            <tr>
                                <td>Ultimo Successo</td>
                                <td>
                                    <?php if ($last_poll > 0): ?>
                                        <?php 
                                        $time_diff = current_time('timestamp') - $last_poll;
                                        if ($time_diff < 900): // Less than 15 minutes
                                        ?>
                                            <span class="status ok"><?php echo esc_html(human_time_diff($last_poll, current_time('timestamp'))); ?> fa</span>
                                        <?php elseif ($time_diff < 3600): // Less than 1 hour ?>
                                            <span class="status warning"><?php echo esc_html(human_time_diff($last_poll, current_time('timestamp'))); ?> fa</span>
                                        <?php else: ?>
                                            <span class="status error"><?php echo esc_html(human_time_diff($last_poll, current_time('timestamp'))); ?> fa</span>
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
                    
                    <div class="hic-overview-section">
                        <h3>🌐 Monitoraggio Traffico Web</h3>
                        <?php
                        // Get web traffic monitoring statistics 
                        $poller = new \FpHic\HIC_Booking_Poller();
                        $web_stats = $poller->get_web_traffic_stats();
                        ?>
                        <table class="hic-status-table">
                            <tr>
                                <td>Controlli Totali</td>
                                <td><strong><?php echo esc_html(number_format($web_stats['total_checks'])); ?></strong></td>
                                <td>Verifiche automatiche via traffico web</td>
                            </tr>
                            <tr>
                                <td>Ultimo Frontend</td>
                                <td>
                                    <?php if ($web_stats['last_frontend_check'] > 0): ?>
                                        <?php 
                                        $time_diff = current_time('timestamp') - $web_stats['last_frontend_check'];
                                        if ($time_diff < 3600): // Less than 1 hour
                                        ?>
                                            <span class="status ok"><?php echo esc_html(human_time_diff($web_stats['last_frontend_check'], current_time('timestamp'))); ?> fa</span>
                                        <?php else: ?>
                                            <span class="status warning"><?php echo esc_html(human_time_diff($web_stats['last_frontend_check'], current_time('timestamp'))); ?> fa</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="status error">Mai</span>
                                    <?php endif; ?>
                                </td>
                                <td>Traffico frontend (visitatori)</td>
                            </tr>
                            <tr>
                                <td>Recovery Attivati</td>
                                <td>
                                    <?php if ($web_stats['recoveries_triggered'] > 0): ?>
                                        <span class="status <?php echo $web_stats['recoveries_triggered'] > 5 ? 'warning' : 'ok'; ?>">
                                            <?php echo esc_html(number_format($web_stats['recoveries_triggered'])); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="status ok">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($web_stats['last_recovery_via'] !== 'none'): ?>
                                        Ultimo: <?php echo esc_html($web_stats['last_recovery_via']); ?>
                                    <?php else: ?>
                                        Nessun recovery necessario
                                    <?php endif; ?>
                                </td>
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
                        <button class="hic-button hic-button--primary hic-button--block" id="force-polling">
                            <span class="dashicons dashicons-update"></span>
                            Test Polling
                        </button>
                        <button class="hic-button hic-button--secondary hic-button--block" id="test-connectivity">
                            <span class="dashicons dashicons-cloud"></span>
                            Test Connessione
                        </button>
                        <button class="hic-button hic-button--secondary hic-button--block" id="test-web-traffic">
                            <span class="dashicons dashicons-networking"></span>
                            Test Traffico Web
                        </button>
                    </div>
                    
                    <div class="hic-action-group">
                        <h3>Risoluzione Problemi</h3>
                        <button class="hic-button hic-button--secondary hic-button--block" id="trigger-watchdog">
                            <span class="dashicons dashicons-shield"></span>
                            Watchdog
                        </button>
                        <button class="hic-button hic-button--danger hic-button--block" id="reset-timestamps">
                            <span class="dashicons dashicons-warning"></span>
                            Reset Emergenza
                        </button>
                    </div>
                    
                    <?php if (current_user_can('hic_view_logs')): ?>
                    <div class="hic-action-group">
                        <h3>Logs & Export</h3>
                        <button class="hic-button hic-button--secondary hic-button--block" id="download-error-logs">
                            <span class="dashicons dashicons-download"></span>
                            Scarica Log
                        </button>
                    </div>
                    <?php endif; ?>
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
                            <button class="hic-button hic-button--secondary hic-button--small" id="test-brevo-connectivity-quick">Test API</button>
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
                    
                    <div class="hic-integration-item">
                        <div class="hic-integration-header">
                            <span class="hic-integration-icon">🎯</span>
                            <h3>Google Ads</h3>
                            <?php 
                            // Google Ads tracking is handled via GTM and GA4
                            $gtm_enabled = !empty(hic_get_gtm_container_id());
                            $ga4_enabled = !empty(hic_get_measurement_id());
                            $ads_enabled = $gtm_enabled || $ga4_enabled;
                            ?>
                            <?php if ($ads_enabled): ?>
                                <span class="status ok">✓ Attivo</span>
                            <?php else: ?>
                                <span class="status error">✗ Inattivo</span>
                            <?php endif; ?>
                        </div>
                        <div class="hic-integration-details">
                            <p>Conversion tracking via GA4/GTM</p>
                            <?php if ($gtm_enabled): ?>
                                <small>GTM: <?php echo esc_html(substr(hic_get_gtm_container_id(), 0, 8)); ?>...</small>
                            <?php elseif ($ga4_enabled): ?>
                                <small>Via GA4 measurement</small>
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
                        <button class="hic-button hic-button--primary" id="download-latest-bookings">
                            <span class="dashicons dashicons-upload"></span>
                            Invia Ultime 5 Prenotazioni
                        </button>
                        <?php if ($downloaded_count > 0): ?>
                            <button class="hic-button hic-button--secondary" id="reset-download-tracking">
                                <span class="dashicons dashicons-update-alt"></span>
                                Reset Tracking (<?php echo esc_html($downloaded_count); ?> inviate)
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
                                <td><?php echo esc_html($execution_stats['last_poll_time'] ? wp_date('Y-m-d H:i:s', $execution_stats['last_poll_time']) : 'Mai'); ?></td>
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
                                        echo '<span class="status ' . esc_attr($duration > 10000 ? 'warning' : 'ok') . '">' . esc_html($duration) . ' ms</span>';
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
                    
                    <?php if (current_user_can('hic_view_logs')): ?>
                    <div class="hic-activity-section">
                        <h3>📝 Log Recenti</h3>
                        <div class="hic-logs-container">
                            <?php if (empty($recent_logs)): ?>
                                <?php if (!empty($execution_stats['log_file_exists']) && (int) $execution_stats['log_file_size'] === 0): ?>
                                    <p class="hic-no-logs">File di log presente ma vuoto.</p>
                                <?php else: ?>
                                    <p class="hic-no-logs">Nessun log recente disponibile.</p>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php foreach (array_slice($recent_logs, 0, 8) as $log_entry): ?>
                                    <div class="hic-log-entry"><?php echo esc_html(sprintf('[%s] [%s] [%s] %s', $log_entry['timestamp'], $log_entry['level'], $log_entry['memory'], $log_entry['message'])); ?></div>
                                <?php endforeach; ?>
                                <?php if (count($recent_logs) > 8): ?>
                                    <div class="hic-log-more">... e altri <?php echo esc_html(count($recent_logs) - 8); ?> eventi</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
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
                            <p>Recupera prenotazioni da un intervallo temporale specifico. Il limite deve essere compreso tra 1 e 200 prenotazioni; valori fuori da questo intervallo verranno adattati automaticamente.</p>
                            
                            <div class="hic-backfill-form">
                                <div class="hic-form-row">
                                    <label for="backfill-from-date">Da:</label>
                                    <input type="date" id="backfill-from-date" value="<?php echo esc_attr(wp_date('Y-m-d', strtotime('-7 days', current_time('timestamp')))); ?>" />
                                    
                                    <label for="backfill-to-date">A:</label>
                                    <input type="date" id="backfill-to-date" value="<?php echo esc_attr(wp_date('Y-m-d')); ?>" />
                                    
                                    <label for="backfill-date-type">Tipo:</label>
                                    <select id="backfill-date-type">
                                        <option value="checkin">Check-in</option>
                                        <option value="checkout">Check-out</option>
                                        <option value="presence">Presenza</option>
                                    </select>
                                    
                                    <input type="number" id="backfill-limit" placeholder="Limite (1-200)" min="1" max="200" />
                                </div>
                                
                                <div class="hic-form-actions">
                                    <button class="hic-button hic-button--primary" id="start-backfill">
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
                                <button class="hic-button hic-button--secondary" id="reset-timestamps-advanced">
                                    <span class="dashicons dashicons-update"></span>
                                    Reset Timestamp
                                </button>
                                
                                <div class="hic-brevo-test" <?php echo (hic_is_brevo_enabled() && !empty(hic_get_brevo_api_key())) ? '' : 'style="display:none;"'; ?>>
                                    <button class="hic-button hic-button--secondary" id="test-brevo-connectivity">
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

<?php
} // End of hic_diagnostics_page() function

/**
 * AJAX handler for retrieving error log info
 */
function hic_ajax_get_error_log_info() {
    if ( ! current_user_can('hic_view_logs') ) {
        wp_send_json_error( [ 'message' => __( 'Permessi insufficienti', 'hotel-in-cloud' ) ] );
    }

    if ( ! check_ajax_referer( 'hic_diagnostics_nonce', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => __( 'Nonce non valido', 'hotel-in-cloud' ) ] );
    }

    $log_file = hic_get_log_file();
    $limit    = 5 * 1024 * 1024; // 5 MB limit
    $size     = file_exists($log_file) ? filesize($log_file) : 0;

    wp_send_json_success( [
        'size'    => $size,
        'limit'   => $limit,
        'limited' => $size > $limit,
    ] );
}

/**
 * AJAX handler for downloading error logs
 */
function hic_ajax_download_error_logs() {
    if ( ! current_user_can('hic_view_logs') ) {
        wp_die( __( 'Permessi insufficienti', 'hotel-in-cloud' ) );
    }

    if ( ! check_ajax_referer( 'hic_diagnostics_nonce', 'nonce', false ) ) {
        wp_die( __( 'Nonce non valido', 'hotel-in-cloud' ) );
    }

    $log_file = hic_get_log_file();

    if (!file_exists($log_file)) {
        wp_mkdir_p(dirname($log_file));
        touch($log_file);
    }

    if (!is_readable($log_file)) {
        wp_die(__('File di log non leggibile', 'hotel-in-cloud'));
    }

    $limit    = 5 * 1024 * 1024; // 5 MB limit
    $size     = filesize($log_file);
    $limited  = $size > $limit;
    $filename = 'hic-error-log-' . wp_date('Y-m-d-H-i-s') . '.txt';

    nocache_headers();
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    if (!$limited) {
        header('Content-Length: ' . $size);
    } else {
        header('X-HIC-Log-Limited: 1');
    }

    $handle = fopen($log_file, 'rb');
    if ($limited) {
        $offset = $size - $limit;
        if ($offset > 0) {
            fseek($handle, $offset);
        }
        echo "=== LOG TRONCATO A 5MB ===\n";
    }
    fpassthru($handle);
    fclose($handle);

    exit; // Stop execution after download
}

/**
 * AJAX handler for testing Brevo API connectivity
 */
function hic_ajax_test_brevo_connectivity() {
    if ( ! current_user_can('hic_manage') ) {
        wp_send_json_error( [ 'message' => __( 'Permessi insufficienti', 'hotel-in-cloud' ) ] );
    }

    if ( ! check_ajax_referer( 'hic_admin_action', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => __( 'Nonce non valido', 'hotel-in-cloud' ) ] );
    }

    if (!hic_get_brevo_api_key()) {
        wp_send_json_error( [
            'message' => __( 'API key Brevo mancante. Configura prima l\'API key nelle impostazioni.', 'hotel-in-cloud' )
        ] );
    }

    // Test contact API
    $contact_test = hic_test_brevo_contact_api();

    // Test event API
    $event_test = hic_test_brevo_event_api();

    if ( ! ( $contact_test['success'] && $event_test['success'] ) ) {
        wp_send_json_error( array(
            'message'     => __( 'Test connettività Brevo fallito', 'hotel-in-cloud' ),
            'contact_api' => $contact_test,
            'event_api'   => $event_test,
        ) );
    }

    wp_send_json_success( array(
        'message'     => __( 'Test connettività Brevo completato', 'hotel-in-cloud' ),
        'contact_api' => $contact_test,
        'event_api'   => $event_test,
    ) );
}

/**
 * AJAX handler for testing web traffic monitoring
 */
function hic_ajax_test_web_traffic_monitoring() {
    if ( ! current_user_can('hic_manage') ) {
        wp_send_json_error( [ 'message' => __( 'Permessi insufficienti', 'hotel-in-cloud' ) ] );
    }

    if ( ! check_ajax_referer( 'hic_admin_action', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => __( 'Nonce non valido', 'hotel-in-cloud' ) ] );
    }

    try {
        hic_log('Manual Web Traffic Test: Starting manual web traffic monitoring test');
        
        // Simulate web traffic polling checks
        $poller = new \FpHic\HIC_Booking_Poller();
        
        // Record current state
        $current_time = time();
        $last_continuous = get_option('hic_last_continuous_poll', 0);
        $polling_lag = $current_time - $last_continuous;
        
        // Manually trigger proactive check
        $poller->proactive_scheduler_check();
        
        // Manually trigger fallback check
        $poller->fallback_polling_check();
        
        // Get updated stats
        $web_stats = $poller->get_web_traffic_stats();
        
        $response = array(
            'message' => __( 'Test monitoraggio traffico web completato', 'hotel-in-cloud' ),
            'polling_lag_seconds' => $polling_lag,
            'polling_lag_formatted' => round($polling_lag / 60, 1) . ' minuti',
            'web_traffic_stats' => $web_stats,
            'test_timestamp' => $current_time,
            'checks_executed' => array(
                'proactive_check' => 'completed',
                'fallback_check' => 'completed'
            )
        );

        hic_log('Manual Web Traffic Test: Test completed successfully');
        wp_send_json_success($response);
    } catch (Exception $e) {
        hic_log('Manual Web Traffic Test Error: ' . $e->getMessage());
        wp_send_json_error( [ 'message' => sprintf( __( 'Errore durante test traffico web: %s', 'hotel-in-cloud' ), $e->getMessage() ) ] );
    } catch (Error $e) {
        hic_log('Manual Web Traffic Test Fatal Error: ' . $e->getMessage());
        wp_send_json_error( [ 'message' => sprintf( __( 'Errore fatale durante test traffico web: %s', 'hotel-in-cloud' ), $e->getMessage() ) ] );
    }
}

/**
 * AJAX handler for getting web traffic monitoring statistics
 */
function hic_ajax_get_web_traffic_stats() {
    if ( ! current_user_can('hic_manage') ) {
        wp_send_json_error( [ 'message' => __( 'Permessi insufficienti', 'hotel-in-cloud' ) ] );
    }

    if ( ! check_ajax_referer( 'hic_diagnostics_nonce', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => __( 'Nonce non valido', 'hotel-in-cloud' ) ] );
    }

    try {
        $poller = new \FpHic\HIC_Booking_Poller();
        $web_stats = $poller->get_web_traffic_stats();
        
        wp_send_json_success( array(
            'message' => __( 'Statistiche traffico web recuperate', 'hotel-in-cloud' ),
            'web_traffic_stats' => $web_stats
        ) );
    } catch (Exception $e) {
        hic_log('Get Web Traffic Stats Error: ' . $e->getMessage());
        wp_send_json_error( [ 'message' => sprintf( __( 'Errore durante recupero statistiche: %s', 'hotel-in-cloud' ), $e->getMessage() ) ] );
    }
}

