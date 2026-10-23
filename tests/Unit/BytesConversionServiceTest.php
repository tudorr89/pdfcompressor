<?php

namespace Tests\Unit;

use App\Services\BytesConversionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BytesConversionServiceTest extends TestCase
{
    #[DataProvider('sizes')]
    public function test_it_formats_byte_sizes(int|float $bytes, int $decimals, string $expected): void
    {
        $this->assertSame($expected, BytesConversionService::filesize($bytes, $decimals));
    }

    /**
     * @return array<string, array{int|float, int, string}>
     */
    public static function sizes(): array
    {
        return [
            'zero bytes' => [0, 2, '0 B'],
            'plain bytes' => [512, 2, '512 B'],
            'kilobytes' => [2048, 2, '2.00 kB'],
            'megabytes' => [1048576, 0, '1 MB'],
        ];
    }
}
