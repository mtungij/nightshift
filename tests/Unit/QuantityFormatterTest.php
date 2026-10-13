<?php

namespace Tests\Unit;

use App\Support\QuantityFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuantityFormatterTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function quantities(): array
    {
        return [
            'integer' => ['4.0000', '4'],
            'crate' => ['24.000000', '24'],
            'bucket' => ['6.0000', '6'],
            'litres' => ['1.500000', '1.5'],
            'fraction' => ['0.7500', '0.75'],
            'small fraction' => ['0.000001', '0.000001'],
            'negative' => ['-24.0000', '-24'],
            'negative fraction' => ['-0.750000', '-0.75'],
            'zero' => ['-0.0000', '0'],
            'large precise value' => ['9999999999.123456', '9999999999.123456'],
        ];
    }

    #[DataProvider('quantities')]
    public function test_formats_quantities_without_rounding(string $input, string $expected): void
    {
        $this->assertSame($expected, QuantityFormatter::format($input));
    }
}
