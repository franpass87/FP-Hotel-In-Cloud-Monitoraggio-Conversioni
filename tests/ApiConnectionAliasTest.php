<?php

use FpHic\HIC_Booking_Poller;

class ApiConnectionAliasTest extends WP_UnitTestCase {

    protected function setUp(): void {
        parent::setUp();
        require_once __DIR__ . '/../includes/config-validator.php';
        require_once __DIR__ . '/../includes/admin/diagnostics.php';
        $this->resetConfiguration();
    }

    protected function tearDown(): void {
        $this->resetConfiguration();
        parent::tearDown();
    }

    private function resetConfiguration(): void {
        delete_option('hic_connection_type');
        delete_option('hic_api_url');
        delete_option('hic_api_email');
        delete_option('hic_api_password');
        delete_option('hic_property_id');
        delete_option('hic_reliable_polling_enabled');
        \FpHic\Helpers\hic_clear_option_cache();
        unset($GLOBALS['hic_config_validator']);
    }

    private function configureApiConnection(string $connection_type): void {
        update_option('hic_connection_type', $connection_type);
        update_option('hic_api_url', 'https://api.hotelincloud.com/api/partner');
        update_option('hic_api_email', 'alias-test@example.com');
        update_option('hic_api_password', 'secure_password');
        update_option('hic_property_id', '98765');
        update_option('hic_reliable_polling_enabled', '1');
        \FpHic\Helpers\hic_clear_option_cache();
        unset($GLOBALS['hic_config_validator']);
    }

    private function assertSchedulerReady(): void {
        $state = [
            'connection_type' => hic_get_connection_type(),
            'normalized' => \FpHic\Helpers\hic_normalize_connection_type(),
            'api_url' => hic_get_api_url(),
            'has_credentials' => hic_has_basic_auth_credentials(),
            'uses_api' => \FpHic\Helpers\hic_connection_uses_api(),
            'should_schedule' => hic_should_schedule_poll_event(),
        ];

        $this->assertTrue(
            $state['should_schedule'],
            'Scheduler gate should allow polling. State: ' . json_encode($state)
        );

        $poller = new HIC_Booking_Poller();
        $stats = $poller->get_stats();
        $this->assertArrayHasKey('should_poll', $stats);
        $this->assertTrue(
            $stats['should_poll'],
            'Booking poller should be active. Stats: ' . json_encode($stats)
        );
    }

    private function assertValidatorAcceptsConnection(): void {
        $validator = new \FpHic\HIC_Config_Validator();
        $result = $validator->validate_all_config();

        foreach ($result['errors'] as $error) {
            $this->assertStringNotContainsString('Invalid connection type', $error);
        }
    }

    public function test_api_connection_type_is_valid(): void {
        $this->configureApiConnection('api');

        $this->assertTrue(\FpHic\Helpers\hic_connection_uses_api());
        $this->assertSchedulerReady();
        $this->assertValidatorAcceptsConnection();
    }

    public function test_polling_alias_behaves_like_api(): void {
        $this->configureApiConnection('polling');

        $this->assertSame('api', \FpHic\Helpers\hic_normalize_connection_type());
        $this->assertTrue(\FpHic\Helpers\hic_connection_uses_api());
        $this->assertSchedulerReady();
        $this->assertValidatorAcceptsConnection();
    }
}
