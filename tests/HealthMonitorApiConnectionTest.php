<?php
namespace FpHic {
    function hic_test_api_connection($prop_id, $email, $password) {
        $GLOBALS['hic_api_test_called'] = [$prop_id, $email, $password];
        return ['success' => true, 'message' => 'API OK'];
    }
}
namespace {
use PHPUnit\Framework\TestCase;
if (!defined('HIC_FEATURE_HEALTH_MONITORING')) {
    define('HIC_FEATURE_HEALTH_MONITORING', false);
}
require_once __DIR__ . '/../includes/health-monitor.php';

class HealthMonitorApiConnectionTest extends TestCase
{
    public function test_check_api_connection_executes_api_test()
    {
        update_option('hic_property_id', 'prop');
        update_option('hic_api_email', 'email@example.com');
        update_option('hic_api_password', 'pass');

        $monitor = new \HIC_Health_Monitor();
        $ref = new \ReflectionClass($monitor);
        $method = $ref->getMethod('check_api_connection');
        $method->setAccessible(true);
        $result = $method->invoke($monitor);

        $this->assertSame(['prop', 'email@example.com', 'pass'], $GLOBALS['hic_api_test_called']);
        $this->assertSame('pass', $result['status']);
        $this->assertSame('API OK', $result['message']);
    }
}
}
