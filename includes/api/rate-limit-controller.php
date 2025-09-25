<?php declare(strict_types=1);

namespace FpHic\Api;

use FpHic\HIC_Rate_Limiter;
use function FpHic\Helpers\hic_log;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralized HTTP rate limiting for outbound API calls.
 */
final class RateLimitController
{
    /**
     * Default per-host limits expressed as attempts/window (in seconds).
     *
     * @var array<string,array{key:string,max_attempts:int,window:int}>
     */
    private const DEFAULT_LIMITS = [
        'api.hotelcincloud.com' => [
            'key' => 'hic:hotel-in-cloud',
            'max_attempts' => 180,
            'window' => 60,
        ],
        'monitor.hotelcincloud.com' => [
            'key' => 'hic:monitoring-endpoint',
            'max_attempts' => 120,
            'window' => 60,
        ],
        'www.google-analytics.com' => [
            'key' => 'hic:ga4',
            'max_attempts' => 120,
            'window' => 60,
        ],
        'graph.facebook.com' => [
            'key' => 'hic:meta',
            'max_attempts' => 80,
            'window' => 60,
        ],
        'in-automate.brevo.com' => [
            'key' => 'hic:brevo',
            'max_attempts' => 60,
            'window' => 60,
        ],
    ];

    /**
     * Register WordPress filters.
     */
    public static function bootstrap(): void
    {
        if (!function_exists('add_filter')) {
            return;
        }

        add_filter('pre_http_request', [self::class, 'intercept'], 5, 3);
    }

    /**
     * Enforce per-host rate limits before WordPress executes the HTTP request.
     *
     * @param mixed        $preempt Either a short-circuit response or false.
     * @param array<mixed> $args    HTTP request arguments.
     * @param string       $url     Target URL.
     *
     * @return mixed Either the original value or a WP_Error if blocked.
     */
    public static function intercept($preempt, array $args, $url)
    {
        if ($preempt !== false) {
            return $preempt;
        }

        if (!is_string($url) || $url === '') {
            return $preempt;
        }

        $config = self::resolveConfig($url, $args);
        if ($config === null) {
            return $preempt;
        }

        $key = (string) ($config['key'] ?? '');
        $maxAttempts = (int) ($config['max_attempts'] ?? 0);
        $window = (int) ($config['window'] ?? 0);

        if ($key === '' || $maxAttempts <= 0 || $window <= 0) {
            return $preempt;
        }

        $result = HIC_Rate_Limiter::attempt($key, $maxAttempts, $window);
        if ($result['allowed']) {
            if (!\headers_sent() && function_exists('header')) {
                \header('X-HIC-RateLimit-Remaining: ' . max(0, (int) $result['remaining']));
            }

            return $preempt;
        }

        $retryAfter = max(1, (int) $result['retry_after']);
        hic_log(
            sprintf('API rate limit reached for %s (key: %s)', self::sanitizeUrlForLog($url), $key),
            HIC_LOG_LEVEL_WARNING,
            [
                'retry_after' => $retryAfter,
                'max_attempts' => $maxAttempts,
                'window' => $window,
            ]
        );

        return new \WP_Error(
            HIC_ERROR_RATE_LIMITED,
            sprintf(__(
                'Rate limit raggiunto. Riprovare tra %d secondi.',
                'hotel-in-cloud'
            ), $retryAfter),
            [
                'status' => HIC_HTTP_TOO_MANY_REQUESTS,
                'retry_after' => $retryAfter,
                'rate_limit_key' => $key,
            ]
        );
    }

    /**
     * Determine the rate-limit configuration for the outgoing request.
     *
     * @param string       $url  Target URL.
     * @param array<mixed> $args HTTP request arguments.
     *
     * @return array{key:string,max_attempts:int,window:int}|null
     */
    private static function resolveConfig(string $url, array $args): ?array
    {
        $overrides = $args['hic_rate_limit'] ?? null;
        if (is_array($overrides)) {
            $config = self::normalizeConfig($overrides);
            if ($config !== null) {
                return $config;
            }
        }

        $host = self::getHost($url);
        if ($host === '') {
            return null;
        }

        $limits = self::loadLimitMap($url, $args);

        if (!isset($limits[$host])) {
            return null;
        }

        return self::normalizeConfig($limits[$host]);
    }

    /**
     * Retrieve the registered rate limits after filters are applied.
     *
     * @return array<string,array{key:string,max_attempts:int,window:int}>
     */
    public static function getRegisteredLimits(): array
    {
        $limits = self::loadLimitMap('hic://introspect', []);
        $normalized = [];

        foreach ($limits as $host => $config) {
            if (!is_array($config)) {
                continue;
            }

            $normalizedConfig = self::normalizeConfig($config);
            if ($normalizedConfig === null) {
                continue;
            }

            $normalized[$host] = $normalizedConfig;
        }

        return $normalized;
    }

    /**
     * Sanitize configuration array.
     *
     * @param array<mixed> $config Raw configuration.
     */
    private static function normalizeConfig(array $config): ?array
    {
        $key = isset($config['key']) ? trim((string) $config['key']) : '';
        $maxAttempts = isset($config['max_attempts']) ? (int) $config['max_attempts'] : 0;
        $window = isset($config['window']) ? (int) $config['window'] : 0;

        if ($key === '' || $maxAttempts <= 0 || $window <= 0) {
            return null;
        }

        return [
            'key' => strtolower($key),
            'max_attempts' => max(1, $maxAttempts),
            'window' => max(1, $window),
        ];
    }

    /**
     * Load the raw rate limit map including third-party customisations.
     *
     * @param string|null $url  URL used for filter context.
     * @param array<mixed> $args Request arguments used for filter context.
     *
     * @return array<string,mixed>
     */
    private static function loadLimitMap($url, array $args): array
    {
        $limits = self::DEFAULT_LIMITS;

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('hic_rate_limit_map', $limits, $url, $args);
            if (is_array($filtered) && $filtered !== []) {
                $limits = $filtered;
            }
        }

        return is_array($limits) ? $limits : self::DEFAULT_LIMITS;
    }

    private static function getHost(string $url): string
    {
        $parsed = \wp_parse_url($url);
        if (!is_array($parsed) || empty($parsed['host'])) {
            return '';
        }

        return strtolower($parsed['host']);
    }

    private static function sanitizeUrlForLog(string $url): string
    {
        $parsed = \wp_parse_url($url);
        if (!is_array($parsed)) {
            return '[invalid-url]';
        }

        $sanitized = $parsed['scheme'] ?? 'https';
        $sanitized .= '://';
        $sanitized .= $parsed['host'] ?? '[host]';

        if (!empty($parsed['path'])) {
            $sanitized .= $parsed['path'];
        }

        return $sanitized;
    }
}

RateLimitController::bootstrap();
