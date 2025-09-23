<?php declare(strict_types=1);

use FpHic\GoogleAdsEnhanced\GoogleAdsEnhancedConversions;
use PHPUnit\Framework\TestCase;

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once __DIR__ . '/../includes/google-ads-enhanced.php';

final class EnhancedConversionsQueueAutoloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $hic_test_options, $hic_test_option_autoload;

        $hic_test_options = [];
        $hic_test_option_autoload = [];
    }

    public function test_queue_and_batch_updates_disable_autoload(): void
    {
        global $hic_test_options, $hic_test_option_autoload, $wpdb;

        $wpdb = new class {
            public string $prefix = 'wp_';

            public function prepare($query, ...$args)
            {
                return $query;
            }

            public function get_results($query, $output = ARRAY_A)
            {
                return [];
            }
        };

        $enhanced = new GoogleAdsEnhancedConversions();

        $queueForBatchUpload = new ReflectionMethod($enhanced, 'queue_for_batch_upload');
        $queueForBatchUpload->setAccessible(true);
        $queueForBatchUpload->invoke($enhanced, 123);

        $this->assertSame([123], $hic_test_options['hic_enhanced_conversions_queue']);
        $this->assertArrayHasKey('hic_enhanced_conversions_queue', $hic_test_option_autoload);
        $this->assertFalse($hic_test_option_autoload['hic_enhanced_conversions_queue']);

        $enhanced->batch_upload_enhanced_conversions();

        $this->assertSame([], $hic_test_options['hic_enhanced_conversions_queue']);
        $this->assertArrayHasKey('hic_enhanced_conversions_queue', $hic_test_option_autoload);
        $this->assertFalse($hic_test_option_autoload['hic_enhanced_conversions_queue']);
    }
}
