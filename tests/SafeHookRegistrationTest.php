<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use function FpHic\Helpers\hic_safe_add_hook;

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('hic_test_sample_action_callback')) {
    function hic_test_sample_action_callback(): void {}
}

if (!function_exists('hic_test_sample_filter_callback')) {
    function hic_test_sample_filter_callback($value = null) {
        return $value;
    }
}

final class SafeHookRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['hic_test_hooks'] = [];
        $GLOBALS['hic_test_filters'] = [];
    }

    public function test_duplicate_action_registration_is_prevented(): void
    {
        hic_safe_add_hook('action', 'hic_test_action', 'hic_test_sample_action_callback');
        hic_safe_add_hook('action', 'hic_test_action', 'hic_test_sample_action_callback');

        $this->assertArrayHasKey('hic_test_action', $GLOBALS['hic_test_hooks']);
        $this->assertArrayHasKey(10, $GLOBALS['hic_test_hooks']['hic_test_action']);
        $this->assertCount(1, $GLOBALS['hic_test_hooks']['hic_test_action'][10]);
    }

    public function test_duplicate_filter_registration_is_prevented(): void
    {
        hic_safe_add_hook('filter', 'hic_test_filter', 'hic_test_sample_filter_callback');
        hic_safe_add_hook('filter', 'hic_test_filter', 'hic_test_sample_filter_callback');

        $this->assertArrayHasKey('hic_test_filter', $GLOBALS['hic_test_filters']);
        $this->assertArrayHasKey(10, $GLOBALS['hic_test_filters']['hic_test_filter']);
        $this->assertCount(1, $GLOBALS['hic_test_filters']['hic_test_filter'][10]);
    }

    public function test_duplicate_closure_registration_is_prevented(): void
    {
        $closure = static function (): void {};

        hic_safe_add_hook('action', 'hic_test_closure', $closure);
        hic_safe_add_hook('action', 'hic_test_closure', $closure);

        $this->assertArrayHasKey('hic_test_closure', $GLOBALS['hic_test_hooks']);
        $this->assertArrayHasKey(10, $GLOBALS['hic_test_hooks']['hic_test_closure']);
        $this->assertCount(1, $GLOBALS['hic_test_hooks']['hic_test_closure'][10]);
    }
}
