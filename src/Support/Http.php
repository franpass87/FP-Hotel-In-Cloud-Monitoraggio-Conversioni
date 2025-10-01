<?php declare(strict_types=1);

namespace FpHic\HicS2S\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class Http
{
    private const MAX_RETRY_AFTER = 300;

    /**
     * @param callable():array{url:string,args?:array<string,mixed>} $requestFactory
     * @return array{success:bool,code:int|null,attempts:int,response:mixed,error:mixed}
     */
    public static function postWithRetry(callable $requestFactory, int $maxAttempts = 3, int $initialDelay = 1): array
    {
        $attempt = 0;
        $lastResponse = null;
        $lastError = null;
        $code = null;

        while ($attempt < $maxAttempts) {
            $attempt++;

            $request = $requestFactory();
            $url = isset($request['url']) ? (string) $request['url'] : '';
            $args = isset($request['args']) && is_array($request['args']) ? $request['args'] : [];

            if ($url === '') {
                $lastError = new \WP_Error('invalid_url', 'URL non valido');
                break;
            }

            $response = \wp_remote_post($url, $args);

            if (is_wp_error($response)) {
                $lastError = $response;
                $lastResponse = null;
                $code = null;
                if ($attempt < $maxAttempts) {
                    self::pause(self::calculateDelay($attempt, $initialDelay, null));
                    continue;
                }
                break;
            }

            $code = \wp_remote_retrieve_response_code($response);
            $retryAfterHeader = self::extractRetryAfter($response);

            if ($attempt < $maxAttempts && ($code === 429 || ($code >= 500 && $code < 600))) {
                $lastResponse = $response;
                self::pause(self::calculateDelay($attempt, $initialDelay, $retryAfterHeader));
                continue;
            }

            $success = $code >= 200 && $code < 300;

            return [
                'success' => $success,
                'code' => $code,
                'attempts' => $attempt,
                'response' => $response,
                'error' => null,
            ];
        }

        return [
            'success' => false,
            'code' => $code,
            'attempts' => $attempt,
            'response' => $lastResponse,
            'error' => $lastError,
        ];
    }

    private static function pause(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        if (function_exists('wp_sleep')) {
            wp_sleep($seconds);

            return;
        }

        usleep($seconds * 1_000_000);
    }

    private static function calculateDelay(int $attempt, int $initialDelay, ?int $retryAfter): int
    {
        if ($retryAfter !== null) {
            $delay = min($retryAfter, self::MAX_RETRY_AFTER);

            return max(1, $delay);
        }

        $computed = $initialDelay * $attempt;

        if ($computed > self::MAX_RETRY_AFTER) {
            return self::MAX_RETRY_AFTER;
        }

        return max(1, $computed);
    }

    /**
     * @param mixed $response
     */
    private static function extractRetryAfter($response): ?int
    {
        $header = WpHttp::retrieveHeader($response, 'retry-after');

        if (!is_string($header) || trim($header) === '') {
            return null;
        }

        $header = trim($header);

        if (ctype_digit($header)) {
            $seconds = (int) $header;

            return $seconds > 0 ? $seconds : null;
        }

        $timestamp = strtotime($header);

        if ($timestamp === false) {
            return null;
        }

        $seconds = $timestamp - time();

        return $seconds > 0 ? $seconds : null;
    }
}
