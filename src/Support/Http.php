<?php declare(strict_types=1);

namespace FpHic\HicS2S\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class Http
{
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
                break;
            }

            $code = \wp_remote_retrieve_response_code($response);

            if ($code >= 500 && $code < 600 && $attempt < $maxAttempts) {
                $lastResponse = $response;
                \sleep($initialDelay * $attempt);
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
}
