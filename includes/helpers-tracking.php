<?php declare(strict_types=1);

namespace FpHic\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

// Additive includes for modular helpers (cache utilities, privacy exporters)
require_once __DIR__ . '/helpers/tracking-cache.php';
require_once __DIR__ . '/helpers/privacy-exporters.php';

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
    $wpdb = hic_get_wpdb_instance(['get_var', 'prepare', 'get_results']);

    if (!$wpdb) {
        return ['data' => [], 'done' => true];
    }

    $table = hic_sanitize_identifier($wpdb->prefix . 'hic_gclids', 'table');
    if (!hic_tracking_table_exists($wpdb)) {
        return ['data' => [], 'done' => true];
    }

    $email = sanitize_email($email_address);
    if (empty($email)) {
        return ['data' => [], 'done' => true];
    }

    $column_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'email'));
    if (!$column_exists) {
        return ['data' => [], 'done' => true];
    }

    $number = 50;
    $offset = ($page - 1) * $number;
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, gclid, fbclid, sid, utm_source, utm_medium, utm_campaign, utm_content, utm_term, created_at FROM `{$table}` WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
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
    $wpdb = hic_get_wpdb_instance(['get_var', 'prepare', 'get_results', 'delete']);

    if (!$wpdb) {
        return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    $table = hic_sanitize_identifier($wpdb->prefix . 'hic_gclids', 'table');
    if (!hic_tracking_table_exists($wpdb)) {
        return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    $email = sanitize_email($email_address);
    if (empty($email)) {
        return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    $column_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'email'));
    if (!$column_exists) {
        return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    $number = 50;
    $offset = ($page - 1) * $number;
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
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

// -------------------------------------------------------------------------
// Tracking lookup cache helpers
// -------------------------------------------------------------------------

/** @var array<string,bool> */
$GLOBALS['hic_tracking_table_runtime_cache'] = $GLOBALS['hic_tracking_table_runtime_cache'] ?? [];

/** @var array<string,bool> */
$GLOBALS['hic_tracking_table_rebuild_attempted'] = $GLOBALS['hic_tracking_table_rebuild_attempted'] ?? [];

// functions hic_tracking_cache_group(), hic_get_tracking_lookup_cache(), etc. are now loaded from tracking-cache.php

function hic_flush_tracking_table_state(?string $prefix = null): void
{
    $runtime = &$GLOBALS['hic_tracking_table_runtime_cache'];
    $attempted = &$GLOBALS['hic_tracking_table_rebuild_attempted'];

    if ($prefix === null) {
        $runtime = [];
        $attempted = [];
    } else {
        unset($runtime[$prefix], $attempted[$prefix]);
    }

    if (!function_exists('wp_cache_delete')) {
        return;
    }

    if ($prefix === null) {
        return;
    }

    wp_cache_delete(hic_tracking_table_cache_key($prefix), hic_tracking_cache_group());
}

/**
 * Determine if the plugin tracking table exists for the provided wpdb-like instance.
 *
 * @param \wpdb|object $wpdb Object exposing a wpdb-compatible API (prefix property plus prepare/get_var methods).
 */
function hic_tracking_table_exists($wpdb, bool $attempt_rebuild = true): bool
{
    if (!is_object($wpdb)) {
        return false;
    }

    if (!method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')) {
        return false;
    }

    $prefix = property_exists($wpdb, 'prefix') ? (string) $wpdb->prefix : '';
    if ($prefix === '') {
        return false;
    }

    $runtime = &$GLOBALS['hic_tracking_table_runtime_cache'];
    $attempted = &$GLOBALS['hic_tracking_table_rebuild_attempted'];

    if (isset($runtime[$prefix]) && $runtime[$prefix] === true) {
        return true;
    }

    $cache_found = false;
    $cached_state = null;
    if (function_exists('wp_cache_get')) {
        $cached_state = wp_cache_get(hic_tracking_table_cache_key($prefix), hic_tracking_cache_group(), false, $cache_found);
    }

    if ($cache_found) {
        $runtime[$prefix] = (bool) $cached_state;

        if ($runtime[$prefix] === true || !$attempt_rebuild) {
            return $runtime[$prefix];
        }
    }

    $table = $wpdb->prefix . 'hic_gclids';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;

    if (!$exists && $attempt_rebuild) {
        if (!isset($attempted[$prefix]) && function_exists('\\hic_create_database_table')) {
            $attempted[$prefix] = true;
            if (\hic_create_database_table()) {
                $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
            }
        }
    }

    $runtime[$prefix] = $exists;

    if (function_exists('wp_cache_set')) {
        wp_cache_set(
            hic_tracking_table_cache_key($prefix),
            $exists ? 1 : 0,
            hic_tracking_cache_group(),
            hic_tracking_table_cache_ttl()
        );
    }

    return $exists;
}

/**
 * Retrieve tracking IDs (gclid and fbclid) for a given SID from database.
 *
 * @param string $sid Session identifier
 * @return array{gclid:?string, fbclid:?string, msclkid:?string, ttclid:?string, gbraid:?string, wbraid:?string}
*/
function hic_get_tracking_ids_by_sid($sid) {
    static $runtime_cache = [];

    $sid = sanitize_text_field($sid);
    if (empty($sid)) {
        return ['gclid' => null, 'fbclid' => null, 'msclkid' => null, 'ttclid' => null, 'gbraid' => null, 'wbraid' => null];
    }

    if (array_key_exists($sid, $runtime_cache)) {
        return $runtime_cache[$sid];
    }

    $cached = hic_get_tracking_lookup_cache('tracking', $sid, $found);
    if ($found && is_array($cached)) {
        return $runtime_cache[$sid] = $cached;
    }

    $wpdb = hic_get_wpdb_instance(['get_var', 'prepare', 'get_row']);
    if (!$wpdb) {
        hic_log('hic_get_tracking_ids_by_sid: wpdb is not available');
        $value = ['gclid' => null, 'fbclid' => null, 'msclkid' => null, 'ttclid' => null, 'gbraid' => null, 'wbraid' => null];
        if ($found === false) {
            hic_set_tracking_lookup_cache('tracking', $sid, $value);
        }

        return $runtime_cache[$sid] = $value;
    }

    if (!hic_tracking_table_exists($wpdb)) {
        hic_log('hic_get_tracking_ids_by_sid: Table does not exist: ' . $wpdb->prefix . 'hic_gclids');
        $value = ['gclid' => null, 'fbclid' => null, 'msclkid' => null, 'ttclid' => null, 'gbraid' => null, 'wbraid' => null];
        hic_set_tracking_lookup_cache('tracking', $sid, $value);

        return $runtime_cache[$sid] = $value;
    }

    $table = hic_sanitize_identifier($wpdb->prefix . 'hic_gclids', 'table');
    $row = $wpdb->get_row($wpdb->prepare("SELECT gclid, fbclid, msclkid, ttclid, gbraid, wbraid FROM `{$table}` WHERE sid=%s ORDER BY id DESC LIMIT 1", $sid));

    if ($wpdb->last_error) {
        hic_log('hic_get_tracking_ids_by_sid: Database error retrieving tracking IDs: ' . $wpdb->last_error);
        $value = ['gclid' => null, 'fbclid' => null, 'msclkid' => null, 'ttclid' => null, 'gbraid' => null, 'wbraid' => null];
        hic_set_tracking_lookup_cache('tracking', $sid, $value);

        return $runtime_cache[$sid] = $value;
    }

    if ($row) {
        $value = [
            'gclid' => $row->gclid,
            'fbclid' => $row->fbclid,
            'msclkid' => $row->msclkid,
            'ttclid' => $row->ttclid,
            'gbraid' => $row->gbraid,
            'wbraid' => $row->wbraid,
        ];
        hic_set_tracking_lookup_cache('tracking', $sid, $value);

        return $runtime_cache[$sid] = $value;
    }

    $value = ['gclid' => null, 'fbclid' => null, 'msclkid' => null, 'ttclid' => null, 'gbraid' => null, 'wbraid' => null];
    hic_set_tracking_lookup_cache('tracking', $sid, $value);

    return $runtime_cache[$sid] = $value;
}

/**
 * Retrieve UTM parameters for a given SID from database.
 *
 * @param string $sid Session identifier
 * @return array{utm_source:?string, utm_medium:?string, utm_campaign:?string, utm_content:?string, utm_term:?string}
*/
function hic_get_utm_params_by_sid($sid) {
    static $runtime_cache = [];

    $sid = sanitize_text_field($sid);
    if (empty($sid)) {
        return ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_content' => null, 'utm_term' => null];
    }

    if (array_key_exists($sid, $runtime_cache)) {
        return $runtime_cache[$sid];
    }

    $cached = hic_get_tracking_lookup_cache('utm', $sid, $found);
    if ($found && is_array($cached)) {
        return $runtime_cache[$sid] = $cached;
    }

    $wpdb = hic_get_wpdb_instance(['get_var', 'prepare', 'get_row']);
    if (!$wpdb) {
        hic_log('hic_get_utm_params_by_sid: wpdb is not available');
        $value = ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_content' => null, 'utm_term' => null];
        if ($found === false) {
            hic_set_tracking_lookup_cache('utm', $sid, $value);
        }

        return $runtime_cache[$sid] = $value;
    }

    if (!hic_tracking_table_exists($wpdb)) {
        hic_log('hic_get_utm_params_by_sid: Table does not exist: ' . $wpdb->prefix . 'hic_gclids');
        $value = ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_content' => null, 'utm_term' => null];
        hic_set_tracking_lookup_cache('utm', $sid, $value);

        return $runtime_cache[$sid] = $value;
    }

    $table = hic_sanitize_identifier($wpdb->prefix . 'hic_gclids', 'table');

    $row = $wpdb->get_row($wpdb->prepare("SELECT utm_source, utm_medium, utm_campaign, utm_content, utm_term FROM `{$table}` WHERE sid=%s ORDER BY id DESC LIMIT 1", $sid));

    if ($wpdb->last_error) {
        hic_log('hic_get_utm_params_by_sid: Database error retrieving UTM params: ' . $wpdb->last_error);
        $value = ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_content' => null, 'utm_term' => null];
        hic_set_tracking_lookup_cache('utm', $sid, $value);

        return $runtime_cache[$sid] = $value;
    }

    if ($row) {
        $value = [
            'utm_source'   => $row->utm_source,
            'utm_medium'   => $row->utm_medium,
            'utm_campaign' => $row->utm_campaign,
            'utm_content'  => $row->utm_content,
            'utm_term'     => $row->utm_term,
        ];
        hic_set_tracking_lookup_cache('utm', $sid, $value);

        return $runtime_cache[$sid] = $value;
    }

    $value = ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_content' => null, 'utm_term' => null];
    hic_set_tracking_lookup_cache('utm', $sid, $value);

    return $runtime_cache[$sid] = $value;
}

/**
 * Normalize bucket attribution according to priority: gclid > gbraid/wbraid > fbclid > organic
 *
 * @param string|null $gclid  Google Click ID from Google Ads
 * @param string|null $fbclid Facebook Click ID from Meta Ads
 * @param string|null $gbraid Google Ads GBRAID identifier
 * @param string|null $wbraid Google Ads WBRAID identifier
 * @return string One of: 'gads', 'fbads', 'organic'
 */
function fp_normalize_bucket($gclid, $fbclid, $gbraid = null, $wbraid = null){
  foreach ([$gclid, $gbraid, $wbraid] as $google_identifier) {
    if (is_string($google_identifier) || is_numeric($google_identifier)) {
      $normalized = trim((string) $google_identifier);
      if ($normalized !== '') {
        return 'gads';
      }
    }
  }

  if (is_string($fbclid) || is_numeric($fbclid)) {
    $facebook_identifier = trim((string) $fbclid);
    if ($facebook_identifier !== '') {
      return 'fbads';
    }
  }

  return 'organic';
}

/**
 * Legacy function name for backward compatibility
 * @deprecated Use fp_normalize_bucket() instead
 */
function hic_get_bucket($gclid, $fbclid, $gbraid = null, $wbraid = null){
  return fp_normalize_bucket($gclid, $fbclid, $gbraid, $wbraid);
}

