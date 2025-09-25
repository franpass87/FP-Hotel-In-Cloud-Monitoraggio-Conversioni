<?php declare(strict_types=1);
/**
 * Constants and Configuration for HIC Plugin
 * 
 * This file centralizes all magic numbers and configuration values
 * used throughout the plugin to improve maintainability.
 */

if (!defined('ABSPATH')) exit;

// Provide fallbacks for WordPress time constants when the plugin is loaded
// in non-WordPress environments (e.g. automated tests).
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS);
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS);
}

// === POLLING INTERVALS ===
define('HIC_CONTINUOUS_POLLING_INTERVAL', 30);    // 30 seconds for near real-time polling (optimized for limited hosting)
define('HIC_DEEP_CHECK_INTERVAL', 1800);          // 30 minutes for deep check (re-enabled for safety)
define('HIC_DEEP_CHECK_LOOKBACK_DAYS', 5);        // Look back 5 days in deep check
define('HIC_WATCHDOG_THRESHOLD', 300);            // 5 minutes threshold
define('HIC_FALLBACK_POLLING_INTERVAL', 120);     // 2 minutes fallback

// === API LIMITS & TIMEOUTS ===
define('HIC_API_TIMEOUT', 30);                    // 30 seconds API timeout
define('HIC_API_MAX_RETRIES', 3);                 // Maximum retry attempts
define('HIC_API_RATE_LIMIT_DELAY', 1);            // 1 second between requests
define('HIC_API_BATCH_SIZE', 50);                 // Maximum records per API call

// === LOG MANAGEMENT ===
define('HIC_LOG_MAX_SIZE', 10485760);             // 10MB maximum log file size
define('HIC_LOG_RETENTION_DAYS', 30);             // Keep logs for 30 days
define('HIC_LOG_LEVEL_ERROR', 'error');
define('HIC_LOG_LEVEL_WARNING', 'warning');
define('HIC_LOG_LEVEL_INFO', 'info');
define('HIC_LOG_LEVEL_DEBUG', 'debug');
// Default log level is configured via the 'hic_log_level' option

// === DATA RETENTION ===
define('HIC_RETENTION_GCLID_DAYS', 90);           // Keep tracking identifiers for 90 days
define('HIC_RETENTION_BOOKING_EVENT_DAYS', 30);   // Keep processed booking events for 30 days
define('HIC_RETENTION_REALTIME_SYNC_DAYS', 60);   // Keep realtime sync state records for 60 days

// === BREVO DEFAULTS ===
define('HIC_BREVO_DEFAULT_LIST_IT', 20);          // Default Italian list ID
define('HIC_BREVO_DEFAULT_LIST_EN', 21);          // Default English list ID
define('HIC_BREVO_EVENT_ENDPOINT', 'https://in-automate.brevo.com/api/v2/trackEvent');

// === RESERVATION PROCESSING ===
define('HIC_RESERVATION_LOCK_TIMEOUT', 300);      // 5 minutes lock timeout
define('HIC_RESERVATION_CACHE_TIME', 3600);       // 1 hour cache time
define('HIC_PROCESSED_RESERVATION_LIMIT', 10000); // Keep last 10k processed IDs

// === EMAIL VALIDATION ===
define('HIC_EMAIL_MAX_LENGTH', 254);              // RFC 5321 max email length
define('HIC_SID_MIN_LENGTH', 8);                  // Minimum SID length
define('HIC_SID_MAX_LENGTH', 256);                // Maximum SID length

// === OTA DOMAINS ===
define('HIC_OTA_DOMAINS', [
    'guest.booking.com',
    'guest.airbnb.com', 
    'expedia.com',
    'hotels.com',
    'agoda.com',
    'priceline.com',
    'kayak.com',
    'trivago.com'
]);

// === BUCKET TYPES ===
define('HIC_BUCKET_GADS', 'gads');
define('HIC_BUCKET_FBADS', 'fbads');
define('HIC_BUCKET_ORGANIC', 'organic');

