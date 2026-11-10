<?php

namespace App\Support;

/**
 * Canonical amount-in-words for cheques (Philippine pesos).
 * e.g. 165548.69 => "ONE HUNDRED SIXTY FIVE THOUSAND FIVE HUNDRED FORTY EIGHT AND 69/100 PESOS ONLY"
 * The JS twin in resources/js/lib/amountInWords.js must produce identical output.
 */
class AmountInWords
{
    private const ONES = ['', 'ONE', 'TWO', 'THREE', 'FOUR', 'FIVE', 'SIX', 'SEVEN', 'EIGHT', 'NINE', 'TEN',
        'ELEVEN', 'TWELVE', 'THIRTEEN', 'FOURTEEN', 'FIFTEEN', 'SIXTEEN', 'SEVENTEEN', 'EIGHTEEN', 'NINETEEN'];

    private const TENS = ['', '', 'TWENTY', 'THIRTY', 'FORTY', 'FIFTY', 'SIXTY', 'SEVENTY', 'EIGHTY', 'NINETY'];

    private const SCALES = ['', 'THOUSAND', 'MILLION', 'BILLION', 'TRILLION'];

    public static function convert(string|int|float $amount): string
    {
        $norm = Money::parse(is_float($amount) ? number_format($amount, 2, '.', '') : $amount);
        if ($norm === null) {
            throw new \InvalidArgumentException('Invalid amount.');
        }
        [$whole, $cents] = explode('.', $norm);

        $words = self::wholeToWords($whole);

        return "{$words} AND {$cents}/100 PESOS ONLY";
    }

    private static function wholeToWords(string $whole): string
    {
        if ((int) $whole === 0 && strlen(ltrim($whole, '0')) === 0) {
            return 'ZERO';
        }
        $groups = [];
        $s = $whole;
        while ($s !== '') {
            $groups[] = (int) substr($s, -3);
            $s = strlen($s) > 3 ? substr($s, 0, -3) : '';
        }
        $parts = [];
        foreach (array_reverse($groups, true) as $i => $g) {
            if ($g === 0) {
                continue;
            }
            $parts[] = trim(self::hundreds($g).' '.self::SCALES[$i]);
        }

        return implode(' ', $parts);
    }

    private static function hundreds(int $n): string
    {
        $out = [];
        if ($n >= 100) {
            $out[] = self::ONES[intdiv($n, 100)].' HUNDRED';
            $n %= 100;
        }
        if ($n >= 20) {
            $out[] = self::TENS[intdiv($n, 10)];
            $n %= 10;
        }
        if ($n > 0) {
            $out[] = self::ONES[$n];
        }

        return implode(' ', $out);
    }
}
