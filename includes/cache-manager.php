<?php declare(strict_types=1);
/**
 * Enhanced Caching System for HIC Plugin
 *
 * Provides intelligent caching with automatic invalidation,
 * performance optimization, and memory management.
 */

namespace FpHic;

if (!defined('ABSPATH')) exit;

class HIC_Cache_Manager {

    private const CACHE_GROUP = 'hic_cache';
    private const DEFAULT_EXPIRATION = 3600; // 1 hour
    private const MAX_CACHE_SIZE = 1048576; // 1MB per cache entry
    private const TRACKED_OPTION = 'hic_cache_tracked_keys';

    /**
     * @var array<string,array{value:mixed,expires:int}>
     */
    private static $memory_cache = [];
    /**
     * @var array<string,bool>
     */
    private static array $tracked_keys = [];
    private static bool $tracked_loaded = false;
    /**
     * @var array<string,string>
     */
    private static array $namespace_cache = [];

    /**
     * Get cached data with fallback
     */
    public static function get($key, $default = null) {
        $cache_key = self::get_cache_key($key);

        // Try memory cache first (fastest)
        if (isset(self::$memory_cache[$cache_key])) {
            $data = self::$memory_cache[$cache_key];
            if (self::is_cache_valid($data)) {
                hic_log("Cache HIT (memory): $key", HIC_LOG_LEVEL_DEBUG);
                return $data['value'];
            }

            unset(self::$memory_cache[$cache_key]);
        }

        if (self::using_object_cache()) {
            $object_cached = wp_cache_get($cache_key, self::CACHE_GROUP);
            if (is_array($object_cached) && isset($object_cached['value'], $object_cached['expires'])) {
                if ((int) $object_cached['expires'] > time()) {
                    self::remember_in_memory($cache_key, $object_cached['value'], (int) $object_cached['expires'] - time());
                    hic_log("Cache HIT (object): $key", HIC_LOG_LEVEL_DEBUG);
                    return $object_cached['value'];
                }

                wp_cache_delete($cache_key, self::CACHE_GROUP);
                self::untrack_key($cache_key);
            }
        }

        // Try WordPress transient cache
        $cached_data = get_transient($cache_key);
        if ($cached_data !== false) {
            hic_log("Cache HIT (transient): $key", HIC_LOG_LEVEL_DEBUG);
            // Store in memory for faster subsequent access
            self::remember_in_memory($cache_key, $cached_data, self::DEFAULT_EXPIRATION);
            return $cached_data;
        }

        hic_log("Cache MISS: $key", HIC_LOG_LEVEL_DEBUG);
        return $default;
    }

    /**
     * Set cached data with expiration
     */
    public static function set($key, $value, $expiration = self::DEFAULT_EXPIRATION) {
        $cache_key = self::get_cache_key($key);

        // Validate cache size
        if (self::get_data_size($value) > self::MAX_CACHE_SIZE) {
            hic_log("Cache entry too large, skipping: $key", HIC_LOG_LEVEL_WARNING);
            return false;
        }

        // Set in memory cache
        self::remember_in_memory($cache_key, $value, $expiration);

        // Set in WordPress transient cache
        $result = set_transient($cache_key, $value, $expiration);

        if (self::using_object_cache()) {
            wp_cache_set(
                $cache_key,
                [
                    'value' => $value,
                    'expires' => time() + $expiration,
                ],
                self::CACHE_GROUP,
                $expiration
            );
        }

        self::track_key($cache_key);

        if ($result) {
            hic_log("Cache SET: $key (expires in {$expiration}s)", HIC_LOG_LEVEL_DEBUG);
        } else {
            hic_log("Cache SET failed: $key", HIC_LOG_LEVEL_WARNING);
        }

        return $result;
    }

    /**
     * Delete cached data
     */
    public static function delete($key) {
        $cache_key = self::get_cache_key($key);

        // Remove from memory cache
        unset(self::$memory_cache[$cache_key]);

        if (self::using_object_cache()) {
            wp_cache_delete($cache_key, self::CACHE_GROUP);
        }

        // Remove from transient cache
        $result = delete_transient($cache_key);

        self::untrack_key($cache_key);

        if ($result) {
            hic_log("Cache DELETE: $key", HIC_LOG_LEVEL_DEBUG);
        }

        return $result;
    }

