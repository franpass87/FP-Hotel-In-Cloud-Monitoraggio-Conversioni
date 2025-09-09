<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/config-validator.php';

final class LocalEnvironmentTest extends TestCase
{
    public function test_sanitized_host_detects_local_environment()
    {
        $original_host = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'local<script>host';

        $validator = new FpHic\HIC_Config_Validator();

        $ref = new ReflectionClass($validator);
        $method = $ref->getMethod('is_local_environment');
        $method->setAccessible(true);

        $result = $method->invoke($validator);

        if ($original_host === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $original_host;
        }

        $this->assertTrue(
            $result,
            'Sanitized host should be recognized as local.'
        );
    }
}

