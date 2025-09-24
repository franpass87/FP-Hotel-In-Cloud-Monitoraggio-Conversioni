<?php declare(strict_types=1);

namespace FpHic\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

function hic_register_exporter($exporters) {
    $exporters['hic-tracking-data'] = [
        'exporter_friendly_name' => __('Dati di tracciamento HIC', 'hotel-in-cloud'),
        'callback' => __NAMESPACE__ . '\\hic_export_tracking_data',
    ];
    return $exporters;
}

function hic_register_eraser($erasers) {
    $erasers['hic-tracking-data'] = [
        'eraser_friendly_name' => __('Dati di tracciamento HIC', 'hotel-in-cloud'),
        'callback' => __NAMESPACE__ . '\\hic_erase_tracking_data',
    ];
    return $erasers;
}

/**
 * Export tracking data associated with an email address.
 */
function hic_export_tracking_data($email_address, $page = 1) {
    global $wpdb;

    if (!$wpdb) {
        return ['data' => [], 'done' => true];
    }

    $table = $wpdb->prefix . 'hic_gclids';
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    if (!$table_exists) {
        return ['data' => [], 'done' => true];
    }

    $email = sanitize_email($email_address);
    if (empty($email)) {
        return ['data' => [], 'done' => true];
    }

    $column_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'email'));
    if (!$column_exists) {
        return ['data' => [], 'done' => true];
    }

    $number = 50;
    $offset = ($page - 1) * $number;
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, gclid, fbclid, sid, utm_source, utm_medium, utm_campaign, utm_content, utm_term, created_at FROM $table WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
            $email,
            $number,
            $offset
        )
    );

    $data = [];
    foreach ($rows as $row) {
        $item = [];
        if ($row->gclid !== null) {
            $item[] = ['name' => 'gclid', 'value' => $row->gclid];
        }
        if ($row->fbclid !== null) {
            $item[] = ['name' => 'fbclid', 'value' => $row->fbclid];
        }
        if ($row->sid !== null) {
            $item[] = ['name' => 'sid', 'value' => $row->sid];
        }
        if ($row->utm_source !== null) {
            $item[] = ['name' => 'utm_source', 'value' => $row->utm_source];
        }
        if ($row->utm_medium !== null) {
            $item[] = ['name' => 'utm_medium', 'value' => $row->utm_medium];
        }
        if ($row->utm_campaign !== null) {
            $item[] = ['name' => 'utm_campaign', 'value' => $row->utm_campaign];
        }
        if ($row->utm_content !== null) {
            $item[] = ['name' => 'utm_content', 'value' => $row->utm_content];
        }
        if ($row->utm_term !== null) {
            $item[] = ['name' => 'utm_term', 'value' => $row->utm_term];
        }
        if ($row->created_at !== null) {
            $item[] = ['name' => 'created_at', 'value' => $row->created_at];
        }

        $data[] = [
            'group_id'    => 'hic_tracking_data',
            'group_label' => __('Dati di tracciamento HIC', 'hotel-in-cloud'),
            'item_id'     => 'tracking-' . $row->id,
            'data'        => $item,
        ];
    }

    $done = count($rows) < $number;

    return ['data' => $data, 'done' => $done];
}

/**
 * Erase tracking data associated with an email address.
 */
