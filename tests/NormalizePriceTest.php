<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/bootstrap.php';

use FpHic\Helpers;

final class NormalizePriceTest extends TestCase
{
    #[DataProvider('priceProvider')]
    public function testNormalizePrice($input, $expected)
    {
        $this->assertEqualsWithDelta($expected, Helpers\hic_normalize_price($input), 0.0001);
    }

    public static function priceProvider()
    {
        return [
            ['1.234,56', 1234.56],
            ['1,234.56', 1234.56],
            ['  1234,56  ', 1234.56],
            ['abc', 0.0],
            ['', 0.0],
            [null, 0.0],
        ];
    }
}
