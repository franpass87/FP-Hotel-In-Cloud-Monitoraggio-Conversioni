<?php declare(strict_types=1);

final class AdminMenuRegistrationTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $menu, $submenu;
        $menu    = [];
        $submenu = [];

        $GLOBALS['hic_registered_menu_pages'] = [];
        $GLOBALS['hic_registered_submenus']   = [];

        if (class_exists(\FpHic\AutomatedReporting\AutomatedReportingManager::class)) {
            $instanceProperty = new ReflectionProperty(\FpHic\AutomatedReporting\AutomatedReportingManager::class, 'instance');
            $instanceProperty->setAccessible(true);
            $instanceProperty->setValue(null);
        }
    }

    public function test_unified_monitor_menu_is_registered_with_hic_capability(): void
    {
        $dashboard = new \FpHic\RealtimeDashboard\RealtimeDashboard();
        $dashboard->add_dashboard_menu();

        $menus = $GLOBALS['hic_registered_menu_pages'] ?? [];
        $this->assertArrayHasKey('hic-monitoring', $menus);

        $menu = $menus['hic-monitoring'];
        $this->assertSame('hic-monitoring', $menu['menu_slug']);
        $this->assertSame('hic_manage', $menu['capability']);
        $this->assertSame('HIC Monitor', $menu['menu_title']);

        global $submenu;
        $this->assertArrayHasKey('hic-monitoring', $submenu);
        $this->assertNotEmpty($submenu['hic-monitoring']);
        $this->assertSame('Dashboard', $submenu['hic-monitoring'][0][0]);
    }

    public function test_setup_wizard_menu_uses_unified_parent(): void
    {
        $suite = new \FpHic\ReconAndSetup\EnterpriseManagementSuite();
        $suite->add_setup_wizard_menu();

        $this->assertSubmenuRegistered('hic-monitoring', 'hic-setup-wizard', 'Setup Wizard');
    }

    public function test_enhanced_conversions_menu_uses_unified_parent(): void
    {
        $conversions = new \FpHic\GoogleAdsEnhanced\GoogleAdsEnhancedConversions();
        $conversions->add_enhanced_conversions_menu();

        $this->assertSubmenuRegistered('hic-monitoring', 'hic-enhanced-conversions', 'Enhanced Conversions');
    }

    public function test_circuit_breakers_menu_uses_unified_parent(): void
    {
        $manager = new \FpHic\CircuitBreaker\CircuitBreakerManager(false);
        $manager->add_circuit_breaker_menu();

        $this->assertSubmenuRegistered('hic-monitoring', 'hic-circuit-breakers', 'Circuit Breakers');
    }

    public function test_reports_menu_is_registered_under_monitor(): void
    {
        $reflection = new ReflectionProperty(\FpHic\AutomatedReporting\AutomatedReportingManager::class, 'instance');
        $reflection->setAccessible(true);
        $reflection->setValue(null);

        $reports = \FpHic\AutomatedReporting\AutomatedReportingManager::instance();
        $reports->add_reports_menu();

        $this->assertSubmenuRegistered('hic-monitoring', 'hic-reports', 'Reports');
    }

    public function test_settings_and_diagnostics_share_unified_menu(): void
    {
        require_once __DIR__ . '/../includes/admin/admin-settings.php';

        hic_add_admin_menu();

        $this->assertSubmenuRegistered('hic-monitoring', 'hic-monitoring-settings', 'Impostazioni');
        $this->assertSubmenuRegistered('hic-monitoring', 'hic-diagnostics', 'Diagnostics');
    }

    /**
     * @param string $parent
     * @param string $slug
     * @param string $expectedLabel
     */
    private function assertSubmenuRegistered(string $parent, string $slug, string $expectedLabel): void
    {
        $submenus = $GLOBALS['hic_registered_submenus'][$parent] ?? [];
        $this->assertArrayHasKey($slug, $submenus, sprintf('Expected submenu %s to be registered under %s', $slug, $parent));

        $entry = $submenus[$slug];
        $this->assertSame($slug, $entry['menu_slug']);
        $this->assertSame('hic_manage', $entry['capability']);
        $this->assertSame($expectedLabel, $entry['menu_title']);
    }
}
