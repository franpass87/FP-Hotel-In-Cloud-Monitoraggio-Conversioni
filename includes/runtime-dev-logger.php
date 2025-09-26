<?php declare(strict_types=1);

namespace FpHic\Runtime;

use FpHic\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

const HIC_RUNTIME_LOGGER_STATE_KEY = 'hic_runtime_logger_state';

/** @var array<int,array<string,mixed>> */
$GLOBALS['hic_runtime_logger_events'] = $GLOBALS['hic_runtime_logger_events'] ?? [];

/**
 * Initialize the runtime logger hooks when running in a debuggable environment.
 */
function bootstrap(): void
{
    static $bootstrapped = false;

    if ($bootstrapped) {
        return;
    }
    $bootstrapped = true;

    if (!is_logger_enabled()) {
        return;
    }

    set_error_handler(__NAMESPACE__ . '\\handle_error');
    set_exception_handler(__NAMESPACE__ . '\\handle_exception');
    register_shutdown_function(__NAMESPACE__ . '\\handle_shutdown');

    if (function_exists('add_action')) {
        add_action('admin_notices', __NAMESPACE__ . '\\render_admin_notice');
        add_action('wp_footer', __NAMESPACE__ . '\\render_frontend_overlay');
    }
}

/**
 * Determine if the runtime logger should be active.
 */
function is_logger_enabled(): bool
{
    $enabled = defined('WP_DEBUG') && WP_DEBUG;

    if (function_exists('apply_filters')) {
        /** @psalm-suppress MixedAssignment */
        $enabled = apply_filters('hic_enable_runtime_logger', $enabled);
    }

    return (bool) $enabled;
}

/**
 * Map PHP error severities to plugin log levels.
 */
function map_error_level(int $severity): string
{
    switch ($severity) {
        case E_ERROR:
        case E_PARSE:
        case E_CORE_ERROR:
        case E_COMPILE_ERROR:
        case E_USER_ERROR:
        case E_RECOVERABLE_ERROR:
            return \HIC_LOG_LEVEL_ERROR;

        case E_WARNING:
        case E_USER_WARNING:
        case E_COMPILE_WARNING:
        case E_CORE_WARNING:
            return \HIC_LOG_LEVEL_WARNING;

        default:
            return \HIC_LOG_LEVEL_INFO;
    }
}

/**
 * Record an event both locally and in the persistent audit log.
 *
 * @param array<string,mixed> $event
 */
function record_event(array $event): void
{
    $event['timestamp'] = $event['timestamp'] ?? gmdate('c');

    /** @var array<int,array<string,mixed>> $events */
    $events = $GLOBALS['hic_runtime_logger_events'] ?? [];
    $events[] = $event;
    $GLOBALS['hic_runtime_logger_events'] = $events;

    $message = '[' . $event['type'] . '] ' . ($event['message'] ?? '');
    $context = [
        'file' => $event['file'] ?? '',
        'line' => $event['line'] ?? 0,
        'trace' => $event['trace'] ?? '',
        'runtime_logger' => true,
    ];

    try {
        Helpers\hic_log($message, $event['level'] ?? \HIC_LOG_LEVEL_INFO, $context);
    } catch (\Throwable $exception) {
        // Fallback to the PHP error log if the plugin logger is not available yet.
        error_log('HIC runtime logger failure: ' . $exception->getMessage());
    }
}

/**
 * Format the raw PHP error data into the canonical event structure.
 */
function format_event(string $type, string $message, string $file, int $line, string $level, ?string $trace = null): array
{
    $sanitize_text_field = function_exists('sanitize_text_field')
        ? 'sanitize_text_field'
        : static function ($value) {
            return is_string($value) ? filter_var($value, FILTER_SANITIZE_FULL_SPECIAL_CHARS) : '';
        };

    $wp_kses_post = function_exists('wp_kses_post')
        ? 'wp_kses_post'
        : static function ($value) {
            return is_string($value) ? strip_tags($value) : '';
        };

    $sanitized_message = $sanitize_text_field($message);

    return [
        'type' => $type,
        'message' => $sanitized_message,
        'file' => $sanitize_text_field($file),
        'line' => $line,
        'level' => $level,
        'trace' => $trace ? $wp_kses_post($trace) : '',
    ];
}

