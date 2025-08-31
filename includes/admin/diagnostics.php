<?php
/**
 * HIC Plugin Diagnostics and Monitoring
 */

if (!defined('ABSPATH')) exit;

/* ============ Cron Diagnostics Functions ============ */

/**
 * Check internal scheduler status (replaces WP-Cron diagnostics)
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
        ),
        'queue_table' => array(
            'exists' => false,
            'total_events' => 0,
            'processed_events' => 0,
            'pending_events' => 0,
            'error_events' => 0
        ),
        'lock_status' => array(
            'active' => false,
            'age_seconds' => 0
        )
    );
    
    // Check if internal scheduler conditions are met
    $status['internal_scheduler']['conditions_met'] = 
        hic_reliable_polling_enabled() && 
        hic_get_connection_type() === 'api' && 
        hic_get_api_url() && 
        (hic_has_basic_auth_credentials() || hic_get_api_key());
    
    // Get stats from reliable poller if available
    if (class_exists('HIC_Booking_Poller')) {
        $poller = new HIC_Booking_Poller();
        $poller_stats = $poller->get_stats();
        
        if (!isset($poller_stats['error'])) {
            $status['internal_scheduler'] = array_merge($status['internal_scheduler'], array(
                'last_poll' => $poller_stats['last_poll'] ?? 0,
                'last_poll_human' => $poller_stats['last_poll_human'] ?? 'Mai eseguito',
                'lag_seconds' => $poller_stats['lag_seconds'] ?? 0
            ));
            
            $status['queue_table'] = array(
                'exists' => true,
                'total_events' => $poller_stats['total_events'] ?? 0,
                'processed_events' => $poller_stats['processed_events'] ?? 0,
                'pending_events' => $poller_stats['pending_events'] ?? 0,
                'error_events' => $poller_stats['error_events'] ?? 0
            );
            
            $status['lock_status'] = array(
                'active' => $poller_stats['lock_active'] ?? false,
                'age_seconds' => $poller_stats['lock_age'] ?? 0
            );
        }
    }
    
    // Calculate next run estimate based on polling interval
    if ($status['internal_scheduler']['last_poll'] > 0) {
        $polling_interval = 300; // 5 minutes default
        $schedules = wp_get_schedules();
        $configured_interval = hic_get_polling_interval();
        if (isset($schedules[$configured_interval])) {
            $polling_interval = $schedules[$configured_interval]['interval'];
        }
        
        $next_run_estimate = $status['internal_scheduler']['last_poll'] + $polling_interval;
        $status['internal_scheduler']['next_run_estimate'] = $next_run_estimate;
        $status['internal_scheduler']['next_run_human'] = human_time_diff($next_run_estimate, time()) . ' from now';
    }
    
    // Check if queue table exists manually if poller is not available
    if (!$status['queue_table']['exists']) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'hic_booking_events';
        $status['queue_table']['exists'] = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'") === $queue_table;
    }
    
    // Real-time sync stats (keep existing functionality)
    global $wpdb;
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
        'last_cron_execution' => get_option('hic_last_cron_execution', 0),
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
 * Manual execution for testing (now using internal scheduler)
 */
function hic_execute_manual_cron($event_name) {
    if (!in_array($event_name, array('hic_api_poll_event', 'hic_api_updates_event', 'hic_reliable_poll_event'))) {
        return array('success' => false, 'message' => 'Invalid event name');
    }
    
    $start_time = microtime(true);
    
    try {
        if ($event_name === 'hic_reliable_poll_event' || $event_name === 'hic_api_poll_event') {
            // Use the internal scheduler
            if (class_exists('HIC_Booking_Poller')) {
                $poller = new HIC_Booking_Poller();
                $poller->execute_poll();
                $message = 'Internal scheduler polling executed successfully';
            } else {
                // Fallback to legacy function
                hic_api_poll_bookings();
                $message = 'Legacy polling executed successfully';
            }
        } else {
            hic_api_poll_updates();
            $message = 'Updates polling executed successfully';
        }
        
        $execution_time = round((microtime(true) - $start_time) * 1000, 2);
        
        return array(
            'success' => true, 
            'message' => $message,
            'execution_time' => $execution_time . 'ms'
        );
        
    } catch (Exception $e) {
        return array(
            'success' => false, 
            'message' => 'Error: ' . $e->getMessage()
        );
    }
}

/**
 * Test dispatch functions with sample data
 */
