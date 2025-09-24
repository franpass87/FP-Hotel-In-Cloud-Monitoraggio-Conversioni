<?php declare(strict_types=1);

final class DeactivationCapabilityCleanupTest extends WP_UnitTestCase
{
    /** @var array<string,bool> */
    private array $originalCaps = [];

    protected function setUp(): void
    {
        parent::setUp();

        $role = get_role('administrator');
        $this->assertNotNull($role, 'Administrator role must exist for capability tests');

        $this->originalCaps = [
            'hic_manage'   => $role->has_cap('hic_manage'),
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

    public function test_deactivate_removes_custom_capabilities(): void
    {
        $role = get_role('administrator');
        $this->assertNotNull($role);
        $role->add_cap('hic_manage');
        $role->add_cap('hic_view_logs');

        \FpHic\hic_deactivate();

        $role = get_role('administrator');
        $this->assertFalse($role->has_cap('hic_manage'));
        $this->assertFalse($role->has_cap('hic_view_logs'));
    }

    public function test_capability_cleanup_helper_is_idempotent(): void
    {
        $role = get_role('administrator');
        $this->assertNotNull($role);
        $role->remove_cap('hic_manage');
        $role->remove_cap('hic_view_logs');

        \FpHic\hic_remove_plugin_capabilities_for_current_site();

        $role = get_role('administrator');
        $this->assertFalse($role->has_cap('hic_manage'));
        $this->assertFalse($role->has_cap('hic_view_logs'));
    }
}
