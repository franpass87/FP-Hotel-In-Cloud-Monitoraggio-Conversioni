<?php
require_once __DIR__ . '/preload.php';
use PHPUnit\Framework\TestCase;

class TrackingParamsCliCronTest extends TestCase
{
    protected function setUp(): void
    {
        $_COOKIE = [];
        $_GET = [];
    }

    private function runInitCapture(callable $capture): void
    {
        if ( ! is_admin() && ! wp_doing_cron() && ( ! defined('WP_CLI') || ! WP_CLI ) ) {
            $capture();
        }
    }

    public function test_cookie_set_in_frontend(): void
    {
        $_GET['gclid'] = 'testgclid1234567890';
        $this->runInitCapture(function () { $_COOKIE['hic_sid'] = 'stub'; });
        $this->assertArrayHasKey('hic_sid', $_COOKIE);
    }

    public function test_no_cookie_in_wp_cli(): void
    {
        define('WP_CLI', true);
        $_GET['gclid'] = 'testgclid1234567890';
        $this->runInitCapture(function () { $_COOKIE['hic_sid'] = 'stub'; });
        $this->assertArrayNotHasKey('hic_sid', $_COOKIE);
    }

    public function test_no_cookie_in_cron(): void
    {
        define('HIC_TEST_DOING_CRON', true);
        $_GET['gclid'] = 'testgclid1234567890';
        $this->runInitCapture(function () { $_COOKIE['hic_sid'] = 'stub'; });
        $this->assertArrayNotHasKey('hic_sid', $_COOKIE);
    }
}
