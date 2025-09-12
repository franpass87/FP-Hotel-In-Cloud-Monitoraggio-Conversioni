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
    
    private static $memory_cache = [];
    
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
            } else {
                unset(self::$memory_cache[$cache_key]);
            }
        }
        
        // Try WordPress transient cache
        $cached_data = get_transient($cache_key);
        if ($cached_data !== false) {
            hic_log("Cache HIT (transient): $key", HIC_LOG_LEVEL_DEBUG);
            // Store in memory for faster subsequent access
            self::$memory_cache[$cache_key] = [
                'value' => $cached_data,
                'expires' => time() + self::DEFAULT_EXPIRATION
            ];
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
        self::$memory_cache[$cache_key] = [
            'value' => $value,
            'expires' => time() + $expiration
        ];
        
        // Set in WordPress transient cache
        $result = set_transient($cache_key, $value, $expiration);
        
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
        
        // Remove from transient cache
        $result = delete_transient($cache_key);
        
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
        
        hic_log("Cache cleared all entries");
        return true;
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
        return HIC_CACHE_PREFIX . md5($key);
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