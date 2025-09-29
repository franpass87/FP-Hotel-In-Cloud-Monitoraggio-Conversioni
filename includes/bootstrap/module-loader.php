<?php declare(strict_types=1);

namespace FpHic\Bootstrap;

/**
 * Centralizes the "require" statements that wire plugin modules.
 *
 * The previous bootstrap sequence duplicated the module list across multiple
 * closures and made it difficult to understand which files were always loaded
 * versus late-loaded or admin-only. This loader exposes explicit groups that
 * can be triggered at the right time (early bootstrap, "init", and admin).
 */
final class ModuleLoader
{
    /** @var self|null */
    private static $instance = null;

    /** @var string */
    private $baseDir;

    /** @var bool */
    private $coreLoaded = false;

    /** @var bool */
    private $initLoaded = false;

    /** @var bool */
    private $adminLoaded = false;

    private const CORE_MODULES = [
        'includes/constants.php',
        'includes/functions.php',
        'includes/log-manager.php',
        'includes/http-security.php',
        'includes/input-validator.php',
        'includes/cache-manager.php',
        'includes/rate-limiter.php',
        'includes/booking-poller.php',
        'includes/intelligent-polling-manager.php',
        'includes/database-optimizer.php',
        'includes/booking-metrics.php',
        'includes/realtime-dashboard.php',
        'includes/automated-reporting.php',
        'includes/google-ads-enhanced.php',
        'includes/circuit-breaker.php',
        'includes/enterprise-management-suite.php',
        'includes/helpers-logging.php',
        'includes/helpers-tracking.php',
        'includes/helpers-scheduling.php',
        'includes/runtime-dev-logger.php',
        'includes/database.php',
        'includes/privacy.php',
        'includes/api/rate-limit-controller.php',
        'includes/uninstall.php',
    ];

    private const INIT_MODULES = [
        'includes/booking-processor.php',
        'includes/integrations/ga4.php',
        'includes/integrations/gtm.php',
        'includes/integrations/facebook.php',
        'includes/integrations/brevo.php',
        'includes/api/webhook.php',
        'includes/api/polling.php',
        'includes/cli.php',
        'includes/config-validator.php',
        'includes/performance-monitor.php',
        'includes/performance-analytics-dashboard.php',
        'includes/health-monitor.php',
        'includes/experience/helpers.php',
        'includes/experience/assets.php',
        'includes/experience/shortcodes.php',
    ];

    private const ADMIN_MODULES = [
        'includes/admin/admin-settings.php',
        'includes/admin/diagnostics.php',
        'includes/site-health.php',
    ];

    private function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, '/');
    }

    public static function instance(string $baseDir = ''): self
    {
        if (null === self::$instance) {
            if ('' === $baseDir) {
                throw new \RuntimeException('ModuleLoader::instance requires a base path on first call.');
            }

            self::$instance = new self($baseDir);
        }

        return self::$instance;
    }

    public function loadCore(): void
    {
        if ($this->coreLoaded) {
            return;
        }

        $this->coreLoaded = true;

        $this->requireGroup(self::CORE_MODULES);
    }

    public function loadInit(): void
    {
        if ($this->initLoaded) {
            return;
        }

        $this->initLoaded = true;

        $this->requireGroup(self::INIT_MODULES);
    }

    public function loadAdmin(): void
    {
        if ($this->adminLoaded) {
            return;
        }

        $this->adminLoaded = true;

        $this->requireGroup(self::ADMIN_MODULES);
    }

    private function requireGroup(array $paths): void
    {
        foreach ($paths as $relativePath) {
            $this->requireRelative($relativePath);
        }
    }

    private function requireRelative(string $relativePath): void
    {
        require_once $this->baseDir . '/' . ltrim($relativePath, '/');
    }
}