    /**
     * Clear all cache entries
     */
    public static function clear_all() {
        global $wpdb;

        // Clear memory cache
        self::$memory_cache = [];

        if (self::using_object_cache()) {
            self::ensure_tracked_keys_loaded();
            foreach (array_keys(self::$tracked_keys) as $tracked_key) {
                wp_cache_delete($tracked_key, self::CACHE_GROUP);
            }
            wp_cache_delete(self::get_tracking_cache_key(), self::CACHE_GROUP);
        }

        // Clear WordPress transients
        $prefix = $wpdb->esc_like('_transient_' . HIC_CACHE_PREFIX);
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $prefix . '%'
            )
        );

        // Clear timeout transients
        $timeout_prefix = $wpdb->esc_like('_transient_timeout_' . HIC_CACHE_PREFIX);
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $timeout_prefix . '%'
            )
        );

        self::$tracked_keys = [];
        self::$tracked_loaded = true;
        self::persist_tracked_keys();

        hic_log("Cache cleared all entries");
        return true;
    }

    private static function remember_in_memory(string $cache_key, $value, int $ttl): void
    {
        self::$memory_cache[$cache_key] = [
            'value' => $value,
            'expires' => time() + max(1, $ttl),
        ];
    }

    private static function using_object_cache(): bool
    {
        return function_exists('wp_using_ext_object_cache')
            && wp_using_ext_object_cache()
            && function_exists('wp_cache_get')
            && function_exists('wp_cache_set')
            && function_exists('wp_cache_delete');
    }

    private static function track_key(string $cache_key): void
    {
        self::ensure_tracked_keys_loaded();

        if (!isset(self::$tracked_keys[$cache_key])) {
            self::$tracked_keys[$cache_key] = true;
            self::persist_tracked_keys();
        }
    }

    private static function untrack_key(string $cache_key): void
    {
        self::ensure_tracked_keys_loaded();

        if (isset(self::$tracked_keys[$cache_key])) {
            unset(self::$tracked_keys[$cache_key]);
            self::persist_tracked_keys();
        }
    }

    private static function ensure_tracked_keys_loaded(): void
    {
        if (self::$tracked_loaded) {
            return;
        }

        $keys = [];

        if (self::using_object_cache()) {
            $cachedKeys = wp_cache_get(self::get_tracking_cache_key(), self::CACHE_GROUP);
            if (is_array($cachedKeys)) {
                $keys = array_values(array_filter($cachedKeys, 'is_string'));
            }
        }

        if (empty($keys)) {
            $map = self::load_tracking_map();
            $namespace = self::get_cache_namespace();
            if (isset($map[$namespace]) && is_array($map[$namespace])) {
                $keys = $map[$namespace];
            } elseif (isset($map['__legacy__']) && is_array($map['__legacy__'])) {
                $keys = $map['__legacy__'];
            }
        }

        self::$tracked_keys = [];
        foreach ($keys as $key) {
            if (is_string($key) && $key !== '') {
                self::$tracked_keys[$key] = true;
            }
        }

        self::$tracked_loaded = true;
    }

    /**
     * Get cached data or compute if not exists
     */
    public static function remember($key, $callback, $expiration = self::DEFAULT_EXPIRATION) {
        $cached = self::get($key);

        if ($cached !== null) {
            return $cached;
        }

        // Compute value
        $value = call_user_func($callback);

        // Cache the result
        self::set($key, $value, $expiration);

        return $value;
    }

    /**
     * Cache API response with smart expiration
     */
    public static function cache_api_response($endpoint, $params, $response, $expiration = null) {
        // Create unique key for this API call
        $key = 'api_' . md5($endpoint . serialize($params));

        // Smart expiration based on response type
        if ($expiration === null) {
            if (is_wp_error($response)) {
                $expiration = 300; // Cache errors for 5 minutes only
            } elseif (self::is_dynamic_data($response)) {
                $expiration = 600; // Cache dynamic data for 10 minutes
            } else {
                $expiration = self::DEFAULT_EXPIRATION; // Default 1 hour
            }
        }

        return self::set($key, $response, $expiration);
    }

    /**
     * Get cached API response
     */
    public static function get_cached_api_response($endpoint, $params) {
        $key = 'api_' . md5($endpoint . serialize($params));
        return self::get($key);
    }

    /**
     * Cache reservation data with automatic invalidation
     */
    public static function cache_reservation($reservation_id, $data, $expiration = 1800) {
        $key = 'reservation_' . $reservation_id;
        return self::set($key, $data, $expiration); // 30 minutes for reservations
    }

    /**
     * Get cached reservation
     */
    public static function get_cached_reservation($reservation_id) {
        $key = 'reservation_' . $reservation_id;
        return self::get($key);
    }

    /**
     * Invalidate reservation cache when updated
     */
    public static function invalidate_reservation($reservation_id) {
        $key = 'reservation_' . $reservation_id;
        return self::delete($key);
    }

    /**
     * Get cache statistics
     */
    public static function get_stats() {
        global $wpdb;

        $prefix = $wpdb->esc_like('_transient_' . HIC_CACHE_PREFIX);
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                $prefix . '%'
            )
        );

        $memory_count = count(self::$memory_cache);
        $memory_size = self::get_memory_cache_size();

        return [
            'transient_entries' => intval($count),
            'memory_entries' => $memory_count,
            'memory_size_bytes' => $memory_size,
            'memory_size_human' => size_format($memory_size)
        ];
    }

    /**
     * Cleanup expired memory cache entries
     */
    public static function cleanup_memory_cache() {
        $current_time = time();
        $cleaned = 0;

        foreach (self::$memory_cache as $key => $data) {
            if (!self::is_cache_valid($data)) {
                unset(self::$memory_cache[$key]);
                $cleaned++;
            }
        }

        if ($cleaned > 0) {
            hic_log("Cleaned $cleaned expired memory cache entries", HIC_LOG_LEVEL_DEBUG);
        }

        return $cleaned;
    }

    /**
     * Generate cache key with prefix
     */
    private static function get_cache_key($key) {
        return HIC_CACHE_PREFIX . md5(self::get_cache_namespace() . '|' . $key);
    }

    private static function get_cache_namespace(): string
    {
        $identifier = self::determine_namespace_identifier();

        if (!isset(self::$namespace_cache[$identifier])) {
            $salt = '';
            if (defined('HIC_CACHE_NAMESPACE_SALT')) {
                $salt = trim((string) HIC_CACHE_NAMESPACE_SALT);
            }

            $parts = [$identifier];
            if ($salt !== '') {
                $parts[] = $salt;
            }

            self::$namespace_cache[$identifier] = implode('|', $parts);
        }

        return self::$namespace_cache[$identifier];
    }

    private static function determine_namespace_identifier(): string
    {
        if (defined('HIC_CACHE_NAMESPACE')) {
            $custom = trim((string) HIC_CACHE_NAMESPACE);
            if ($custom !== '') {
                return $custom;
            }
        }

        $blog_id = 0;

        if (function_exists('get_current_blog_id')) {
            $blog_id = (int) get_current_blog_id();
        } elseif (isset($GLOBALS['blog_id'])) {
            $blog_id = (int) $GLOBALS['blog_id'];
        }

        if ($blog_id <= 0 && defined('BLOG_ID_CURRENT_SITE')) {
            $blog_id = (int) BLOG_ID_CURRENT_SITE;
        }

        if ($blog_id <= 0) {
            $blog_id = 1;
        }

        return 'blog-' . $blog_id;
    }

    private static function get_tracking_cache_key(): string
    {
        return self::TRACKED_OPTION . ':' . md5(self::get_cache_namespace());
    }

    private static function persist_tracked_keys(): void
    {
        $keys = array_keys(self::$tracked_keys);
        $namespace = self::get_cache_namespace();

        if (self::using_object_cache()) {
            if (empty($keys)) {
                wp_cache_delete(self::get_tracking_cache_key(), self::CACHE_GROUP);
            } else {
                wp_cache_set(self::get_tracking_cache_key(), $keys, self::CACHE_GROUP, DAY_IN_SECONDS);
            }
        }

        $map = self::load_tracking_map();
        unset($map['__legacy__']);

        if (empty($keys)) {
            unset($map[$namespace]);
        } else {
            $map[$namespace] = $keys;
        }

        if (empty($map)) {
            delete_option(self::TRACKED_OPTION);
        } else {
            update_option(self::TRACKED_OPTION, $map, false);
        }
    }

    /**
     * @return array<string,array<int,string>>
     */
    private static function load_tracking_map(): array
    {
        $stored = get_option(self::TRACKED_OPTION, []);

        if (!is_array($stored)) {
            return [];
        }

        if (self::is_sequential_array($stored)) {
            $filtered = [];
            foreach ($stored as $value) {
                if (is_string($value) && $value !== '') {
                    $filtered[] = $value;
                }
            }

            return ['__legacy__' => $filtered];
        }

        foreach ($stored as $namespace => $keys) {
            if (!is_array($keys)) {
                unset($stored[$namespace]);
                continue;
            }

            $filtered = [];
            foreach ($keys as $value) {
                if (is_string($value) && $value !== '') {
                    $filtered[] = $value;
                }
            }

            $stored[$namespace] = $filtered;
        }

        return $stored;
    }

    private static function is_sequential_array(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * Check if cached data is still valid
     */
    private static function is_cache_valid($data) {
        return isset($data['expires']) && $data['expires'] > time();
    }

    /**
     * Check if response contains dynamic data that should expire sooner
     */
    private static function is_dynamic_data($data) {
        // Check for timestamps, status fields, or other indicators of dynamic data
        if (is_array($data)) {
            $dynamic_indicators = ['updated_at', 'status', 'last_modified', 'timestamp'];
            foreach ($dynamic_indicators as $indicator) {
                if (isset($data[$indicator])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Calculate data size for cache validation
     */
    private static function get_data_size($data) {
        return strlen(serialize($data));
    }

    /**
     * Get memory cache size
     */
    private static function get_memory_cache_size() {
        $size = 0;
        foreach (self::$memory_cache as $data) {
            $size += self::get_data_size($data);
        }
        return $size;
    }
}

// Hook for periodic cleanup - only register if WordPress functions are available
if (function_exists('add_action')) {
    add_action('hic_cleanup_event', function() {
        \FpHic\HIC_Cache_Manager::cleanup_memory_cache();
    });
}