function hic_test_dispatch_functions() {
    // Test data for webhook-style integrations (legacy format)
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
        // Test with both organic (manual) and paid (gads) scenarios
        
        // === TEST SCENARIO 1: Organic/Manual (no tracking IDs) ===
        $organic_gclid = null;
        $organic_fbclid = null;
        $organic_sid = 'test_organic_' . time();
        
        // Test GA4 (Organic)
        if (!empty(hic_get_measurement_id()) && !empty(hic_get_api_secret())) {
            hic_send_to_ga4($test_data, $organic_gclid, $organic_fbclid);
            $results['ga4_organic'] = 'Test organic event sent to GA4';
        } else {
            $results['ga4_organic'] = 'GA4 not configured';
        }
        
        // Test Facebook (Organic)
        if (!empty(hic_get_fb_pixel_id()) && !empty(hic_get_fb_access_token())) {
            hic_send_to_fb($test_data, $organic_gclid, $organic_fbclid);
            $results['facebook_organic'] = 'Test organic event sent to Facebook';
        } else {
            $results['facebook_organic'] = 'Facebook not configured';
        }
        
        // Test Brevo (Organic)
        if (hic_is_brevo_enabled() && !empty(hic_get_brevo_api_key())) {
            hic_send_brevo_contact($test_data, $organic_gclid, $organic_fbclid);
            hic_send_brevo_event($test_data, $organic_gclid, $organic_fbclid);
            $results['brevo_organic'] = 'Test organic contact sent to Brevo';
        } else {
            $results['brevo_organic'] = 'Brevo not configured or disabled';
        }
        
        // === TEST SCENARIO 2: Paid Traffic (Google Ads) ===
        $paid_gclid = 'test_gclid_' . time();
        $paid_fbclid = null;
        $paid_sid = 'test_gads_' . time();
        
        // Test GA4 (Paid)
        if (!empty(hic_get_measurement_id()) && !empty(hic_get_api_secret())) {
            hic_send_to_ga4($test_data, $paid_gclid, $paid_fbclid);
            $results['ga4_paid'] = 'Test paid (gads) event sent to GA4';
        } else {
            $results['ga4_paid'] = 'GA4 not configured';
        }
        
        // Test Facebook (Paid)
        if (!empty(hic_get_fb_pixel_id()) && !empty(hic_get_fb_access_token())) {
            hic_send_to_fb($test_data, $paid_gclid, $paid_fbclid);
            $results['facebook_paid'] = 'Test paid (gads) event sent to Facebook';
        } else {
            $results['facebook_paid'] = 'Facebook not configured';
        }
        
        // Test Brevo (Paid)
        if (hic_is_brevo_enabled() && !empty(hic_get_brevo_api_key())) {
            hic_send_brevo_contact($test_data, $paid_gclid, $paid_fbclid);
            hic_send_brevo_event($test_data, $paid_gclid, $paid_fbclid);
            $results['brevo_paid'] = 'Test paid (gads) contact sent to Brevo';
        } else {
            $results['brevo_paid'] = 'Brevo not configured or disabled';
        }
        
        // === TEST SCENARIO 3: Facebook Ads Traffic (fbads) ===
        $fb_gclid = null;
        $fb_fbclid = 'test_fbclid_' . time();
        $fb_sid = 'test_fbads_' . time();
        
        // Test GA4 (Facebook Ads)
        if (!empty(hic_get_measurement_id()) && !empty(hic_get_api_secret())) {
            hic_send_to_ga4($test_data, $fb_gclid, $fb_fbclid);
            $results['ga4_fbads'] = 'Test fbads event sent to GA4';
        } else {
            $results['ga4_fbads'] = 'GA4 not configured';
        }
        
        // Test Facebook (Facebook Ads)
        if (!empty(hic_get_fb_pixel_id()) && !empty(hic_get_fb_access_token())) {
            hic_send_to_fb($test_data, $fb_gclid, $fb_fbclid);
            $results['facebook_fbads'] = 'Test fbads event sent to Facebook';
        } else {
            $results['facebook_fbads'] = 'Facebook not configured';
        }
        
        // Test Brevo (Facebook Ads)
        if (hic_is_brevo_enabled() && !empty(hic_get_brevo_api_key())) {
            hic_send_brevo_contact($test_data, $fb_gclid, $fb_fbclid);
            hic_send_brevo_event($test_data, $fb_gclid, $fb_fbclid);
            $results['brevo_fbads'] = 'Test fbads contact sent to Brevo';
        } else {
            $results['brevo_fbads'] = 'Brevo not configured or disabled';
        }
        
        // === EMAIL TESTS (Both scenarios use same email functions) ===
        
        // Test Admin Email
        $admin_email = hic_get_admin_email();
        if (!empty($admin_email)) {
            hic_send_admin_email($test_data, $organic_gclid, $organic_fbclid, $organic_sid);
            $results['admin_email'] = 'Test email sent to admin: ' . $admin_email . ' (bucket: organic)';
        } else {
            $results['admin_email'] = 'Admin email not configured';
        }
        
        // Test Francesco Email
        if (hic_francesco_email_enabled()) {
            hic_send_francesco_email($test_data, $paid_gclid, $paid_fbclid, $paid_sid);
            $results['francesco_email'] = 'Test email sent to Francesco (bucket: gads)';
        } else {
            $results['francesco_email'] = 'Francesco email disabled in settings';
        }
        
        return array('success' => true, 'results' => $results);
        
    } catch (Exception $e) {
        return array('success' => false, 'message' => 'Error: ' . $e->getMessage());
    }
}

/**
 * Test bucket normalization function with all combinations
 */