/**
 * Custom PHP error handler forwarding notices and warnings to the plugin log.
 */
function handle_error(int $severity, string $message, string $file = '', int $line = 0): bool
{
    if (!(error_reporting() & $severity)) {
        return false;
    }

    $level = map_error_level($severity);
    $event = format_event('php_error', $message, $file, $line, $level);

    record_event($event);

    // Continue with PHP's default error handler.
    return false;
}

/**
 * Custom exception handler capturing uncaught exceptions.
 */
function handle_exception(\Throwable $exception): void
{
    $event = format_event(
        'uncaught_exception',
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        \HIC_LOG_LEVEL_ERROR,
        $exception->getTraceAsString()
    );

    record_event($event);
}

/**
 * Shutdown handler to surface fatal errors.
 */
function handle_shutdown(): void
{
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $level = map_error_level($error['type']);
    $event = format_event('shutdown_error', $error['message'] ?? '', $error['file'] ?? '', (int) ($error['line'] ?? 0), $level);

    record_event($event);
}

/**
 * Render admin notice for captured runtime errors.
 */
function render_admin_notice(): void
{
    if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
        return;
    }

    $events = $GLOBALS['hic_runtime_logger_events'] ?? [];
    if (empty($events)) {
        return;
    }

    $esc_html__ = function_exists('esc_html__')
        ? static function (string $text, string $domain = 'default'): string {
            return esc_html__($text, $domain);
        }
        : static function (string $text, string $domain = 'default'): string {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        };

    $esc_html = function_exists('esc_html')
        ? static function ($text): string {
            return esc_html($text);
        }
        : static function ($text): string {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        };

    echo '<div class="notice notice-error hic-runtime-logger-notice">';
    echo '<p><strong>' . $esc_html__('HIC Monitor runtime warnings detected.', 'hotel-in-cloud') . '</strong></p>';
    echo '<ul>';

    $max_items = 5;
    $counter = 0;
    foreach ($events as $event) {
        if ($counter >= $max_items) {
            break;
        }

        $summary = sprintf(
            '%s in %s:%d',
            $event['message'] ?? '',
            $event['file'] ?? '',
            (int) ($event['line'] ?? 0)
        );

        echo '<li>' . $esc_html($summary) . '</li>';
        $counter++;
    }

    if (count($events) > $max_items) {
        $remaining = count($events) - $max_items;
        $more_items_label = function_exists('__')
            ? __('...and %d more items. Check the HIC logs for full details.', 'hotel-in-cloud')
            : '...and %d more items. Check the HIC logs for full details.';
        $message = sprintf($more_items_label, $remaining);
        echo '<li>' . $esc_html($message) . '</li>';
    }

    echo '</ul>';
    echo '</div>';
}

/**
 * Render a lightweight overlay on the frontend so administrators can spot captured issues.
 */
function render_frontend_overlay(): void
{
    if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
        return;
    }

    $events = $GLOBALS['hic_runtime_logger_events'] ?? [];
    if (empty($events)) {
        return;
    }

    $latest = end($events);
    if (!is_array($latest)) {
        return;
    }

    $message = sprintf(
        '%s (%s:%d)',
        $latest['message'] ?? '',
        $latest['file'] ?? '',
        (int) ($latest['line'] ?? 0)
    );

    $esc_html__ = function_exists('esc_html__')
        ? static function (string $text, string $domain = 'default'): string {
            return esc_html__($text, $domain);
        }
        : static function (string $text, string $domain = 'default'): string {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        };

    $esc_html = function_exists('esc_html')
        ? static function ($text): string {
            return esc_html($text);
        }
        : static function ($text): string {
            return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
        };

    echo '<div class="hic-runtime-logger-overlay" style="position:fixed;bottom:1rem;right:1rem;z-index:99999;background:#b81c1c;color:#fff;padding:1rem;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,0.3);font-size:13px;">';
    echo '<strong>' . $esc_html__('HIC runtime warning', 'hotel-in-cloud') . ':</strong> ' . $esc_html($message);
    echo '</div>';
}

bootstrap();
