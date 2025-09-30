<?php declare(strict_types=1);

namespace FpHic\Bootstrap;

use FpHic\Helpers;

final class Lifecycle
{
    /**
     * Execute a callback for the current site and every site within a network.
     *
     * @param callable(int=):void $callback Callback receiving the processed blog ID.
     */
    public static function forEachSite(callable $callback): void
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

    /**
     * Run activation routines for the current or every site in a network.
     *
     * @param bool $networkWide Whether the plugin is being activated network-wide.
     */
    public static function activate(bool $networkWide): void
    {
        $prepareSite = static function (): void {
            \hic_maybe_upgrade_db();

            if (Helpers\hic_should_bootstrap_feature('enterprise_suite')
                && class_exists('FpHic\\ReconAndSetup\\EnterpriseManagementSuite')
            ) {
                \FpHic\ReconAndSetup\EnterpriseManagementSuite::maybe_install_tables();
            }

            \FpHic\CircuitBreaker\CircuitBreakerManager::activate();

            $role = function_exists('get_role') ? get_role('administrator') : null;
            if ($role) {
                if (!$role->has_cap('hic_manage')) {
                    $role->add_cap('hic_manage');
                }
                if (!$role->has_cap('hic_view_logs')) {
                    $role->add_cap('hic_view_logs');
                }
            }

            self::clearScheduledHooks();
            self::ensureLogDirectory();

            if (function_exists('update_option')) {
                update_option('hic_plugin_version', HIC_PLUGIN_VERSION);
                Helpers\hic_clear_option_cache('hic_plugin_version');
            }
        };

        if ($networkWide) {
            self::forEachSite(static function () use ($prepareSite): void {
                $prepareSite();
            });

            return;
        }

        $prepareSite();
    }

    /**
     * Synchronise administrator capabilities on every request.
     */
    public static function ensureAdminCapabilities(): void
    {
        if (!function_exists('get_role') || !class_exists('\\WP_Role')) {
            return;
        }

        $targetRoles = ['administrator'];

        foreach ($targetRoles as $roleName) {
            $role = get_role($roleName);

            if (!($role instanceof \WP_Role)) {
                continue;
            }

            if (!$role->has_cap('hic_manage')) {
                $role->add_cap('hic_manage');
            }

            if (!$role->has_cap('hic_view_logs')) {
                $role->add_cap('hic_view_logs');
            }
        }
    }

    /**
     * Register hooks to keep multisite provisioning aligned with single-site behaviour.
     */
    public static function registerNetworkProvisioningHook(): void
    {
        if (!function_exists('is_multisite') || !is_multisite()) {
            return;
        }

        if (!function_exists('switch_to_blog') || !function_exists('restore_current_blog')) {
            return;
        }

        add_action('wpmu_new_blog', [self::class, 'handleNewBlogProvisioning']);
    }

    /**
     * Register actions that keep administrator capabilities in sync.
     */
    public static function registerCapabilitySyncHooks(): void
    {
        self::ensureAdminCapabilities();

        add_action('init', [self::class, 'ensureAdminCapabilities']);
        add_action('admin_init', [self::class, 'ensureAdminCapabilities']);
    }

    /**
     * Handle provisioning of new blogs in a multisite network.
     */
    public static function handleNewBlogProvisioning(int $blogId): void
    {
        if (!switch_to_blog($blogId)) {
            return;
        }

        try {
            self::ensureAdminCapabilities();
            \hic_maybe_upgrade_db();

            if (Helpers\hic_should_bootstrap_feature('enterprise_suite')
                && class_exists('FpHic\\ReconAndSetup\\EnterpriseManagementSuite')
            ) {
                \FpHic\ReconAndSetup\EnterpriseManagementSuite::maybe_install_tables();
            }

            \FpHic\CircuitBreaker\CircuitBreakerManager::activate();

            if (function_exists('update_option')) {
                update_option('hic_plugin_version', HIC_PLUGIN_VERSION);
                Helpers\hic_clear_option_cache('hic_plugin_version');
            }
        } finally {
            restore_current_blog();
        }
    }

    private static function clearScheduledHooks(): void
    {
        if (!function_exists('wp_clear_scheduled_hook')) {
            return;
        }

        wp_clear_scheduled_hook('hic_db_database_optimization');
        wp_clear_scheduled_hook('hic_reconciliation');
        wp_clear_scheduled_hook('hic_capture_tracking_params');
    }

    private static function ensureLogDirectory(): void
    {
        if (!defined('WP_CONTENT_DIR')) {
            return;
        }

        $status = Helpers\hic_ensure_log_directory_security();
        $logDir = $status['path'] !== ''
            ? $status['path']
            : WP_CONTENT_DIR . '/uploads/hic-logs';

        if ($logDir === '') {
            return;
        }

        if (!$status['directory']) {
            $errorMessage = $status['errors']['directory'] ?? 'errore sconosciuto';
            Helpers\hic_log(
                sprintf(
                    'Impossibile creare la cartella dei log %s: %s',
                    $logDir,
                    $errorMessage
                ),
                \HIC_LOG_LEVEL_ERROR
            );

            add_action('admin_notices', static function () use ($logDir): void {
                echo '<div class="notice notice-error"><p>' .
                    esc_html(
                        sprintf(
                            __('Impossibile creare la cartella dei log %s. Verifica i permessi.', 'hotel-in-cloud'),
                            $logDir
                        )
                    ) .
                    '</p></div>';
            });

            return;
        }

        if (!$status['htaccess']) {
            $htaccess = $logDir . '/.htaccess';
            $errorMessage = $status['errors']['htaccess'] ?? ($status['errors']['directory'] ?? 'errore sconosciuto');

            Helpers\hic_log(
                sprintf(
                    'Impossibile creare il file %s: %s',
                    $htaccess,
                    $errorMessage
                ),
                \HIC_LOG_LEVEL_ERROR
            );

            add_action('admin_notices', static function () use ($htaccess): void {
                echo '<div class="notice notice-error"><p>' .
                    esc_html(
                        sprintf(
                            __('Impossibile creare il file %s. Verifica i permessi.', 'hotel-in-cloud'),
                            $htaccess
                        )
                    ) .
                    '</p></div>';
            });
        }

        if (!$status['web_config']) {
            $webConfig = $logDir . '/web.config';
            $errorMessage = $status['errors']['web_config'] ?? ($status['errors']['directory'] ?? 'errore sconosciuto');

            Helpers\hic_log(
                sprintf(
                    'Impossibile creare il file %s: %s',
                    $webConfig,
                    $errorMessage
                ),
                \HIC_LOG_LEVEL_ERROR
            );

            add_action('admin_notices', static function () use ($webConfig): void {
                echo '<div class="notice notice-error"><p>' .
                    esc_html(
                        sprintf(
                            __('Impossibile creare il file %s. Verifica i permessi.', 'hotel-in-cloud'),
                            $webConfig
                        )
                    ) .
                    '</p></div>';
            });
        }
    }
}
