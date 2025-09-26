<?php declare(strict_types=1);

namespace FpHic\Bootstrap;

final class UpgradeManager
{
    private const OPTION_PLUGIN_VERSION = 'hic_plugin_version';

    /** @var bool */
    private static $hasRun = false;

    public static function register(): void
    {
        if (!function_exists('add_action')) {
            self::maybeUpgrade();
            return;
        }

        add_action('plugins_loaded', [self::class, 'maybeUpgrade'], 5);
        add_action('upgrader_process_complete', [self::class, 'handleUpgraderProcessComplete'], 10, 2);
    }

    public static function maybeUpgrade(): void
    {
        if (self::$hasRun) {
            return;
        }

        self::$hasRun = true;

        if (!defined('HIC_PLUGIN_VERSION')) {
            return;
        }

        if (!function_exists('get_option') || !function_exists('update_option')) {
            return;
        }

        self::forEachSite(static function (): void {
            self::upgradeCurrentSite();
        });
    }

    /**
     * @param array<string,mixed> $hookExtra
     */
    public static function handleUpgraderProcessComplete($upgrader, $hookExtra): void
    {
        if (!is_array($hookExtra) || ($hookExtra['type'] ?? '') !== 'plugin') {
            return;
        }

        $plugins = $hookExtra['plugins'] ?? [];

        if (is_string($plugins)) {
            $plugins = [$plugins];
        }

        if (!defined('HIC_PLUGIN_BASENAME') || !in_array(HIC_PLUGIN_BASENAME, $plugins, true)) {
            return;
        }

        self::$hasRun = false;
        self::maybeUpgrade();
    }

    private static function upgradeCurrentSite(): void
    {
        $currentVersion = (string) HIC_PLUGIN_VERSION;
        $storedVersion  = get_option(self::OPTION_PLUGIN_VERSION);

        if (!is_string($storedVersion) || $storedVersion === '') {
            self::handleFreshInstall($currentVersion);
            return;
        }

        if (version_compare($storedVersion, $currentVersion, '>=')) {
            return;
        }

        self::runVersionedUpgrades($storedVersion, $currentVersion);
        self::finalizeUpgrade($currentVersion, $storedVersion);
    }

    private static function handleFreshInstall(string $currentVersion): void
    {
        if (function_exists('hic_maybe_upgrade_db')) {
            \hic_maybe_upgrade_db();
        }

        update_option(self::OPTION_PLUGIN_VERSION, $currentVersion);
        \FpHic\Helpers\hic_clear_option_cache(self::OPTION_PLUGIN_VERSION);
        \FpHic\Helpers\hic_clear_option_cache();

        self::flushCaches();

        if (function_exists('do_action')) {
            do_action('hic_plugin_fresh_install', $currentVersion);
        }
    }

    private static function runVersionedUpgrades(string $fromVersion, string $toVersion): void
    {
        if (function_exists('hic_maybe_upgrade_db')) {
            \hic_maybe_upgrade_db();
        }

        if (class_exists(Lifecycle::class)) {
            Lifecycle::ensureAdminCapabilities();
        }

        $upgradeSteps = [
            '3.2.0' => static function (): void {
                \FpHic\Helpers\hic_clear_option_cache('hic_db_version');
            },
            '3.3.0' => static function (): void {
                if (class_exists('FpHic\\ReconAndSetup\\EnterpriseManagementSuite')) {
                    \FpHic\ReconAndSetup\EnterpriseManagementSuite::maybe_install_tables();
                }

                if (class_exists('FpHic\\CircuitBreaker\\CircuitBreakerManager')) {
                    \FpHic\CircuitBreaker\CircuitBreakerManager::activate();
                }
            },
        ];

        foreach ($upgradeSteps as $version => $callback) {
            if (
                version_compare($fromVersion, $version, '<')
                && version_compare($toVersion, $version, '>=')
            ) {
                $callback();
            }
        }
    }

    private static function finalizeUpgrade(string $currentVersion, string $previousVersion): void
    {
        update_option(self::OPTION_PLUGIN_VERSION, $currentVersion);
        \FpHic\Helpers\hic_clear_option_cache(self::OPTION_PLUGIN_VERSION);
        \FpHic\Helpers\hic_clear_option_cache();

        self::flushCaches();

        if (function_exists('hic_maybe_upgrade_db')) {
            \hic_maybe_upgrade_db();
        }

        if (function_exists('do_action')) {
            do_action('hic_plugin_upgraded', $currentVersion, $previousVersion);
        }

        if (function_exists('hic_log')) {
            \FpHic\Helpers\hic_log(
                sprintf('HIC Monitor aggiornato da %s a %s', $previousVersion, $currentVersion),
                \HIC_LOG_LEVEL_INFO
            );
        }
    }

    private static function flushCaches(): void
    {
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        if (function_exists('opcache_reset') && ini_get('opcache.enable')) {
            @opcache_reset();
        }
    }

    private static function forEachSite(callable $callback): void
    {
        $currentBlogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;

        $callback($currentBlogId);

        if (!function_exists('is_multisite') || !is_multisite()) {
            return;
        }

        if (!function_exists('get_sites') || !function_exists('switch_to_blog') || !function_exists('restore_current_blog')) {
            return;
        }

        $sites = get_sites([
            'fields' => 'ids',
            'number' => 0,
        ]);

        foreach ($sites as $siteId) {
            $siteId = (int) $siteId;

            if ($siteId === $currentBlogId) {
                continue;
            }

            if (!switch_to_blog($siteId)) {
                continue;
            }

            try {
                $callback($siteId);
            } finally {
                restore_current_blog();
            }
        }
    }
}
