<?php

namespace Tests\Unit;

use App\Support\AmountInWords;
use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AmountInWordsTest extends TestCase
{
    public static function cases(): array
    {
        return [
            ['165548.69', 'ONE HUNDRED SIXTY FIVE THOUSAND FIVE HUNDRED FORTY EIGHT AND 69/100 PESOS ONLY'],
            ['33151.68', 'THIRTY THREE THOUSAND ONE HUNDRED FIFTY ONE AND 68/100 PESOS ONLY'],
            ['0.01', 'ZERO AND 01/100 PESOS ONLY'],
            ['1', 'ONE AND 00/100 PESOS ONLY'],
            ['1000000', 'ONE MILLION AND 00/100 PESOS ONLY'],
            ['1000001.5', 'ONE MILLION ONE AND 50/100 PESOS ONLY'],
            ['90141.01', 'NINETY THOUSAND ONE HUNDRED FORTY ONE AND 01/100 PESOS ONLY'],
            ['12,345.60', 'TWELVE THOUSAND THREE HUNDRED FORTY FIVE AND 60/100 PESOS ONLY'],
            ['100000', 'ONE HUNDRED THOUSAND AND 00/100 PESOS ONLY'],
            ['2000000000.99', 'TWO BILLION AND 99/100 PESOS ONLY'],
        ];
    }

    #[DataProvider('cases')]
    public function test_converts(string $in, string $out): void
    {
        $this->assertSame($out, AmountInWords::convert($in));
    }

    public function test_rejects_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AmountInWords::convert('12.345');
    }

    public function test_money_parse_and_sum(): void
    {
        $this->assertSame('1234.50', Money::parse('1,234.5'));
        $this->assertNull(Money::parse('abc'));
        $this->assertNull(Money::parse('-5'));
        $this->assertSame('0.30', Money::sum(['0.10', '0.20']));
    }
}