function hic_test_bucket_normalization() {
    $test_cases = array(
        // Test case format: [gclid, fbclid, expected_bucket, description]
        array(null, null, 'organic', 'No tracking IDs (organic traffic)'),
        array('', '', 'organic', 'Empty tracking IDs (organic traffic)'),
        array('test_gclid_123', null, 'gads', 'Google Ads only (gclid priority)'),
        array('test_gclid_123', '', 'gads', 'Google Ads with empty fbclid (gclid priority)'),
        array(null, 'test_fbclid_456', 'fbads', 'Facebook Ads only (fbclid)'),
        array('', 'test_fbclid_456', 'fbads', 'Facebook Ads with empty gclid (fbclid)'),
        array('test_gclid_123', 'test_fbclid_456', 'gads', 'Both tracking IDs (gclid takes priority)'),
        array('0', null, 'organic', 'String zero gclid (should be treated as empty)'),
        array(null, '0', 'organic', 'String zero fbclid (should be treated as empty)'),
        array('false', null, 'gads', 'String "false" gclid (non-empty string)'),
        array(null, 'false', 'fbads', 'String "false" fbclid (non-empty string)')
    );
    
    $results = array();
    $passed = 0;
    $failed = 0;
    
    foreach ($test_cases as $index => $test_case) {
        list($gclid, $fbclid, $expected, $description) = $test_case;
        
        $actual = fp_normalize_bucket($gclid, $fbclid);
        $test_passed = ($actual === $expected);
        
        if ($test_passed) {
            $passed++;
            $status = 'PASS';
        } else {
            $failed++;
            $status = 'FAIL';
        }
        
        $results[] = array(
            'test' => $index + 1,
            'description' => $description,
            'gclid' => $gclid === null ? 'null' : "'" . $gclid . "'",
            'fbclid' => $fbclid === null ? 'null' : "'" . $fbclid . "'",
            'expected' => $expected,
            'actual' => $actual,
            'status' => $status
        );
    }
    
    // Test that legacy function still works
    $legacy_result = hic_get_bucket('test_gclid', null);
    if ($legacy_result === 'gads') {
        $passed++;
        $results[] = array(
            'test' => 'legacy',
            'description' => 'Legacy hic_get_bucket() function compatibility',
            'gclid' => "'test_gclid'",
            'fbclid' => 'null',
            'expected' => 'gads',
            'actual' => $legacy_result,
            'status' => 'PASS'
        );
    } else {
        $failed++;
        $results[] = array(
            'test' => 'legacy',
            'description' => 'Legacy hic_get_bucket() function compatibility',
            'gclid' => "'test_gclid'",
            'fbclid' => 'null',
            'expected' => 'gads',
            'actual' => $legacy_result,
            'status' => 'FAIL'
        );
    }
    
    return array(
        'success' => $failed === 0,
        'summary' => "Bucket normalization tests: {$passed} passed, {$failed} failed",
        'passed' => $passed,
        'failed' => $failed,
        'results' => $results
    );
}

/**
 * Test bucket normalization with actual dispatch functions
 */
function hic_test_bucket_integration() {
    $test_data = array(
        'reservation_id' => 'BUCKET_TEST_' . time(),
        'amount' => 100.00,
        'currency' => 'EUR',
        'email' => 'bucket-test@example.com',
        'first_name' => 'Test',
        'last_name' => 'Bucket',
        'room' => 'Bucket Test Room'
    );
    
    $test_scenarios = array(
        array('scenario' => 'organic', 'gclid' => null, 'fbclid' => null),
        array('scenario' => 'gads', 'gclid' => 'test_gclid_' . time(), 'fbclid' => null),
        array('scenario' => 'fbads', 'gclid' => null, 'fbclid' => 'test_fbclid_' . time()),
        array('scenario' => 'priority_test', 'gclid' => 'test_gclid_' . time(), 'fbclid' => 'test_fbclid_' . time())
    );
    
    $results = array();
    
    foreach ($test_scenarios as $scenario) {
        $expected_bucket = $scenario['scenario'] === 'priority_test' ? 'gads' : $scenario['scenario'];
        $actual_bucket = fp_normalize_bucket($scenario['gclid'], $scenario['fbclid']);
        
        $test_result = array(
            'scenario' => $scenario['scenario'],
            'gclid' => $scenario['gclid'],
            'fbclid' => $scenario['fbclid'],
            'expected_bucket' => $expected_bucket,
            'actual_bucket' => $actual_bucket,
            'bucket_correct' => $actual_bucket === $expected_bucket,
            'integrations' => array()
        );
        
        // Test each integration with this scenario
        $integrations = array(
            'ga4' => function($data, $gclid, $fbclid) {
                if (!empty(hic_get_measurement_id()) && !empty(hic_get_api_secret())) {
                    hic_send_to_ga4($data, $gclid, $fbclid);
                    return 'sent';
                }
                return 'not_configured';
            },
            'facebook' => function($data, $gclid, $fbclid) {
                if (!empty(hic_get_fb_pixel_id()) && !empty(hic_get_fb_access_token())) {
                    hic_send_to_fb($data, $gclid, $fbclid);
                    return 'sent';
                }
                return 'not_configured';
            },
            'brevo' => function($data, $gclid, $fbclid) {
                if (!empty(hic_get_brevo_api_key())) {
                    hic_send_brevo_event($data, $gclid, $fbclid);
                    return 'sent';
                }
                return 'not_configured';
            }
        );
        
        foreach ($integrations as $name => $func) {
            try {
                $status = $func($test_data, $scenario['gclid'], $scenario['fbclid']);
                $test_result['integrations'][$name] = $status;
            } catch (Exception $e) {
                $test_result['integrations'][$name] = 'error: ' . $e->getMessage();
            }
        }
        
        $results[] = $test_result;
    }
    
    return array(
        'success' => true,
        'message' => 'Bucket integration tests completed',
        'results' => $results
    );
}

