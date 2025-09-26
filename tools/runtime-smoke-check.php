#!/usr/bin/env php
<?php declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__ . '/../');
    }

    if (!defined('WP_DEBUG')) {
        define('WP_DEBUG', true);
    }

    if (!defined('HIC_LOG_LEVEL_INFO')) {
        define('HIC_LOG_LEVEL_INFO', 'info');
    }
    if (!defined('HIC_LOG_LEVEL_WARNING')) {
        define('HIC_LOG_LEVEL_WARNING', 'warning');
    }
    if (!defined('HIC_LOG_LEVEL_ERROR')) {
        define('HIC_LOG_LEVEL_ERROR', 'error');
    }

    if (!defined('HIC_LOG_MAX_SIZE')) {
        define('HIC_LOG_MAX_SIZE', 1048576);
    }
    if (!defined('HIC_LOG_RETENTION_DAYS')) {
        define('HIC_LOG_RETENTION_DAYS', 7);
    }

    // Provide minimal WordPress-style helpers for the runtime logger.
    if (!function_exists('apply_filters')) {
        function apply_filters(string $hook_name, $value) {
            return $value;
        }
    }

    if (!function_exists('error_log')) {
        function error_log($message, $message_type = 0, $destination = '', $extra_headers = ''): bool {
            fwrite(STDERR, (string) $message . PHP_EOL);
            return true;
        }
    }
}

namespace FpHic\Helpers {
    function hic_log($msg, $level = HIC_LOG_LEVEL_INFO, $context = [])
    {
        $context_str = json_encode($context, JSON_UNESCAPED_UNICODE);
        $line = sprintf('[runtime-smoke] %s: %s %s', strtoupper((string) $level), (string) $msg, $context_str);
        fwrite(STDOUT, $line . PHP_EOL);
        return true;
    }
}

namespace {
    require_once __DIR__ . '/../includes/runtime-dev-logger.php';

    // Trigger a warning-level event.
    \FpHic\Runtime\handle_error(E_USER_WARNING, 'Runtime smoke test warning', __FILE__, __LINE__);

    // Trigger an exception event.
    try {
        throw new \RuntimeException('Runtime smoke test exception');
    } catch (\Throwable $throwable) {
        \FpHic\Runtime\handle_exception($throwable);
    }

    // Record a synthetic fatal error event directly through the logger API.
    \FpHic\Runtime\record_event([
        'type' => 'manual_injection',
        'message' => 'Manual runtime logger verification entry',
        'file' => __FILE__,
        'line' => __LINE__,
        'level' => HIC_LOG_LEVEL_INFO,
    ]);

    echo "Runtime smoke check complete." . PHP_EOL;
}
