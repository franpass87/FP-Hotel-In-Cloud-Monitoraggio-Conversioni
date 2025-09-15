<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/config-validator.php';

final class PollingIntervalValidationTest extends TestCase
{
    /**
     * @dataProvider validIntervalsProvider
     */
    public function test_valid_intervals_do_not_trigger_warning(string $interval): void
    {
        update_option('hic_property_id', '123');
        update_option('hic_api_email', 'test@example.com');
        update_option('hic_api_password', 'secret');
        update_option('hic_polling_interval', $interval);

        \FpHic\Helpers\hic_clear_option_cache();

        $validator = new \FpHic\HIC_Config_Validator();
        $ref = new \ReflectionClass($validator);
        $method = $ref->getMethod('validate_polling_config');
        $method->setAccessible(true);
        $method->invoke($validator);

        $warnings = $ref->getProperty('warnings');
        $warnings->setAccessible(true);
        $errors = $ref->getProperty('errors');
        $errors->setAccessible(true);

        $this->assertSame([], $warnings->getValue($validator), 'Warnings should be empty for interval: ' . $interval);
        $this->assertSame([], $errors->getValue($validator), 'Errors should be empty for interval: ' . $interval);
    }

    public static function validIntervalsProvider(): array
    {
        return [
            ['every_minute'],
            ['every_two_minutes'],
            ['hic_poll_interval'],
            ['hic_reliable_interval'],
        ];
    }

    protected function tearDown(): void
    {
        global $hic_test_options;
        foreach (['hic_property_id','hic_api_email','hic_api_password','hic_polling_interval'] as $key) {
            unset($hic_test_options[$key]);
        }
        \FpHic\Helpers\hic_clear_option_cache();
    }
}
