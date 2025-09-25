<?php declare(strict_types=1);

namespace FpHic;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Simple rate limiter utility backed by WordPress transients.
 */
class HIC_Rate_Limiter
{
    private const DEFAULT_MAX_ATTEMPTS = 10;
    private const DEFAULT_WINDOW = 60;
    private const MEMORY_CACHE_LIMIT = 128;

    /**
     * @var array<string,array{count:int,expires_at:int}>
     */
    private static array $memoryCache = [];

    /**
     * Attempt an action respecting the configured rate limit.
     *
     * @param string $key          Unique identifier for the action and actor combination.
     * @param int    $maxAttempts  Maximum attempts allowed during the window.
     * @param int    $window       Rate limit window in seconds.
     *
     * @return array{allowed:bool,remaining:int,retry_after:int}
     */
    public static function attempt(string $key, int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS, int $window = self::DEFAULT_WINDOW): array
    {
        $normalizedKey = self::normalizeKey($key);

        if ($normalizedKey === '' || $maxAttempts <= 0 || $window <= 0) {
            return [
                'allowed' => true,
                'remaining' => max(0, $maxAttempts),
                'retry_after' => 0,
            ];
        }

        $now = time();
        $state = self::getState($normalizedKey, $now);

        if ($state['count'] >= $maxAttempts && $state['expires_at'] > $now) {
            $retryAfter = max(1, $state['expires_at'] - $now);

            return [
                'allowed' => false,
                'remaining' => 0,
                'retry_after' => $retryAfter,
            ];
        }

        if ($state['expires_at'] <= $now) {
            $state = [
                'count' => 0,
                'expires_at' => $now + $window,
            ];
        }

        $state['count']++;
        $state['expires_at'] = max($state['expires_at'], $now + $window);

        self::storeState($normalizedKey, $state, $now);

        $remaining = max(0, $maxAttempts - $state['count']);

        return [
            'allowed' => true,
            'remaining' => $remaining,
            'retry_after' => 0,
        ];
    }

    /**
     * Retrieve the retry-after value (in seconds) for the provided key.
     */
    public static function getRetryAfter(string $key): int
    {
        $normalizedKey = self::normalizeKey($key);
        if ($normalizedKey === '') {
            return 0;
        }

        $state = self::getState($normalizedKey, time());
        if ($state['count'] <= 0) {
            return 0;
        }

        $retryAfter = $state['expires_at'] - time();
        return $retryAfter > 0 ? $retryAfter : 0;
    }

    /**
     * Reset the stored state for a rate limit key.
     */
    public static function reset(string $key): void
    {
        $normalizedKey = self::normalizeKey($key);
        if ($normalizedKey === '') {
            return;
        }

        unset(self::$memoryCache[$normalizedKey]);

        if (function_exists('delete_transient')) {
            delete_transient(self::storageKey($normalizedKey));
        }
    }

    /**
     * Inspect the current usage for a rate limit without mutating it.
     *
     * @param string $key         Unique identifier that matches the attempt key.
     * @param int    $maxAttempts Configured maximum attempts for the window.
     * @param int    $window      Window length in seconds.
     *
     * @return array{count:int,remaining:int,retry_after:int,expires_at:int,window:int}
     */
    public static function inspect(string $key, int $maxAttempts, int $window): array
    {
        $normalizedKey = self::normalizeKey($key);
        if ($normalizedKey === '' || $maxAttempts <= 0 || $window <= 0) {
            return [
                'count' => 0,
                'remaining' => max(0, $maxAttempts),
                'retry_after' => 0,
                'expires_at' => 0,
                'window' => max(0, $window),
            ];
        }

        $now = time();
        $state = self::getState($normalizedKey, $now);
        $retryAfter = $state['expires_at'] > $now ? $state['expires_at'] - $now : 0;

        return [
            'count' => $state['count'],
            'remaining' => max(0, $maxAttempts - $state['count']),
            'retry_after' => $retryAfter,
            'expires_at' => $state['expires_at'],
            'window' => max(1, $window),
        ];
    }

    /**
     * Retrieve the stored state for the given key.
     *
     * @return array{count:int,expires_at:int}
     */
    private static function getState(string $normalizedKey, int $now): array
    {
        if ($normalizedKey === '') {
            return ['count' => 0, 'expires_at' => 0];
        }

        if (isset(self::$memoryCache[$normalizedKey])) {
            $state = self::$memoryCache[$normalizedKey];
            if ($state['expires_at'] > 0 && $state['expires_at'] <= $now) {
                unset(self::$memoryCache[$normalizedKey]);
                return ['count' => 0, 'expires_at' => 0];
            }

            return $state;
        }

        if (!function_exists('get_transient')) {
            return ['count' => 0, 'expires_at' => 0];
        }

        $stored = get_transient(self::storageKey($normalizedKey));
        if (!is_array($stored) || !isset($stored['count'], $stored['expires_at'])) {
            self::rememberState($normalizedKey, ['count' => 0, 'expires_at' => 0]);
            return ['count' => 0, 'expires_at' => 0];
        }

        $count = max(0, (int) $stored['count']);
        $expiresAt = max(0, (int) $stored['expires_at']);

        if ($expiresAt > 0 && $expiresAt <= $now) {
            self::reset($normalizedKey);
            return ['count' => 0, 'expires_at' => 0];
        }

        $state = ['count' => $count, 'expires_at' => $expiresAt];
        self::rememberState($normalizedKey, $state);

        return $state;
    }

    /**
     * Persist the updated state to memory and transients.
     *
     * @param array{count:int,expires_at:int} $state
     */
    private static function storeState(string $normalizedKey, array $state, int $now): void
    {
        self::rememberState($normalizedKey, $state);

        if (!function_exists('set_transient')) {
            return;
        }

        $ttl = max(1, $state['expires_at'] - $now);
        set_transient(self::storageKey($normalizedKey), $state, $ttl);
    }

    /**
     * Cache the state in memory while keeping memory usage bounded.
     *
     * @param array{count:int,expires_at:int} $state
     */
    private static function rememberState(string $normalizedKey, array $state): void
    {
        if (count(self::$memoryCache) >= self::MEMORY_CACHE_LIMIT) {
            $oldestKey = array_key_first(self::$memoryCache);
            if ($oldestKey !== null) {
                unset(self::$memoryCache[$oldestKey]);
            }
        }

        self::$memoryCache[$normalizedKey] = $state;
    }

    private static function storageKey(string $normalizedKey): string
    {
        $hash = substr(hash('sha256', $normalizedKey), 0, 32);
        return HIC_TRANSIENT_API_RATE_LIMIT . '_' . $hash;
    }

    private static function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        if ($key === '') {
            return '';
        }

        $normalized = preg_replace('/[^a-z0-9:_-]/', '', $key);
        if (!is_string($normalized)) {
            return '';
        }

        return $normalized;
    }
}