/**
 * Force restart of internal scheduler (replaces WP-Cron rescheduling)
 */
function hic_force_restart_internal_scheduler() {
    hic_log('Force restart: Starting internal scheduler restart process');
    
    $results = array();
    
    // Clear any existing WP-Cron events (cleanup)
    $legacy_events = array('hic_api_poll_event', 'hic_api_updates_event', 'hic_retry_failed_notifications_event');
    foreach ($legacy_events as $event) {
        $timestamp = wp_next_scheduled($event);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $event);
            $results['legacy_' . $event . '_cleared'] = 'Cleared legacy event';
        }
    }
    
    // Clear internal scheduler event
    $reliable_timestamp = wp_next_scheduled('hic_reliable_poll_event');
    if ($reliable_timestamp) {
        wp_unschedule_event($reliable_timestamp, 'hic_reliable_poll_event');
        $results['reliable_poll_event_cleared'] = 'Cleared existing event';
    }
    
    // Check if internal scheduler should be active
    $should_activate = hic_reliable_polling_enabled() && 
                      hic_get_connection_type() === 'api' && 
                      hic_get_api_url() && 
                      (hic_has_basic_auth_credentials() || hic_get_api_key());
    
    if ($should_activate) {
        // Schedule new reliable polling event
        $result = wp_schedule_event(time() + 60, 'hic_reliable_interval', 'hic_reliable_poll_event');
        if ($result) {
            $results['internal_scheduler'] = 'Successfully restarted';
            hic_log('Force restart: Internal scheduler restarted successfully');
        } else {
            $results['internal_scheduler'] = 'Failed to restart';
            hic_log('Force restart: Failed to restart internal scheduler');
        }
        
        // Reset last poll timestamp to trigger immediate bootstrap
        delete_option('hic_last_reliable_poll');
        $results['poll_history_reset'] = 'Last poll timestamp reset for bootstrap';
        
    } else {
        $results['internal_scheduler'] = 'Conditions not met for activation';
        hic_log('Force restart: Conditions not met for internal scheduler');
    }
    
    return $results;
}

/**
 * Get WordPress cron schedules info
 */
function hic_get_wp_cron_schedules() {
    $schedules = wp_get_schedules();
    return array(
        'available_schedules' => $schedules,
        'hic_interval_exists' => isset($schedules['hic_poll_interval']),
        'hic_interval_seconds' => isset($schedules['hic_poll_interval']) ? $schedules['hic_poll_interval']['interval'] : null
    );
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
    $to_date = date('Y-m-d H:i:s');
    $from_date = date('Y-m-d H:i:s', strtotime('-30 days'));
    
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
 * Raw fetch function that doesn't process reservations
 */
function hic_fetch_reservations_raw($prop_id, $date_type, $from_date, $to_date, $limit = null) {
    $base = rtrim(hic_get_api_url(), '/');
    $email = hic_get_api_email();
    $pass = hic_get_api_password();
    
    if (!$base || !$email || !$pass || !$prop_id) {
        return new WP_Error('hic_missing_conf', 'URL/credenziali/propId mancanti');
    }
    
    $endpoint = $base . '/reservations/' . rawurlencode($prop_id);
    $args = array('date_type' => $date_type, 'from_date' => $from_date, 'to_date' => $to_date);
    if ($limit) $args['limit'] = (int)$limit;
    $url = add_query_arg($args, $endpoint);
    
    hic_log("Raw API Call: $url");

    $res = wp_remote_get($url, array(
        'timeout' => 30,
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode("$email:$pass"),
            'Accept' => 'application/json',
            'User-Agent' => 'WP/FP-HIC-Plugin'
        ),
    ));
    
    if (is_wp_error($res)) {
        hic_log("Raw API call failed: " . $res->get_error_message());
        return $res;
    }
    
    $code = wp_remote_retrieve_response_code($res);
    if ($code !== 200) {
        $body = wp_remote_retrieve_body($res);
        hic_log("Raw API HTTP $code - Response body: " . substr($body, 0, 500));
        return new WP_Error('hic_http', "HTTP $code - Errore API");
    }
    
    $body = wp_remote_retrieve_body($res);
    if (empty($body)) {
        return new WP_Error('hic_empty_response', 'Empty response body');
    }
    
    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        hic_log("JSON decode error: " . json_last_error_msg());
        return new WP_Error('hic_json_error', 'Invalid JSON response');
    }
    
    return $data;
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
add_action('wp_ajax_hic_manual_cron_test', 'hic_ajax_manual_cron_test');
add_action('wp_ajax_hic_refresh_diagnostics', 'hic_ajax_refresh_diagnostics');
add_action('wp_ajax_hic_test_dispatch', 'hic_ajax_test_dispatch');
add_action('wp_ajax_hic_force_reschedule', 'hic_ajax_force_reschedule');
add_action('wp_ajax_hic_backfill_reservations', 'hic_ajax_backfill_reservations');
add_action('wp_ajax_hic_download_latest_bookings', 'hic_ajax_download_latest_bookings');
add_action('wp_ajax_hic_reset_download_tracking', 'hic_ajax_reset_download_tracking');

