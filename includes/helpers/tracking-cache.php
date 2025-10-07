<?php declare(strict_types=1);

namespace FpHic\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tracking cache utilities extracted for modularity.
 */

function hic_tracking_cache_group(): string
{
    return defined('HIC_TRACKING_CACHE_GROUP') ? HIC_TRACKING_CACHE_GROUP : 'hic_monitor_tracking';
}

function hic_tracking_lookup_cache_ttl(string $context, string $sid): int
{
    $default = defined('HIC_TRACKING_LOOKUP_CACHE_TTL') ? (int) HIC_TRACKING_LOOKUP_CACHE_TTL : 5 * MINUTE_IN_SECONDS;

    return (int) apply_filters('hic_tracking_lookup_cache_ttl', $default, $context, $sid);
}

function hic_tracking_table_cache_ttl(): int
{
    $default = defined('HIC_TRACKING_TABLE_CACHE_TTL') ? (int) HIC_TRACKING_TABLE_CACHE_TTL : 10 * MINUTE_IN_SECONDS;

    return (int) apply_filters('hic_tracking_table_cache_ttl', $default);
}

function hic_tracking_cache_key(string $sid, string $suffix): string
{
    return $suffix . ':' . md5($sid);
}

function hic_tracking_table_cache_key(string $prefix): string
{
    return 'table_exists:' . md5($prefix);
}

/**
 * Retrieve a cached lookup from the object cache if available.
 *
 * @return array<string,?string>|null
 */
function hic_get_tracking_lookup_cache(string $suffix, string $sid, ?bool &$found = null): ?array
{
    $found = false;

    if (!function_exists('wp_cache_get')) {
        return null;
    }

    $cache = wp_cache_get(hic_tracking_cache_key($sid, $suffix), hic_tracking_cache_group(), false, $found);

    if (!$found) {
        return null;
    }

    return is_array($cache) ? $cache : null;
}

function hic_set_tracking_lookup_cache(string $suffix, string $sid, array $value): void
{
    if (!function_exists('wp_cache_set')) {
        return;
    }

    wp_cache_set(
        hic_tracking_cache_key($sid, $suffix),
        $value,
        hic_tracking_cache_group(),
        hic_tracking_lookup_cache_ttl($suffix, $sid)
    );
}

function hic_flush_tracking_cache(string $sid): void
{
    if (!function_exists('wp_cache_delete')) {
        return;
    }

    $group = hic_tracking_cache_group();
    wp_cache_delete(hic_tracking_cache_key($sid, 'tracking'), $group);
    wp_cache_delete(hic_tracking_cache_key($sid, 'utm'), $group);
}


