<?php

namespace App\Support;

/**
 * All money in FINOX is a decimal string / DECIMAL(15,2). Never a float.
 */
class Money
{
    /** Normalise user input ("1,234.5", "₱ 100") to a 2-decimal string, or null if invalid. */
    public static function parse(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim(str_replace(['₱', 'PHP', ' '], '', (string) $value));
        // Commas are only accepted as thousands separators ("1,234.50"); "1,5" is ambiguous, so reject it.
        if (str_contains($v, ',')) {
            if (! preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $v)) {
                return null;
            }
            $v = str_replace(',', '', $v);
        }
        if ($v === '' || ! preg_match('/^\d{1,13}(\.\d{1,2})?$/', $v)) {
            return null;
        }
        [$whole, $frac] = array_pad(explode('.', $v), 2, '');

        return ltrim($whole, '0') === '' ? '0.'.str_pad($frac, 2, '0') : ltrim($whole, '0').'.'.str_pad($frac, 2, '0');
    }

    public static function format(mixed $value, ?string $symbol = null): string
    {
        $symbol ??= Settings::get('currency_symbol', '₱');

        return $symbol.number_format((float) $value, 2, '.', ',');
    }

    /** Sum of decimal strings using integer centavos (no float drift). */
    public static function sum(iterable $values): string
    {
        $c = 0;
        foreach ($values as $v) {
            $c += (int) round(((float) $v) * 100);
        }

        return sprintf('%d.%02d', intdiv($c, 100), $c % 100);
    }
}