function hic_ajax_manual_cron_test() {
    // Verify nonce
    if (!check_ajax_referer('hic_diagnostics_nonce', 'nonce', false)) {
        wp_die(json_encode(array('success' => false, 'message' => 'Invalid nonce')));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(array('success' => false, 'message' => 'Insufficient permissions')));
    }
    
    $event_name = sanitize_text_field($_POST['event'] ?? '');
    $result = hic_execute_manual_cron($event_name);
    
    wp_die(json_encode($result));
}

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
    
    // Validate date type (based on API documentation: only checkin, checkout, presence are supported)
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

function hic_ajax_download_latest_bookings() {
    // Verify nonce
    if (!check_ajax_referer('hic_diagnostics_nonce', 'nonce', false)) {
        wp_die(json_encode(array('success' => false, 'message' => 'Invalid nonce')));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die(json_encode(array('success' => false, 'message' => 'Insufficient permissions')));
    }
    
    $format = sanitize_text_field($_POST['format'] ?? 'json');
    
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
                    'message' => 'Tutte le ultime 5 prenotazioni sono già state scaricate. Usa il bottone "Reset Download Tracking" per scaricarle nuovamente.',
                    'already_downloaded' => true
                )));
            }
        }
        
        // Extract booking IDs for tracking
        $booking_ids = array();
        foreach ($result as $booking) {
            if (isset($booking['id']) && !empty($booking['id'])) {
                $booking_ids[] = $booking['id'];
            }
        }
        
        // Mark these bookings as downloaded
        if (!empty($booking_ids)) {
            hic_mark_bookings_as_downloaded($booking_ids);
        }
        
        // Format the data based on requested format
        if ($format === 'csv') {
            $csv_data = hic_format_bookings_as_csv($result);
            $filename = 'ultime_5_prenotazioni_' . date('Y-m-d_H-i-s') . '.csv';
            
            wp_die(json_encode(array(
                'success' => true,
                'format' => 'csv',
                'filename' => $filename,
                'data' => $csv_data,
                'count' => count($result),
                'booking_ids' => $booking_ids
            )));
        } else {
            // JSON format
            $filename = 'ultime_5_prenotazioni_' . date('Y-m-d_H-i-s') . '.json';
            
            wp_die(json_encode(array(
                'success' => true,
                'format' => 'json',
                'filename' => $filename,
                'data' => $result,
                'count' => count($result),
                'booking_ids' => $booking_ids
            )));
        }
        
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
            'message' => 'Tracking dei download resettato con successo. Ora puoi scaricare nuovamente tutte le prenotazioni.'
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
                            </select>
                            <p class="description">
                                <strong>Check-in:</strong> Prenotazioni per arrivi in questo periodo<br>
                                <strong>Check-out:</strong> Prenotazioni per partenze in questo periodo<br>
                                <strong>Presenza:</strong> Prenotazioni con soggiorno in questo periodo
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
            
            <!-- Download Latest Bookings Section -->
            <div class="card">
                <h2>Scarica Ultime Prenotazioni</h2>
                <p>Scarica le ultime 5 prenotazioni create dal sistema Hotel in Cloud per controllo rapido. Le prenotazioni già scaricate vengono automaticamente saltate.</p>
                
                <?php 
                $downloaded_ids = hic_get_downloaded_booking_ids();
                $downloaded_count = count($downloaded_ids);
                ?>
                
                <!-- Download Tracking Status -->
                <div class="notice notice-info inline" style="margin-bottom: 15px;">
                    <p><strong>Status Tracking Download:</strong></p>
                    <ul>
                        <li>Prenotazioni già scaricate: <strong><?php echo esc_html($downloaded_count); ?></strong></li>
                        <li>Sistema anti-duplicazione: <span class="status ok">✓ Attivo</span></li>
                        <?php if ($downloaded_count > 0): ?>
                            <li>Ultime ID scaricate: <code><?php echo esc_html(implode(', ', array_slice($downloaded_ids, -3))); ?></code></li>
                        <?php endif; ?>
                    </ul>
                </div>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="download-format">Formato File</label>
                        </th>
                        <td>
                            <select id="download-format" name="download_format">
                                <option value="json">JSON (per sviluppatori)</option>
                                <option value="csv" selected>CSV (per Excel/fogli di calcolo)</option>
                            </select>
                            <p class="description">Scegli il formato per il download delle prenotazioni</p>
                        </td>
                    </tr>
                </table>
                
                <p>
                    <button class="button button-primary" id="download-latest-bookings">
                        <span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
                        Scarica Ultime 5 Prenotazioni (Non Scaricate)
                    </button>
                    <?php if ($downloaded_count > 0): ?>
                        <button class="button button-secondary" id="reset-download-tracking" style="margin-left: 10px;">
                            <span class="dashicons dashicons-update-alt" style="margin-top: 3px;"></span>
                            Reset Tracking Download
                        </button>
                    <?php endif; ?>
                    <span id="download-status" style="margin-left: 10px; font-weight: bold;"></span>
                </p>
                
                <div class="notice notice-info inline" style="margin-top: 15px;">
                    <p><strong>Note:</strong></p>
                    <ul>
                        <li><strong>Anti-duplicazione:</strong> Vengono scaricate solo le prenotazioni mai scaricate prima</li>
                        <li><strong>Ordinamento:</strong> Le ultime 5 prenotazioni basate sulla data di creazione (più recenti)</li>
                        <li><strong>Tracking automatico:</strong> Il sistema ricorda quali prenotazioni sono state scaricate</li>
                        <li><strong>Reset tracking:</strong> Usa il bottone "Reset" per consentire il nuovo download delle stesse prenotazioni</li>
                        <li>Il download include: ID, dati cliente, camera, date, importo e stato</li>
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
                                    echo 'Attivo';
                                    if ($scheduler_status['internal_scheduler']['last_poll_human'] !== 'Mai eseguito') {
                                        echo ' - Ultimo: ' . esc_html($scheduler_status['internal_scheduler']['last_poll_human']);
                                    }
                                    ?>
                                </span>
                            <?php else: ?>
                                <span class="status error">Non attivo</span>
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
                            <li><strong>Frequenza polling:</strong> Il sistema controlla nuove prenotazioni ogni 1-2 minuti (quasi real-time)</li>
                            <li><strong>Verifica credenziali:</strong> Assicurati che le credenziali API siano corrette</li>
                        <?php endif; ?>
                        <li><strong>Monitoraggio log:</strong> Controlla la sezione "Log Recenti" per errori o problemi</li>
                    </ul>
                </div>
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
                            <td>Sistema di polling interno senza dipendenza da WP-Cron</td>
                        </tr>
                        <tr>
                            <td>WP-Cron Legacy</td>
                            <td><span class="status ok">✓ Rimosso</span></td>
                            <td>Sistema legacy WP-Cron non più utilizzato</td>
                        </tr>
                    </tbody>
                </table>
                
                <h3>Sync Real-time Brevo</h3>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Parametro</th>
                            <th>Valore</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Real-time Sync Abilitato</td>
                            <td>
                                <?php if (hic_realtime_brevo_sync_enabled()): ?>
                                    <span class="status ok">✓ Abilitato</span>
                                <?php else: ?>
                                    <span class="status error">✗ Disabilitato</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Sistema Retry</td>
                            <td>
                                <?php if (function_exists('hic_should_schedule_retry_event') && hic_should_schedule_retry_event()): ?>
                                    <span class="status ok">✓ Integrato nel polling interno</span>
                                <?php else: ?>
                                    <span class="status error">✗ Non attivo (condizioni non soddisfatte)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (isset($scheduler_status['realtime_sync']['table_exists']) && $scheduler_status['realtime_sync']['table_exists'] === false): ?>
                        <tr>
                            <td>Tabella Stati Sync</td>
                            <td><span class="status error">✗ Tabella non esistente</span></td>
                        </tr>
                        <?php else: ?>
                        <tr>
                            <td>Prenotazioni Tracciate</td>
                            <td><?php echo isset($scheduler_status['realtime_sync']['total_tracked']) ? intval($scheduler_status['realtime_sync']['total_tracked']) : '0'; ?></td>
                        </tr>
                        <tr>
                            <td>Notificate con Successo</td>
                            <td>
                                <span class="status ok"><?php echo isset($scheduler_status['realtime_sync']['notified']) ? intval($scheduler_status['realtime_sync']['notified']) : '0'; ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td>Fallite</td>
                            <td>
                                <?php $failed = isset($scheduler_status['realtime_sync']['failed']) ? intval($scheduler_status['realtime_sync']['failed']) : 0; ?>
                                <span class="status <?php echo $failed > 0 ? 'warning' : 'ok'; ?>"><?php echo $failed; ?></span>
                            </td>
                        </tr>
                        <tr>
                            <td>In Attesa</td>
                            <td>
                                <?php $new = isset($scheduler_status['realtime_sync']['new']) ? intval($scheduler_status['realtime_sync']['new']) : 0; ?>
                                <span class="status <?php echo $new > 0 ? 'warning' : 'ok'; ?>"><?php echo $new; ?></span>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Updates Polling Diagnostics -->
                <h3>Diagnostica Updates Polling</h3>
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
                            <td>Updates Enrichment Abilitato</td>
                            <td>
                                <?php if (hic_updates_enrich_contacts()): ?>
                                    <span class="status ok">✓ Abilitato</span>
                                <?php else: ?>
                                    <span class="status error">✗ Disabilitato</span>
                                <?php endif; ?>
                            </td>
                            <td>Controlla se il sistema di arricchimento contatti è attivo</td>
                        </tr>
                        <tr>
                            <td>Ultimo Timestamp Updates</td>
                            <td>
                                <?php 
                                $last_updates_since = get_option('hic_last_updates_since', 0);
                                if ($last_updates_since > 0) {
                                    $time_ago = human_time_diff($last_updates_since, time()) . ' fa';
                                    echo '<span class="status ok">' . esc_html(date('Y-m-d H:i:s', $last_updates_since)) . '</span><br>';
                                    echo '<small>Unix: ' . esc_html($last_updates_since) . ' (' . esc_html($time_ago) . ')</small>';
                                } else {
                                    echo '<span class="status warning">Non impostato</span>';
                                }
                                ?>
                            </td>
                            <td>Timestamp dell'ultimo update processato (con overlap di 5 min)</td>
                        </tr>
                        <tr>
                            <td>Prossimo Polling Range</td>
                            <td>
                                <?php 
                                if ($last_updates_since > 0) {
                                    $overlap_seconds = 300; // Same as in polling function
                                    $next_since = max(0, $last_updates_since - $overlap_seconds);
                                    echo 'Richiederà updates dal: <br>';
                                    echo '<strong>' . esc_html(date('Y-m-d H:i:s', $next_since)) . '</strong><br>';
                                    echo '<small>Unix: ' . esc_html($next_since) . ' (overlap: ' . esc_html($overlap_seconds) . 's)</small>';
                                } else {
                                    echo '<span class="status warning">Non calcolabile</span>';
                                }
                                ?>
                            </td>
                            <td>Range che verrà richiesto nel prossimo polling</td>
                        </tr>
                        <tr>
                            <td>Intervallo Updates Utilizzato</td>
                            <td>
                                <?php if ($updates_interval_used): ?>
                                    <?php 
                                    $actual_seconds = isset($schedules[$updates_interval_used]) ? $schedules[$updates_interval_used]['interval'] : 'N/A';
                                    $is_correct = $updates_interval_used === 'hic_poll_interval' && $actual_seconds == HIC_POLL_INTERVAL_SECONDS;
                                    ?>
                                    <span class="status <?php echo $is_correct ? 'ok' : 'warning'; ?>">
                                        <?php echo esc_html($updates_interval_used . ' (' . $actual_seconds . ' sec)'); ?>
                                    </span>
                                    <?php if (!$is_correct): ?>
                                        <br><small style="color: #dc3232;">⚠ Dovrebbe usare hic_poll_interval (<?php echo HIC_POLL_INTERVAL_SECONDS; ?> sec)</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="status error">Non schedulato</span>
                                <?php endif; ?>
                            </td>
                            <td>Intervallo effettivamente utilizzato per updates polling</td>
                        </tr>
                    </tbody>
                </table>
                
                <h3>Intervalli HIC</h3>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Nome Intervallo</th>
                            <th>Secondi</th>
                            <th>Descrizione</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $hic_intervals = array();
                        foreach ($schedules as $key => $schedule) {
                            if (strpos($key, 'hic_') === 0) {
                                $hic_intervals[$key] = $schedule;
                            }
                        }
                        if (empty($hic_intervals)): ?>
                        <tr>
                            <td colspan="3" style="text-align: center; color: #ffb900; font-weight: bold;">
                                Nessun intervallo HIC registrato
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($hic_intervals as $key => $schedule): ?>
                            <tr>
                                <td><code><?php echo esc_html($key); ?></code></td>
                                <td><?php echo esc_html(number_format($schedule['interval'])); ?></td>
                                <td><?php echo esc_html($schedule['display']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Credentials Status Section -->
            <div class="card">
                <h2>Stato Credenziali e API</h2>
                <table class="widefat" id="hic-credentials-status">
                    <tr>
                        <td>Tipo Connessione</td>
                        <td><?php echo esc_html($credentials_status['connection_type']); ?></td>
                    </tr>
                    <tr>
                        <td>API URL</td>
                        <td><span class="status <?php echo esc_attr($credentials_status['api_url'] ? 'ok' : 'error'); ?>">
                            <?php echo esc_html($credentials_status['api_url'] ? 'Configurato' : 'Mancante'); ?>
                        </span></td>
                    </tr>
                    <tr>
                        <td>Property ID</td>
                        <td><span class="status <?php echo esc_attr($credentials_status['property_id'] ? 'ok' : 'error'); ?>">
                            <?php echo esc_html($credentials_status['property_id'] ? 'Configurato' : 'Mancante'); ?>
                        </span></td>
                    </tr>
                    <tr>
                        <td>API Email</td>
                        <td><span class="status <?php echo esc_attr($credentials_status['api_email'] ? 'ok' : 'error'); ?>">
                            <?php echo esc_html($credentials_status['api_email'] ? 'Configurato' : 'Mancante'); ?>
                        </span></td>
                    </tr>
                    <tr>
                        <td>API Password</td>
                        <td><span class="status <?php echo esc_attr($credentials_status['api_password'] ? 'ok' : 'error'); ?>">
                            <?php echo esc_html($credentials_status['api_password'] ? 'Configurato' : 'Mancante'); ?>
                        </span></td>
                    </tr>
                    <tr>
                        <td>GA4 Configurato</td>
                        <td><span class="status <?php echo esc_attr($credentials_status['ga4_configured'] ? 'ok' : 'error'); ?>">
                            <?php echo esc_html($credentials_status['ga4_configured'] ? 'Sì' : 'No'); ?>
                        </span></td>
                    </tr>
                    <tr>
                        <td>Brevo Configurato</td>
                        <td><span class="status <?php echo esc_attr($credentials_status['brevo_configured'] ? 'ok' : 'error'); ?>">
                            <?php echo esc_html($credentials_status['brevo_configured'] ? 'Sì' : 'No'); ?>
                        </span></td>
                    </tr>
                    <tr>
                        <td>Facebook Configurato</td>
                        <td><span class="status <?php echo esc_attr($credentials_status['facebook_configured'] ? 'ok' : 'error'); ?>">
                            <?php echo esc_html($credentials_status['facebook_configured'] ? 'Sì' : 'No'); ?>
                        </span></td>
                    </tr>
                </table>
            </div>
            
            <!-- Execution Stats Section -->
            <div class="card">
                <h2>Statistiche Esecuzione</h2>
                <table class="widefat" id="hic-execution-stats">
                    <tr>
                        <td>Ultima Esecuzione Cron</td>
                        <td>
                            <?php 
                            if ($execution_stats['last_cron_execution']) {
                                $execution_time = date('Y-m-d H:i:s', $execution_stats['last_cron_execution']);
                                $time_ago = human_time_diff($execution_stats['last_cron_execution'], time()) . ' fa';
                                echo '<span class="status ok">' . esc_html($execution_time) . '</span><br><small>(' . esc_html($time_ago) . ')</small>';
                            } else {
                                echo '<span class="status warning">Mai</span>';
                            }
                            ?>
                        </td>
                    </tr>
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
                            <td>Sistema di polling interno senza dipendenza da WP-Cron</td>
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
        .wrap {
            max-width: none !important;
            margin-right: 20px;
        }
        
        .hic-diagnostics-container {
            max-width: none;
            width: 100%;
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
        }
        
        .hic-diagnostics-container .widefat td,
        .hic-diagnostics-container .widefat th {
            padding: 12px 15px;
            vertical-align: top;
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
        }
        
        #hic-recent-logs div { 
            margin-bottom: 3px; 
            padding: 12px;
        }
        
        .button {
            margin-right: 10px;
            margin-bottom: 5px;
        }
        
        /* Responsive improvements */
        @media (max-width: 782px) {
            .hic-diagnostics-container .card {
                padding: 15px;
            }
            
            .hic-diagnostics-container .widefat {
                font-size: 14px;
            }
            
            .hic-diagnostics-container .widefat td,
            .hic-diagnostics-container .widefat th {
                padding: 8px 10px;
            }
        }
        
        /* Ensure tables don't overflow */
        .hic-diagnostics-container table {
            table-layout: auto;
            word-wrap: break-word;
        }
        
        .hic-diagnostics-container code {
            word-break: break-all;
            background: #f1f1f1;
            padding: 2px 4px;
            border-radius: 3px;
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
        
        // Download latest bookings handler
        $('#download-latest-bookings').click(function() {
            var $btn = $(this);
            var $status = $('#download-status');
            var format = $('#download-format').val();
            
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
            
            // Start download
            $btn.prop('disabled', true);
            $status.text('Scaricando prenotazioni...').css('color', '#0073aa');
            
            $.post(ajaxurl, {
                action: 'hic_download_latest_bookings',
                nonce: '<?php echo wp_create_nonce('hic_diagnostics_nonce'); ?>',
                format: format
            }, function(response) {
                var result = JSON.parse(response);
                
                if (result.success) {
                    $status.text('Download completato! (' + result.count + ' prenotazioni)').css('color', '#46b450');
                    
                    // Create and download file
                    var blob, mimeType;
                    if (result.format === 'csv') {
                        blob = new Blob([result.data], { type: 'text/csv;charset=utf-8;' });
                        mimeType = 'text/csv';
                    } else {
                        blob = new Blob([JSON.stringify(result.data, null, 2)], { type: 'application/json;charset=utf-8;' });
                        mimeType = 'application/json';
                    }
                    
                    // Create download link and trigger download
                    var link = document.createElement('a');
                    if (link.download !== undefined) {
                        var url = URL.createObjectURL(blob);
                        link.setAttribute('href', url);
                        link.setAttribute('download', result.filename);
                        link.style.visibility = 'hidden';
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                        URL.revokeObjectURL(url);
                    } else {
                        // Fallback for older browsers
                        alert('Download automatico non supportato. Copia i dati dalla console del browser.');
                        console.log('Booking data:', result.data);
                    }
                    
                } else {
                    $status.text('Errore durante il download').css('color', '#dc3232');
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
        
        // Reset download tracking handler
        $('#reset-download-tracking').click(function() {
            var $btn = $(this);
            var $status = $('#download-status');
            
            if (!confirm('Vuoi resettare il tracking dei download? Dopo il reset potrai scaricare nuovamente tutte le prenotazioni.')) {
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