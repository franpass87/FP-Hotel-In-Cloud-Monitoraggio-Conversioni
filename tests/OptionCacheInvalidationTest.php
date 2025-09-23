<?php

use PHPUnit\Framework\TestCase;

use function FpHic\Helpers\hic_clear_option_cache;
use function FpHic\Helpers\hic_get_option;
use function FpHic\Helpers\hic_safe_add_hook;

require_once __DIR__ . '/bootstrap.php';

final class OptionCacheInvalidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $hic_test_options, $hic_test_option_autoload, $hic_test_hooks;

        $hic_test_options = [];
        $hic_test_option_autoload = [];
        $hic_test_hooks = [];

        hic_clear_option_cache();

        hic_safe_add_hook('action', 'added_option', 'FpHic\\Helpers\\hic_clear_option_cache', 10, 1);
        hic_safe_add_hook('action', 'updated_option', 'FpHic\\Helpers\\hic_clear_option_cache', 10, 1);
        hic_safe_add_hook('action', 'deleted_option', 'FpHic\\Helpers\\hic_clear_option_cache', 10, 1);
    }

    public function test_cache_refreshes_when_option_is_added_and_deleted(): void
    {
        $defaultValue = 'default-value';

        $this->assertSame($defaultValue, hic_get_option('example_option', $defaultValue));

        $this->assertTrue(add_option('hic_example_option', 'stored-value'));
        $this->assertSame('stored-value', hic_get_option('example_option', $defaultValue));

        $this->assertTrue(delete_option('hic_example_option'));
        $this->assertSame($defaultValue, hic_get_option('example_option', $defaultValue));
    }
}
