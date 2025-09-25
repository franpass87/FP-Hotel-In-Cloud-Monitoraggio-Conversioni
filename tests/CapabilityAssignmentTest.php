<?php declare(strict_types=1);

final class CapabilityAssignmentTest extends WP_UnitTestCase
{
    /** @var array<string,bool> */
    private array $originalCaps = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('\\FpHic\\hic_ensure_admin_capabilities')) {
            require_once dirname(__DIR__) . '/FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php';
        }

        $role = get_role('administrator');
        $this->assertNotNull($role, 'Administrator role must be available for capability checks');

        $this->originalCaps = [
            'hic_manage'    => $role->has_cap('hic_manage'),
            'hic_view_logs' => $role->has_cap('hic_view_logs'),
        ];
    }

    protected function tearDown(): void
    {
        $role = get_role('administrator');
        if ($role) {
            foreach ($this->originalCaps as $capability => $hadCapability) {
                if ($hadCapability) {
                    $role->add_cap($capability);
                } else {
                    $role->remove_cap($capability);
                }
            }
        }

        parent::tearDown();
    }

    public function testEnsureAdminCapabilitiesAddsMissingCaps(): void
    {
        $role = get_role('administrator');
        $this->assertNotNull($role);

        $role->remove_cap('hic_manage');
        $role->remove_cap('hic_view_logs');

        \FpHic\hic_ensure_admin_capabilities();

        $this->assertTrue($role->has_cap('hic_manage'));
        $this->assertTrue($role->has_cap('hic_view_logs'));
    }
}
