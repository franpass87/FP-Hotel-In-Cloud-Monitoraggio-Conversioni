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
function hic_normalize_sensitive_key($key): string
{
    if (!is_scalar($key)) {
        return '';
    }

    $normalized = (string) $key;
    $normalized = preg_replace('/([a-z])([A-Z])/', '$1_$2', $normalized);
    $normalized = preg_replace('/[^a-zA-Z0-9_]+/', '_', $normalized ?? '');
    $normalized = strtolower($normalized ?? '');

    return trim($normalized ?? '', '_');
}

function hic_default_log_message_filter($message, $level) {
    static $email_keys = null;
    static $phone_keys = null;

    if ($email_keys === null) {
        $email_keys = [
            'email',
            'guest_email',
            'customer_email',
            'contact_email',
            'user_email',
            'primary_email',
            'email_address',
            'billing_email',
            'shipping_email',
        ];
        $phone_keys = [
            'phone',
            'phone_number',
            'guest_phone',
            'customer_phone',
            'contact_phone',
            'user_phone',
            'primary_phone',
            'mobile',
            'mobile_phone',
            'mobile_number',
        ];
    }

    if (is_array($message)) {
        foreach ($message as $key => $value) {
            $normalized_key = hic_normalize_sensitive_key($key);

            if ($normalized_key !== '' && in_array($normalized_key, $email_keys, true)) {
                $message[$key] = '[masked-email]';
                continue;
            }
            if ($normalized_key !== '' && in_array($normalized_key, $phone_keys, true)) {
                $message[$key] = '[masked-phone]';
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
            $normalized_key = hic_normalize_sensitive_key($key);

            if ($normalized_key !== '' && in_array($normalized_key, $email_keys, true)) {
                $message->$key = '[masked-email]';
                continue;
            }
            if ($normalized_key !== '' && in_array($normalized_key, $phone_keys, true)) {
                $message->$key = '[masked-phone]';
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

function hic_is_log_filter_registered(): bool
{
    if (function_exists('has_filter')) {
        return has_filter('hic_log_message', __NAMESPACE__ . '\\hic_default_log_message_filter') !== false;
    }

    if (isset($GLOBALS['hic_test_filters']['hic_log_message'])) {
        foreach ($GLOBALS['hic_test_filters']['hic_log_message'] as $callbacks) {
            if (!is_array($callbacks)) {
                continue;
            }

            foreach ($callbacks as $callback) {
                if (!is_array($callback) || !isset($callback['function'])) {
                    continue;
                }

                if ($callback['function'] === __NAMESPACE__ . '\\hic_default_log_message_filter') {
                    return true;
                }
            }
        }
    }

    return false;
}

function hic_ensure_log_filter_registered(): void
{
    if (hic_is_log_filter_registered()) {
        return;
    }

    if (function_exists(__NAMESPACE__ . '\\hic_safe_add_hook')) {
        hic_safe_add_hook('filter', 'hic_log_message', __NAMESPACE__ . '\\hic_default_log_message_filter', 10, 2);
    } elseif (function_exists('\\add_filter')) {
        \add_filter('hic_log_message', __NAMESPACE__ . '\\hic_default_log_message_filter', 10, 2);
    }
}

hic_ensure_log_filter_registered();

function hic_log($msg, $level = HIC_LOG_LEVEL_INFO, $context = []) {
    static $log_manager = null;

    hic_ensure_log_filter_registered();

    if ($log_manager instanceof \HIC_Log_Manager) {
        $current_log_file = hic_get_log_file();
        if (is_string($current_log_file) && $current_log_file !== ''
            && $log_manager->get_log_file_path() !== $current_log_file
        ) {
            unset($GLOBALS['hic_log_manager']);
            $log_manager = null;
        }
    }

    if (!isset($GLOBALS['hic_log_manager'])) {
        $log_manager = null;
    } elseif ($log_manager !== $GLOBALS['hic_log_manager']) {
        $log_manager = $GLOBALS['hic_log_manager'];
    }

    if (null === $log_manager && function_exists('\\hic_get_log_manager')) {
        $log_manager = \hic_get_log_manager();
    }

    if ($log_manager) {
        return $log_manager->log($msg, $level, $context);
    }
    return false;
}

