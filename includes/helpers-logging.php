<?php declare(strict_types=1);

namespace FpHic\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

function hic_get_log_file() {
    return hic_get_option('log_file', WP_CONTENT_DIR . '/uploads/hic-logs/hic-log.txt');
}

function hic_get_log_directory(): string
{
    $defaultDirectory = rtrim(WP_CONTENT_DIR . '/uploads/hic-logs', '/\\');
    $logFile = hic_get_log_file();

    if (!is_string($logFile) || $logFile === '') {
        return $defaultDirectory;
    }

    $directory = dirname($logFile);

    if ($directory === '' || $directory === '.' || $directory === DIRECTORY_SEPARATOR) {
        return $defaultDirectory;
    }

    return rtrim($directory, '/\\');
}

/**
 * Ensure the log directory exists and is shielded from direct access.
 *
 * @return array{directory:bool,htaccess:bool,web_config:bool,errors:array<string,string>,path:string}
 */
function hic_ensure_log_directory_security(): array
{
    $status = [
        'directory' => false,
        'htaccess' => false,
        'web_config' => false,
        'errors' => [],
        'path' => '',
    ];

    if (!defined('WP_CONTENT_DIR')) {
        $status['errors']['directory'] = 'WP_CONTENT_DIR not defined.';

        return $status;
    }

    $logDir = hic_get_log_directory();
    $status['path'] = $logDir;

    if ($logDir === '') {
        $status['errors']['directory'] = 'Empty log directory path.';

        return $status;
    }

    if (!is_dir($logDir)) {
        $created = function_exists('wp_mkdir_p')
            ? wp_mkdir_p($logDir)
            : mkdir($logDir, 0755, true);

        if (!$created && !is_dir($logDir)) {
            $status['errors']['directory'] = sprintf('Unable to create directory %s.', $logDir);

            return $status;
        }
    }

    $status['directory'] = true;

    $directoryWritable = is_writable($logDir);
    if (!$directoryWritable) {
        $status['errors']['directory'] = sprintf('Directory %s is not writable.', $logDir);
    }

    $htaccessPath = $logDir . DIRECTORY_SEPARATOR . '.htaccess';
    $desiredHtaccess = "Require all denied\n<IfModule mod_access_compat.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
    $existingHtaccess = is_readable($htaccessPath) ? file_get_contents($htaccessPath) : '';
    $htaccessIsHardened = is_string($existingHtaccess)
        && strpos($existingHtaccess, 'Require all denied') !== false;

    if ($htaccessIsHardened) {
        $status['htaccess'] = true;
    } elseif ($directoryWritable) {
        if (function_exists('error_clear_last')) {
            error_clear_last();
        }

        if (false !== file_put_contents($htaccessPath, $desiredHtaccess)) {
            $status['htaccess'] = true;
        } else {
            $error = error_get_last();
            $status['errors']['htaccess'] = sprintf(
                'Unable to write %s: %s',
                $htaccessPath,
                $error['message'] ?? 'unknown error'
            );
        }
    } else {
        $status['errors']['htaccess'] = sprintf('Directory %s is not writable.', $logDir);
    }

    $webConfigPath = $logDir . DIRECTORY_SEPARATOR . 'web.config';
    $desiredWebConfig = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <authorization>
      <deny users="*" />
    </authorization>
    <directoryBrowse enabled="false" />
  </system.webServer>
</configuration>
XML;
    $existingWebConfig = is_readable($webConfigPath) ? file_get_contents($webConfigPath) : '';

    if (is_string($existingWebConfig) && strpos($existingWebConfig, '<deny users="*"') !== false) {
        $status['web_config'] = true;
    } elseif ($directoryWritable) {
        if (function_exists('error_clear_last')) {
            error_clear_last();
        }

        if (false !== file_put_contents($webConfigPath, $desiredWebConfig)) {
            $status['web_config'] = true;
        } else {
            $error = error_get_last();
            $status['errors']['web_config'] = sprintf(
                'Unable to write %s: %s',
                $webConfigPath,
                $error['message'] ?? 'unknown error'
            );
        }
    } else {
        $status['errors']['web_config'] = sprintf('Directory %s is not writable.', $logDir);
    }

    return $status;
}

/**
 * Derive the relative request prefixes that must be blocked when static files are exposed.
 *
 * @return array<int,string>
 */
function hic_get_protected_upload_request_paths(): array
{
    if (!function_exists('wp_get_upload_dir')) {
        return [];
    }

    $uploads = wp_get_upload_dir();

    if (empty($uploads['basedir']) || empty($uploads['baseurl'])) {
        return [];
    }

    $protectedDirectories = [];

    $logDirectory = hic_get_log_directory();
    if (is_string($logDirectory) && $logDirectory !== '') {
        $protectedDirectories[] = $logDirectory;
    }

    $protectedDirectories[] = rtrim($uploads['basedir'], '/\\') . '/hic-exports';

    $baseDir = wp_normalize_path($uploads['basedir']);
    $baseDir = rtrim($baseDir, '/');

    $paths = [];

    foreach ($protectedDirectories as $directory) {
        if (!is_string($directory) || $directory === '') {
            continue;
        }

        $normalizedDirectory = wp_normalize_path($directory);

        if ($normalizedDirectory === '' || strpos($normalizedDirectory, $baseDir) !== 0) {
            continue;
        }

        $relative = ltrim(substr($normalizedDirectory, strlen($baseDir)), '/');

        if ($relative === '') {
            continue;
        }

        $relativeUrl = wp_make_link_relative(trailingslashit($uploads['baseurl']) . $relative . '/');
        $relativeUrl = '/' . ltrim($relativeUrl, '/');

        if (substr($relativeUrl, -1) !== '/') {
            $relativeUrl .= '/';
        }

        $paths[] = $relativeUrl;
    }

    return array_values(array_unique($paths));
}

/**
 * Block direct web requests to sensitive upload directories when WordPress handles the request.
 */
function hic_block_sensitive_upload_access(): void
{
    if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
        return;
    }

    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }

    $requestUri = $_SERVER['REQUEST_URI'] ?? '';

    if ($requestUri === '') {
        return;
    }

    $requestPath = wp_parse_url($requestUri, PHP_URL_PATH);

    if (!is_string($requestPath) || $requestPath === '') {
        return;
    }

    $normalizedRequest = '/' . ltrim($requestPath, '/');

    foreach (hic_get_protected_upload_request_paths() as $protectedPrefix) {
        if ($protectedPrefix !== '' && strpos($normalizedRequest, $protectedPrefix) === 0) {
            status_header(404);
            nocache_headers();
            exit;
        }
    }
}

/**
 * Register guards that deny unauthenticated access to sensitive upload directories.
 */
function hic_register_sensitive_upload_guards(): void
{
    if (!function_exists('add_action')) {
        return;
    }

    add_action('template_redirect', __NAMESPACE__ . '\\hic_block_sensitive_upload_access', 0);
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

