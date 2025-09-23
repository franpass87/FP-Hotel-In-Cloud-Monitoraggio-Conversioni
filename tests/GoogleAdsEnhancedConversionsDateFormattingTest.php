<?php declare(strict_types=1);

use FpHic\GoogleAdsEnhanced\GoogleAdsEnhancedConversions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/google-ads-enhanced.php';

final class GoogleAdsEnhancedConversionsDateFormattingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $hic_test_options, $hic_test_option_autoload;

        $hic_test_options = [];
        $hic_test_option_autoload = [];

        if (function_exists('\\FpHic\\Helpers\\hic_clear_option_cache')) {
            \FpHic\Helpers\hic_clear_option_cache();
        }
    }

    public function test_format_conversion_datetime_uses_wordpress_timezone(): void
    {
        update_option('timezone_string', 'Europe/Rome');
        delete_option('hic_property_timezone');

        $enhanced = new GoogleAdsEnhancedConversions();

        $method = new ReflectionMethod($enhanced, 'format_conversion_datetime');
        $method->setAccessible(true);

        $formatted = $method->invoke($enhanced, '2024-03-01 10:00:00');

        $this->assertSame('2024-03-01 10:00:00+0100', $formatted);
        $parsed = DateTime::createFromFormat('Y-m-d H:i:sO', $formatted);
        $this->assertInstanceOf(DateTime::class, $parsed);
        $this->assertSame('+0100', $parsed->format('O'));
    }
}
