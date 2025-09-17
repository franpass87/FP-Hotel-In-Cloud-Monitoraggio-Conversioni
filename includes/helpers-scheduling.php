<?php declare(strict_types=1);

namespace FpHic\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if retry event should be scheduled based on conditions
 */
function hic_should_schedule_retry_event() {
    if (!hic_realtime_brevo_sync_enabled()) {
        return false;
    }

    if (!hic_get_brevo_api_key()) {
        return false;
    }

    $schedules = wp_get_schedules();
    return isset($schedules['hic_every_fifteen_minutes']);
}

/* ================= SAFE WORDPRESS CRON HELPERS ================= */

/**
 * Safely check if an event is scheduled
 */
function hic_safe_wp_next_scheduled($hook) {
    if (!function_exists('wp_next_scheduled')) {
        return false;
    }
    return wp_next_scheduled($hook);
}

/**
 * Safely schedule an event
 */
function hic_safe_wp_schedule_event($timestamp, $recurrence, $hook, $args = array()) {
    if (!function_exists('wp_schedule_event')) {
        return false;
    }
    $result = wp_schedule_event($timestamp, $recurrence, $hook, $args, true);
    if (is_wp_error($result)) {
        hic_log('Scheduling error for ' . $hook . ': ' . $result->get_error_message(), HIC_LOG_LEVEL_ERROR);
        return false;
    }
    return $result;
}

/**
 * Safely clear scheduled hooks
 */
function hic_safe_wp_clear_scheduled_hook($hook, $args = array()) {
    if (!function_exists('wp_clear_scheduled_hook')) {
        return false;
    }
    return wp_clear_scheduled_hook($hook, $args);
}

function hic_add_failed_request_schedule($schedules) {
    $schedules['hic_every_fifteen_minutes'] = array(
        'interval' => 15 * 60,
        'display'  => 'Every 15 Minutes (HIC Failed Requests)'
    );

    if (!isset($schedules['weekly'])) {
        $schedules['weekly'] = array(
            'interval' => 7 * 24 * 60 * 60,
            'display'  => 'Once Weekly (Hotel in Cloud)'
        );
    }

    if (!isset($schedules['monthly'])) {
        $schedules['monthly'] = array(
            'interval' => 30 * 24 * 60 * 60,
            'display'  => 'Once Monthly (Hotel in Cloud)'
        );
    }

    return $schedules;
}

function hic_schedule_failed_request_retry() {
    if (!hic_should_schedule_retry_event()) {
        return;
    }

    if (!hic_safe_wp_next_scheduled('hic_retry_failed_requests')) {
        hic_safe_wp_schedule_event(time(), 'hic_every_fifteen_minutes', 'hic_retry_failed_requests');
    }
}

function hic_retry_failed_requests() {
    global $wpdb;
    if (!$wpdb) {
        return;
    }

    $table = esc_sql($wpdb->prefix . 'hic_failed_requests');
    $rows  = $wpdb->get_results("SELECT * FROM $table");

    if (empty($rows)) {
        return;
    }

    foreach ($rows as $row) {
        if ($row->attempts >= 5) {
            $wpdb->delete($table, array('id' => $row->id));
            continue;
        }

        $delay     = 15 * 60 * pow(2, max(0, $row->attempts - 1));
        $next_time = strtotime($row->last_try) + $delay;
        if (time() < $next_time) {
            continue;
        }

        $args = json_decode($row->payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error_message = 'JSON decode error: ' . json_last_error_msg();
            hic_log('Retry failed for ' . $row->endpoint . ': ' . $error_message, HIC_LOG_LEVEL_ERROR);
            $wpdb->delete($table, array('id' => $row->id));
            continue;
        }
        $response = hic_http_request($row->endpoint, is_array($args) ? $args : array());

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 400) {
            $error_message = is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response);
            $wpdb->update(
                $table,
                array(
                    'attempts'   => $row->attempts + 1,
                    'last_error' => $error_message,
                    'last_try'   => current_time('mysql'),
                ),
                array('id' => $row->id),
                array('%d', '%s', '%s'),
                array('%d')
            );
            hic_log('Retry failed for ' . $row->endpoint . ': ' . $error_message, HIC_LOG_LEVEL_ERROR);
            if ($row->attempts + 1 >= 5) {
                $wpdb->delete($table, array('id' => $row->id));
            }
        } else {
            hic_log('Retry succeeded for ' . $row->endpoint);
            $wpdb->delete($table, array('id' => $row->id));
        }
    }
}

function hic_cleanup_failed_requests($days = 30) {
    if ($days <= 0) {
        return 0;
    }

    global $wpdb;
    if (!$wpdb) {
        hic_log('hic_cleanup_failed_requests: wpdb is not available');
        return false;
    }

    $table  = $wpdb->prefix . 'hic_failed_requests';
    $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

    $deleted = $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM $table WHERE last_try < %s",
            $cutoff
        )
    );

    if ($deleted === false) {
        hic_log('hic_cleanup_failed_requests: Database error: ' . $wpdb->last_error);
        return false;
    }

    hic_log("hic_cleanup_failed_requests: Removed $deleted records older than $days days");
    return $deleted;
}

function hic_schedule_failed_request_cleanup() {
    if (!hic_safe_wp_next_scheduled('hic_cleanup_failed_requests')) {
        hic_safe_wp_schedule_event(time(), 'daily', 'hic_cleanup_failed_requests');
    }
}