// === VERTICAL TYPES ===
define('HIC_VERTICAL_HOTEL', 'hotel');
define('HIC_VERTICAL_RESTAURANT', 'restaurant');

// === ERROR CODES ===
define('HIC_ERROR_API_CONNECTION', 'api_connection_failed');
define('HIC_ERROR_INVALID_CREDENTIALS', 'invalid_credentials');
define('HIC_ERROR_RATE_LIMITED', 'rate_limited');
define('HIC_ERROR_INVALID_DATA', 'invalid_data');
define('HIC_ERROR_PROCESSING_FAILED', 'processing_failed');

// === HTTP STATUS CODES ===
define('HIC_HTTP_OK', 200);
define('HIC_HTTP_BAD_REQUEST', 400);
define('HIC_HTTP_UNAUTHORIZED', 401);
define('HIC_HTTP_FORBIDDEN', 403);
define('HIC_HTTP_NOT_FOUND', 404);
define('HIC_HTTP_TOO_MANY_REQUESTS', 429);
define('HIC_HTTP_INTERNAL_ERROR', 500);

// === CACHE KEYS ===
define('HIC_CACHE_PREFIX', 'hic_cache_');
define('HIC_CACHE_API_RESPONSE', HIC_CACHE_PREFIX . 'api_response_');
define('HIC_CACHE_PROCESSED_IDS', HIC_CACHE_PREFIX . 'processed_ids');
define('HIC_CACHE_LAST_POLLING', HIC_CACHE_PREFIX . 'last_polling');

// === TRANSIENT KEYS ===
define('HIC_TRANSIENT_POLLING_LOCK', 'hic_polling_lock');
define('HIC_TRANSIENT_API_RATE_LIMIT', 'hic_api_rate_limit');
define('HIC_TRANSIENT_HEALTH_CHECK', 'hic_health_check');

// === SECURITY ===
define('HIC_NONCE_LIFETIME', 86400);              // 24 hours nonce lifetime
define('HIC_MAX_LOGIN_ATTEMPTS', 5);              // Max API login attempts
define('HIC_LOGIN_LOCKOUT_TIME', 900);            // 15 minutes lockout

// === FEATURE FLAGS ===
define('HIC_FEATURE_EMAIL_ENRICHMENT', true);     // Enable email enrichment
define('HIC_FEATURE_REAL_TIME_SYNC', true);       // Enable real-time sync
define('HIC_FEATURE_HEALTH_MONITORING', true);    // Enable health monitoring
define('HIC_FEATURE_PERFORMANCE_METRICS', true);  // Enable performance tracking
define('HIC_FEATURE_WEBHOOK_RATE_LIMITING', true); // Enable webhook rate limiting

// === WEBHOOK VALIDATION ===
define('HIC_WEBHOOK_MAX_PAYLOAD_SIZE', 1048576);  // 1MB max webhook payload
define('HIC_WEBHOOK_SIGNATURE_HEADER', 'X-HIC-Signature');
define('HIC_WEBHOOK_RATE_LIMIT_MAX_ATTEMPTS', 60); // Maximum webhook requests per window
define('HIC_WEBHOOK_RATE_LIMIT_WINDOW', 60);      // Rate limit window in seconds

// === DIAGNOSTIC LEVELS ===
define('HIC_DIAGNOSTIC_BASIC', 'basic');
define('HIC_DIAGNOSTIC_DETAILED', 'detailed');
define('HIC_DIAGNOSTIC_FULL', 'full');

// === VERSION INFO ===
define('HIC_PLUGIN_VERSION', '3.3.0');
define('HIC_API_VERSION', 'v1');
define('HIC_MIN_PHP_VERSION', '7.4');
define('HIC_MIN_WP_VERSION', '5.8');
define('HIC_DB_VERSION', '1.8');