function hic_erase_tracking_data($email_address, $page = 1) {
    global $wpdb;

    if (!$wpdb) {
        return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    $table = $wpdb->prefix . 'hic_gclids';
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    if (!$table_exists) {
        return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    $email = sanitize_email($email_address);
    if (empty($email)) {
        return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    $column_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", 'email'));
    if (!$column_exists) {
        return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    $number = 50;
    $offset = ($page - 1) * $number;
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id FROM $table WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
            $email,
            $number,
            $offset
        )
    );

    $items_removed = false;
    foreach ($rows as $row) {
        $deleted = $wpdb->delete($table, ['id' => $row->id], ['%d']);
        if ($deleted) {
            $items_removed = true;
        }
    }

    $done = count($rows) < $number;

    return [
        'items_removed'  => $items_removed,
        'items_retained' => false,
        'messages'       => [],
        'done'           => $done,
    ];
}

/**
 * Retrieve tracking IDs (gclid and fbclid) for a given SID from database.
 *
 * @param string $sid Session identifier
 * @return array{gclid:?string, fbclid:?string, msclkid:?string, ttclid:?string, gbraid:?string, wbraid:?string}
*/
function hic_get_tracking_ids_by_sid($sid) {
    static $cache = [];
    $sid = sanitize_text_field($sid);
    if (empty($sid)) {
        return ['gclid' => null, 'fbclid' => null, 'msclkid' => null, 'ttclid' => null, 'gbraid' => null, 'wbraid' => null];
    }

    if (array_key_exists($sid, $cache)) {
        return $cache[$sid];
    }

    global $wpdb;
    if (!$wpdb) {
        hic_log('hic_get_tracking_ids_by_sid: wpdb is not available');
        return $cache[$sid] = ['gclid' => null, 'fbclid' => null, 'msclkid' => null, 'ttclid' => null, 'gbraid' => null, 'wbraid' => null];
    }

    $table = $wpdb->prefix . 'hic_gclids';

    // Ensure table exists before querying
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    if (!$table_exists) {
        static $rebuild_attempted = false;

        if (!$rebuild_attempted && function_exists('\\hic_create_database_table')) {
            $rebuild_attempted = true;
            \hic_create_database_table();
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
        }

        if (!$table_exists) {
            hic_log('hic_get_tracking_ids_by_sid: Table does not exist: ' . $table);
            return $cache[$sid] = ['gclid' => null, 'fbclid' => null, 'msclkid' => null, 'ttclid' => null, 'gbraid' => null, 'wbraid' => null];
        }
    }
    $row = $wpdb->get_row($wpdb->prepare("SELECT gclid, fbclid, msclkid, ttclid, gbraid, wbraid FROM $table WHERE sid=%s ORDER BY id DESC LIMIT 1", $sid));

    if ($wpdb->last_error) {
        hic_log('hic_get_tracking_ids_by_sid: Database error retrieving tracking IDs: ' . $wpdb->last_error);
        return $cache[$sid] = ['gclid' => null, 'fbclid' => null, 'msclkid' => null, 'ttclid' => null, 'gbraid' => null, 'wbraid' => null];
    }

    if ($row) {
        return $cache[$sid] = [
            'gclid' => $row->gclid,
            'fbclid' => $row->fbclid,
            'msclkid' => $row->msclkid,
            'ttclid' => $row->ttclid,
            'gbraid' => $row->gbraid,
            'wbraid' => $row->wbraid,
        ];
    }

    return $cache[$sid] = ['gclid' => null, 'fbclid' => null, 'msclkid' => null, 'ttclid' => null, 'gbraid' => null, 'wbraid' => null];
}

/**
 * Retrieve UTM parameters for a given SID from database.
 *
 * @param string $sid Session identifier
 * @return array{utm_source:?string, utm_medium:?string, utm_campaign:?string, utm_content:?string, utm_term:?string}
*/
function hic_get_utm_params_by_sid($sid) {
    static $cache = [];
    $sid = sanitize_text_field($sid);
    if (empty($sid)) {
        return ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_content' => null, 'utm_term' => null];
    }

    if (array_key_exists($sid, $cache)) {
        return $cache[$sid];
    }

    global $wpdb;
    if (!$wpdb) {
        hic_log('hic_get_utm_params_by_sid: wpdb is not available');
        return $cache[$sid] = ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_content' => null, 'utm_term' => null];
    }

    $table = $wpdb->prefix . 'hic_gclids';

    // Ensure table exists before querying
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    if (!$table_exists) {
        static $utm_rebuild_attempted = false;

        if (!$utm_rebuild_attempted && function_exists('\\hic_create_database_table')) {
            $utm_rebuild_attempted = true;
            \hic_create_database_table();
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
        }

        if (!$table_exists) {
            hic_log('hic_get_utm_params_by_sid: Table does not exist: ' . $table);
            return $cache[$sid] = ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_content' => null, 'utm_term' => null];
        }
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT utm_source, utm_medium, utm_campaign, utm_content, utm_term FROM $table WHERE sid=%s ORDER BY id DESC LIMIT 1", $sid));

    if ($wpdb->last_error) {
        hic_log('hic_get_utm_params_by_sid: Database error retrieving UTM params: ' . $wpdb->last_error);
        return $cache[$sid] = ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_content' => null, 'utm_term' => null];
    }

    if ($row) {
        return $cache[$sid] = [
            'utm_source'   => $row->utm_source,
            'utm_medium'   => $row->utm_medium,
            'utm_campaign' => $row->utm_campaign,
            'utm_content'  => $row->utm_content,
            'utm_term'     => $row->utm_term,
        ];
    }

    return $cache[$sid] = ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_content' => null, 'utm_term' => null];
}

/**
 * Normalize bucket attribution according to priority: gclid > fbclid > organic
 *
 * @param string|null $gclid Google Click ID from Google Ads
 * @param string|null $fbclid Facebook Click ID from Meta Ads
 * @return string One of: 'gads', 'fbads', 'organic'
 */
function fp_normalize_bucket($gclid, $fbclid){
  if (!empty($gclid) && trim($gclid) !== '')  return 'gads';
  if (!empty($fbclid) && trim($fbclid) !== '') return 'fbads';
  return 'organic';
}

/**
 * Legacy function name for backward compatibility
 * @deprecated Use fp_normalize_bucket() instead
 */
function hic_get_bucket($gclid, $fbclid){
  return fp_normalize_bucket($gclid, $fbclid);
}

