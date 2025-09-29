<?php declare(strict_types=1);

namespace FP_Exp\Frontend;

use function FpHic\Helpers\hic_safe_add_hook;

if (!defined('ABSPATH')) {
    exit;
}

final class Assets
{
    private static $enqueueRequested = false;
    private static $hooked = false;

    public static function bootstrap(): void
    {
        if (self::$hooked) {
            return;
        }

        self::$hooked = true;
        hic_safe_add_hook('action', 'wp_enqueue_scripts', [__CLASS__, 'maybe_enqueue'], 20);
    }

    public static function request_enqueue(): void
    {
        self::$enqueueRequested = true;

        if (did_action('wp_enqueue_scripts')) {
            self::enqueue();
        }
    }

    public static function maybe_enqueue(): void
    {
        if (!self::$enqueueRequested) {
            return;
        }

        self::enqueue();
    }

    private static function enqueue(): void
    {
        $plugin_main_file = dirname(__DIR__, 2) . '/FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php';
        $style_path       = dirname(__DIR__, 2) . '/assets/css/front.css';
        $script_path      = dirname(__DIR__, 2) . '/assets/js/front.js';

        $style_version  = file_exists($style_path) ? (string) filemtime($style_path) : HIC_PLUGIN_VERSION;
        $script_version = file_exists($script_path) ? (string) filemtime($script_path) : HIC_PLUGIN_VERSION;

        wp_register_style(
            'fp-exp-front',
            plugin_dir_url($plugin_main_file) . 'assets/css/front.css',
            [],
            $style_version
        );

        wp_register_script(
            'fp-exp-front',
            plugin_dir_url($plugin_main_file) . 'assets/js/front.js',
            ['wp-i18n'],
            $script_version,
            true
        );

        wp_enqueue_style('fp-exp-front');
        wp_enqueue_script('fp-exp-front');
    }
}

Assets::bootstrap();
