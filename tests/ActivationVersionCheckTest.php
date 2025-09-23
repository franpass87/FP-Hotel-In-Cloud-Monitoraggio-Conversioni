<?php
if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '') {
        return '4.0';
    }
}
if (!function_exists('deactivate_plugins')) {
    function deactivate_plugins($plugin) {
        $GLOBALS['deactivated_plugin'] = $plugin;
    }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename($file) {
        return $file;
    }
}
if (!function_exists('did_action')) {
    function did_action($hook) {
        return 0;
    }
}
if (!function_exists('wp_die')) {
    function wp_die($message) {
        throw new Exception($message);
    }
}
if (!function_exists('__')) {
    function __($text, $domain = null) {
        return $text;
    }
}

use PHPUnit\Framework\TestCase;

class ActivationVersionCheckTest extends TestCase
{
    public function test_activation_blocks_on_low_wp_version()
    {
        require_once __DIR__ . '/../FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php';

        try {
            \FpHic\hic_activate(false);
            $this->fail('Activation should have been blocked.');
        } catch (Exception $e) {
            $this->assertStringContainsString('Richiede almeno PHP', $e->getMessage());
            $this->assertStringContainsString('WordPress 5.8', $e->getMessage());
            $this->assertEquals(
                realpath(__DIR__ . '/../FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php'),
                $GLOBALS['deactivated_plugin'] ?? null
            );
        }
    }
}
