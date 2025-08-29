<?php
/**
 * HIC Plugin Diagnostics and Monitoring
 */

if (!defined('ABSPATH')) exit;

/* ============ Cron Diagnostics Functions ============ */

/**
 * Check if cron events are properly scheduled
 */
function hic_get_cron_status() {
    $status = array(
        'poll_event' => array(
            'scheduled' => false,
            'next_run' => null,
            'next_run_human' => 'Non schedulato',
            'conditions_met' => false
        ),
        'updates_event' => array(
            'scheduled' => false,
            'next_run' => null,
            'next_run_human' => 'Non schedulato',
            'conditions_met' => false
        ),
        'system_cron_enabled' => false,
        'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON
    );
    
    // Check main polling event
    $next_poll = wp_next_scheduled('hic_api_poll_event');
    if ($next_poll) {
        $status['poll_event']['scheduled'] = true;
        $status['poll_event']['next_run'] = $next_poll;
        $status['poll_event']['next_run_human'] = human_time_diff($next_poll, time()) . ' from now';
    }
    
    // Check updates polling event
    $next_updates = wp_next_scheduled('hic_api_updates_event');
    if ($next_updates) {
        $status['updates_event']['scheduled'] = true;
        $status['updates_event']['next_run'] = $next_updates;
        $status['updates_event']['next_run_human'] = human_time_diff($next_updates, time()) . ' from now';
    }
    
    // Check scheduling conditions
    $status['poll_event']['conditions_met'] = hic_should_schedule_poll_event();
    $status['updates_event']['conditions_met'] = hic_should_schedule_updates_event();
    
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
    $has_basic_auth = hic_get_property_id() && hic_get_api_email() && hic_get_api_password();
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
    return hic_get_property_id() && hic_get_api_email() && hic_get_api_password();
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
        'last_updates_time' => get_option('hic_last_updates_since', 0),
        'processed_reservations' => count(get_option('hic_synced_res_ids', array())),
        'enriched_emails' => count(get_option('hic_res_email_map', array())),
        'last_poll_reservations_found' => get_option('hic_last_poll_count', 0),
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
 * Manual cron execution for testing
 */
function hic_execute_manual_cron($event_name) {
    if (!in_array($event_name, array('hic_api_poll_event', 'hic_api_updates_event'))) {
        return array('success' => false, 'message' => 'Invalid event name');
    }
    
    $start_time = microtime(true);
    
    try {
        if ($event_name === 'hic_api_poll_event') {
            hic_api_poll_bookings();
            $message = 'Main polling executed successfully';
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
 * Force rescheduling of cron events
 */
function hic_force_reschedule_crons() {
    // Clear existing schedules
    $poll_timestamp = wp_next_scheduled('hic_api_poll_event');
    if ($poll_timestamp) {
        wp_unschedule_event($poll_timestamp, 'hic_api_poll_event');
    }
    
    $updates_timestamp = wp_next_scheduled('hic_api_updates_event');
    if ($updates_timestamp) {
        wp_unschedule_event($updates_timestamp, 'hic_api_updates_event');
    }
    
    // Reschedule if conditions are met
    $results = array();
    
    if (hic_should_schedule_poll_event()) {
        if (wp_schedule_event(time(), 'hic_poll_interval', 'hic_api_poll_event')) {
            $results['poll_event'] = 'Successfully rescheduled';
        } else {
            $results['poll_event'] = 'Failed to reschedule';
        }
    } else {
        $results['poll_event'] = 'Conditions not met for scheduling';
    }
    
    if (hic_should_schedule_updates_event()) {
        if (wp_schedule_event(time(), 'hic_poll_interval', 'hic_api_updates_event')) {
            $results['updates_event'] = 'Successfully rescheduled';
        } else {
            $results['updates_event'] = 'Failed to reschedule';
        }
    } else {
        $results['updates_event'] = 'Conditions not met for scheduling';
    }
    
    return $results;
}

/**
 * Check system cron setup
 */
function hic_check_system_cron() {
    // Try to detect if wp-cron.php is being called by system cron
    // This is a basic check - more sophisticated detection could be added
    
    $status = array(
        'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        'suggested_crontab' => hic_get_suggested_crontab_entry(),
        'suggested_crontab_with_test' => hic_get_suggested_crontab_with_test(),
        'last_cron_check' => get_option('hic_last_cron_check', 0),
        'cron_test_url' => hic_get_cron_test_url()
    );
    
    // Update last check time
    update_option('hic_last_cron_check', time());
    
    return $status;
}

/**
 * Get suggested crontab entry
 */
function hic_get_suggested_crontab_entry() {
    $wp_cron_url = site_url('wp-cron.php');
    return "*/5 * * * * wget -q -O - \"$wp_cron_url\" >/dev/null 2>&1";
}

/**
 * Get suggested crontab entry with test script
 */
function hic_get_suggested_crontab_with_test() {
    $test_url = hic_get_cron_test_url();
    $wp_cron_url = site_url('wp-cron.php');
    return "*/5 * * * * wget -q -O - \"$wp_cron_url\" >/dev/null 2>&1 && wget -q -O - \"$test_url\" >/dev/null 2>&1";
}

/**
 * Get cron test URL
 */
function hic_get_cron_test_url() {
    return plugin_dir_url(dirname(dirname(__FILE__))) . 'cron-test.php';
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
    $log_file = hic_get_log_file();
    if (!file_exists($log_file)) {
        return array('error_count' => 0, 'last_error' => null);
    }
    
    $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return array('error_count' => 0, 'last_error' => null);
    }
    
    // Count errors in last 1000 lines
    $recent_lines = array_slice($lines, -1000);
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

// Add AJAX handlers
add_action('wp_ajax_hic_manual_cron_test', 'hic_ajax_manual_cron_test');
add_action('wp_ajax_hic_refresh_diagnostics', 'hic_ajax_refresh_diagnostics');
add_action('wp_ajax_hic_test_dispatch', 'hic_ajax_test_dispatch');
add_action('wp_ajax_hic_force_reschedule', 'hic_ajax_force_reschedule');

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
        'cron_status' => hic_get_cron_status(),
        'credentials_status' => hic_get_credentials_status(),
        'execution_stats' => hic_get_execution_stats(),
        'recent_logs' => hic_get_recent_log_entries(20),
        'system_cron' => hic_check_system_cron(),
        'wp_cron_schedules' => hic_get_wp_cron_schedules(),
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
    
    $results = hic_force_reschedule_crons();
    wp_die(json_encode(array('success' => true, 'results' => $results)));
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
    $cron_status = hic_get_cron_status();
    $credentials_status = hic_get_credentials_status();
    $execution_stats = hic_get_execution_stats();
    $recent_logs = hic_get_recent_log_entries(20);
    $system_cron = hic_check_system_cron();
    $wp_cron_schedules = hic_get_wp_cron_schedules();
    $error_stats = hic_get_error_stats();
    
    ?>
    <div class="wrap">
        <h1>HIC Plugin Diagnostics</h1>
        
        <div class="hic-diagnostics-container">
            
            <!-- Cron Status Section -->
            <div class="card">
                <h2>Stato Cron Jobs</h2>
                <table class="widefat fixed" id="hic-cron-status">
                    <thead>
                        <tr>
                            <th>Evento</th>
                            <th>Schedulato</th>
                            <th>Prossima Esecuzione</th>
                            <th>Condizioni</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>hic_api_poll_event</td>
                            <td><span class="status <?php echo esc_attr($cron_status['poll_event']['scheduled'] ? 'scheduled' : 'not-scheduled'); ?>">
                                <?php echo esc_html($cron_status['poll_event']['scheduled'] ? 'Schedulato' : 'Non Schedulato'); ?>
                            </span></td>
                            <td><?php echo esc_html($cron_status['poll_event']['next_run_human']); ?></td>
                            <td><span class="status <?php echo esc_attr($cron_status['poll_event']['conditions_met'] ? 'ok' : 'error'); ?>">
                                <?php echo esc_html($cron_status['poll_event']['conditions_met'] ? 'OK' : 'Non Soddisfatte'); ?>
                            </span></td>
                            <td>
                                <button class="button manual-cron-test" data-event="hic_api_poll_event">Test Manuale</button>
                            </td>
                        </tr>
                        <tr>
                            <td>hic_api_updates_event</td>
                            <td><span class="status <?php echo esc_attr($cron_status['updates_event']['scheduled'] ? 'scheduled' : 'not-scheduled'); ?>">
                                <?php echo esc_html($cron_status['updates_event']['scheduled'] ? 'Schedulato' : 'Non Schedulato'); ?>
                            </span></td>
                            <td><?php echo esc_html($cron_status['updates_event']['next_run_human']); ?></td>
                            <td><span class="status <?php echo esc_attr($cron_status['updates_event']['conditions_met'] ? 'ok' : 'error'); ?>">
                                <?php echo esc_html($cron_status['updates_event']['conditions_met'] ? 'OK' : 'Non Soddisfatte'); ?>
                            </span></td>
                            <td>
                                <button class="button manual-cron-test" data-event="hic_api_updates_event">Test Manuale</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <p>
                    <button class="button button-secondary" id="refresh-diagnostics">Aggiorna Dati</button>
                    <button class="button" id="force-reschedule">Forza Rischedulazione</button>
                    <button class="button" id="test-dispatch">Test Dispatch Funzioni</button>
                </p>
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
                    if (!$cron_status['poll_event']['scheduled']) {
                        $manual_booking_issues[] = array(
                            'type' => 'error',
                            'message' => 'API Polling non schedulato: le prenotazioni manuali non verranno recuperate automaticamente.'
                        );
                    } else {
                        $manual_booking_issues[] = array(
                            'type' => 'info',
                            'message' => 'Modalità API Polling: le prenotazioni manuali vengono recuperate automaticamente ogni 5 minuti.'
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
                        <td>Prossimo Polling</td>
                        <td>
                            <?php if ($cron_status['poll_event']['scheduled']): ?>
                                <span class="status ok"><?php echo esc_html($cron_status['poll_event']['next_run_human']); ?></span>
                            <?php else: ?>
                                <span class="status error">Non schedulato</span>
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
                            <li><strong>Frequenza polling:</strong> Il sistema controlla nuove prenotazioni ogni 5 minuti</li>
                            <li><strong>Verifica credenziali:</strong> Assicurati che le credenziali API siano corrette</li>
                        <?php endif; ?>
                        <li><strong>Monitoraggio log:</strong> Controlla la sezione "Log Recenti" per errori o problemi</li>
                    </ul>
                </div>
            </div>
            
            <!-- System Cron Section -->
            <div class="card">
                <h2>Configurazione Cron di Sistema</h2>
                <table class="widefat">
                    <tr>
                        <td>WP Cron Disabilitato</td>
                        <td><span class="status <?php echo esc_attr($system_cron['wp_cron_disabled'] ? 'error' : 'ok'); ?>">
                            <?php echo esc_html($system_cron['wp_cron_disabled'] ? 'Sì (DISABLE_WP_CRON=true)' : 'No'); ?>
                        </span></td>
                    </tr>
                    <tr>
                        <td>Crontab Base</td>
                        <td><code><?php echo esc_html($system_cron['suggested_crontab']); ?></code></td>
                    </tr>
                    <tr>
                        <td>Crontab con Test</td>
                        <td><code><?php echo esc_html($system_cron['suggested_crontab_with_test']); ?></code></td>
                    </tr>
                    <tr>
                        <td>URL Test Cron</td>
                        <td><a href="<?php echo esc_url($system_cron['cron_test_url']); ?>" target="_blank"><?php echo esc_html($system_cron['cron_test_url']); ?></a></td>
                    </tr>
                </table>
                
                <?php if ($system_cron['wp_cron_disabled']): ?>
                <div class="notice notice-warning inline">
                    <p><strong>Attenzione:</strong> WP Cron è disabilitato. È necessario configurare un cron di sistema.</p>
                </div>
                <?php endif; ?>
                
                <h3>Istruzioni Setup Cron di Sistema</h3>
                <ol>
                    <li>Accedere al server via SSH</li>
                    <li>Eseguire: <code>crontab -e</code></li>
                    <li>Aggiungere la riga: <code><?php echo esc_html($system_cron['suggested_crontab']); ?></code></li>
                    <li>Per monitoraggio aggiuntivo, usare: <code><?php echo esc_html($system_cron['suggested_crontab_with_test']); ?></code></li>
                    <li>Salvare e uscire dall'editor</li>
                </ol>
                
                <h3>Verifica Funzionamento</h3>
                <p>Per verificare che il cron di sistema funzioni:</p>
                <ul>
                    <li>Attendere 5-10 minuti dopo la configurazione</li>
                    <li>Controllare i log di questo plugin per confermare l'esecuzione</li>
                    <li>Testare l'URL di test: <a href="<?php echo esc_url($system_cron['cron_test_url']); ?>" target="_blank">Clicca qui per testare</a></li>
                </ul>
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
                        <td>Email Arricchite</td>
                        <td><?php echo esc_html(number_format($execution_stats['enriched_emails'])); ?></td>
                    </tr>
                    <tr>
                        <td>File di Log</td>
                        <td><?php echo $execution_stats['log_file_exists'] ? esc_html('Esiste (' . size_format($execution_stats['log_file_size']) . ')') : 'Non trovato'; ?></td>
                    </tr>
                </table>
            </div>
            
            <!-- Error Summary Section -->
            <div class="card">
                <h2>Riepilogo Errori</h2>
                <table class="widefat">
                    <tr>
                        <td>Errori Recenti (ultimi 1000 log)</td>
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
                    <tr>
                        <td>Intervallo Polling Configurato</td>
                        <td><span class="status <?php echo esc_attr($wp_cron_schedules['hic_interval_exists'] ? 'ok' : 'error'); ?>">
                            <?php echo $wp_cron_schedules['hic_interval_exists'] ? 
                                esc_html($wp_cron_schedules['hic_interval_seconds'] . ' secondi') : esc_html('Non configurato'); ?>
                        </span></td>
                    </tr>
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
        .hic-diagnostics-container .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
            margin-bottom: 20px;
            padding: 15px;
        }
        .hic-diagnostics-container .card h2 {
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .status.ok { color: #46b450; font-weight: bold; }
        .status.error { color: #dc3232; font-weight: bold; }
        .status.warning { color: #ffb900; font-weight: bold; }
        .status.scheduled { color: #0073aa; font-weight: bold; }
        .status.not-scheduled { color: #ffb900; font-weight: bold; }
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
            padding: 10px;
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
        #hic-recent-logs div { 
            margin-bottom: 2px; 
            padding: 2px 0;
            border-bottom: 1px solid #eee;
        }
    </style>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Manual cron test handler
        $('.manual-cron-test').click(function() {
            var $btn = $(this);
            var event = $btn.data('event');
            var $results = $('#hic-test-results');
            
            $btn.prop('disabled', true).text('Eseguendo...');
            
            $.post(ajaxurl, {
                action: 'hic_manual_cron_test',
                nonce: '<?php echo wp_create_nonce('hic_diagnostics_nonce'); ?>',
                event: event
            }, function(response) {
                var result = JSON.parse(response);
                var messageClass = result.success ? 'notice-success' : 'notice-error';
                var html = '<div class="notice ' + messageClass + ' inline">' +
                          '<p><strong>' + event + ':</strong> ' + result.message;
                if (result.execution_time) {
                    html += ' (Tempo: ' + result.execution_time + ')';
                }
                html += '</p></div>';
                
                $results.html(html);
                $btn.prop('disabled', false).text('Test Manuale');
            });
        });
        
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
                $btn.prop('disabled', false).text('Forza Rischedulazione');
                
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
    });
    </script>
    <?php
}