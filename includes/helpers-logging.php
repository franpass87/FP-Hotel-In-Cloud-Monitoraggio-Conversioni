<?php declare(strict_types=1);

namespace FpHic\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

function hic_get_log_file() {
    return hic_get_option('log_file', WP_CONTENT_DIR . '/uploads/hic-logs/hic-log.txt');
}

function hic_validate_log_path($path) {
    $base_dir = WP_CONTENT_DIR . '/uploads/hic-logs/';
    $default = $base_dir . 'hic-log.txt';

    $path = sanitize_text_field($path);
    if (empty($path)) {
        return $default;
    }

    $normalized_path = str_replace('\\', '/', $path);
    $normalized_base = rtrim(str_replace('\\', '/', $base_dir), '/') . '/';

    if (strpos($normalized_path, '..') !== false || strpos($normalized_path, $normalized_base) !== 0) {
        return $default;
    }

    return $normalized_path;
}

/**
 * Mask common sensitive data like emails, phone numbers and tokens.
 */
function hic_mask_sensitive_data($message) {
    if (!is_string($message)) {
        return $message;
    }

    // Mask email addresses
    $message = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}/u', '[masked-email]', $message);

    // Mask phone numbers (sequences of digits, spaces or dashes)
    $message = preg_replace('/\\b(?:\\+?\\d[\\d\\s\\-]{7,}\\d)\\b/u', '[masked-phone]', $message);

    // Mask tokens and authorization headers
    $message = preg_replace('/(token|api[_-]?key|secret|password)\\s*[=:]\\s*[A-Za-z0-9._-]+/iu', '$1=[masked]', $message);
    $message = preg_replace('/Authorization:\\s*Bearer\\s+[A-Za-z0-9._-]+/iu', 'Authorization: Bearer [masked]', $message);

    return $message;
}

/**
 * Default filter for hic_log_message that masks sensitive data.
 *
 * @param string $message Log message
 * @param string $level   Log level
 * @return string
 */
function hic_default_log_message_filter($message, $level) {
    if (is_array($message)) {
        foreach ($message as $key => $value) {
            if (is_string($key) && preg_match('/^(?:email)$/i', $key)) {
                $message[$key] = '[masked-email]';
                continue;
            }
            if (is_string($key) && preg_match('/(token|api[_-]?key|secret|password)/i', $key)) {
                $message[$key] = '[masked]';
                continue;
            }
            $message[$key] = hic_default_log_message_filter($value, $level);
        }
        return $message;
    }

    if (is_object($message)) {
        foreach ($message as $key => $value) {
            if (is_string($key) && preg_match('/^(?:email)$/i', $key)) {
                $message->$key = '[masked-email]';
                continue;
            }
            if (is_string($key) && preg_match('/(token|api[_-]?key|secret|password)/i', $key)) {
                $message->$key = '[masked]';
                continue;
            }
            $message->$key = hic_default_log_message_filter($value, $level);
        }
        return $message;
    }

    if (is_bool($message) || $message === null) {
        return $message;
    }

    if (is_string($message)) {
        $masked = hic_mask_sensitive_data($message);
        if ($masked !== $message) {
            return $masked;
        }
        if (is_numeric($message)) {
            return '[masked-number]';
        }
        return $masked;
    }

    if (is_numeric($message)) {
        return '[masked-number]';
    }

    return $message;
}

if (function_exists('add_filter')) {
    add_filter('hic_log_message', __NAMESPACE__ . '\\hic_default_log_message_filter', 10, 2);
}

function hic_log($msg, $level = HIC_LOG_LEVEL_INFO, $context = []) {
    static $log_manager = null;

    if (null === $log_manager && function_exists('\\hic_get_log_manager')) {
        $log_manager = \hic_get_log_manager();
    }

    if ($log_manager) {
        return $log_manager->log($msg, $level, $context);
    }
    return false;
}

