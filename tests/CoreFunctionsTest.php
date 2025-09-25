<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use FpHic\Helpers;

final class CoreFunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('wp_date')) {
            function wp_date($format, $timestamp = null) {
                return date($format, $timestamp ?? time());
            }
        }
    }

    public function testBucketNormalization(): void
    {
        self::assertSame('gads', Helpers\fp_normalize_bucket('CL123456', null));
        self::assertSame('fbads', Helpers\fp_normalize_bucket(null, 'FB123456'));
        self::assertSame('gads', Helpers\fp_normalize_bucket('CL123456', 'FB123456'));
        self::assertSame('organic', Helpers\fp_normalize_bucket(null, null));
        self::assertSame('organic', Helpers\fp_normalize_bucket('', ''));
    }

    public function testEmailValidation(): void
    {
        self::assertTrue(Helpers\hic_is_valid_email('test@example.com'));
        self::assertTrue(Helpers\hic_is_valid_email('user.name+tag@domain.co.uk'));
        self::assertFalse(Helpers\hic_is_valid_email('invalid-email'));
        self::assertFalse(Helpers\hic_is_valid_email(''));
        self::assertFalse(Helpers\hic_is_valid_email(null));
    }

    public function testGtmHelperFunctions(): void
    {
        update_option('hic_gtm_enabled', '1');
        update_option('hic_gtm_container_id', 'GTM-TEST123');
        update_option('hic_tracking_mode', 'hybrid');

        self::assertTrue(Helpers\hic_is_gtm_enabled());
        self::assertSame('GTM-TEST123', Helpers\hic_get_gtm_container_id());
        self::assertSame('hybrid', Helpers\hic_get_tracking_mode());
    }

    public function testPriceNormalization(): void
    {
        self::assertEquals(1234.56, Helpers\hic_normalize_price('1.234,56'), '', 0.001);
        self::assertEquals(1234.56, Helpers\hic_normalize_price('1,234.56'), '', 0.001);
        self::assertEquals(1234.0, Helpers\hic_normalize_price('1234'), '', 0.001);
        self::assertEquals(1234.0, Helpers\hic_normalize_price('1234.00'), '', 0.001);
        self::assertSame(0.0, Helpers\hic_normalize_price('-10'));
    }

    public function testOtaEmailDetection(): void
    {
        self::assertTrue(Helpers\hic_is_ota_alias_email('guest123@guest.booking.com'));
        self::assertTrue(Helpers\hic_is_ota_alias_email('user@guest.airbnb.com'));
        self::assertTrue(Helpers\hic_is_ota_alias_email('test@expedia.com'));
        self::assertFalse(Helpers\hic_is_ota_alias_email('user@gmail.com'));
        self::assertFalse(Helpers\hic_is_ota_alias_email('user@hotel.com'));
    }

    public function testConfigurationHelpers(): void
    {
        self::assertIsString(Helpers\hic_get_measurement_id());
        self::assertIsString(Helpers\hic_get_api_secret());
        self::assertIsBool(Helpers\hic_is_brevo_enabled());
        self::assertIsBool(Helpers\hic_is_debug_verbose());
    }

    public function testReservationPhoneFallback(): void
    {
        if (!function_exists('add_action')) {
            function add_action(...$args) {}
        }

        require_once dirname(__DIR__) . '/includes/api/polling.php';

        $res = \FpHic\hic_transform_reservation(['whatsapp' => '12345']);
        self::assertSame('12345', $res['phone']);

        $res2 = \FpHic\hic_transform_reservation(['phone' => '67890', 'whatsapp' => '12345']);
        self::assertSame('67890', $res2['phone']);
    }
}
