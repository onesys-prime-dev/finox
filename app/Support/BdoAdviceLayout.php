<?php

namespace App\Support;

/**
 * The Payment Advice area of the BDO Corporate Check Printing (3-part) sheet.
 * Measured from the scan (200 x 280 mm): the advice part spans y 64-204 mm; the pre-printed CHECK No.
 * sits at about x 148-175 mm, y 80-90 mm, so nothing is printed there.
 * Used by the seeder (fresh installs) and by the migration that upgrades existing databases.
 */
class BdoAdviceLayout
{
    public const CROP = ['top' => 64, 'height' => 140];

    /** @return list<array<string, mixed>> */
    public static function fields(): array
    {
        $f = fn (string $key, string $source, float $x, float $y, float $w, array $extra = []) => array_merge([
            'key' => $key, 'source' => $source, 'x' => $x, 'y' => $y, 'width' => $w, 'font_size' => 10, 'align' => 'left',
            'uppercase' => false, 'max_lines' => 1, 'format' => null, 'region' => 'advice',
        ], $extra);

        return [
            $f('adv_date', 'date', 12, 77, 60, ['label' => 'DATE', 'font_size' => 10]),
            $f('adv_amount', 'amount_number', 74, 77, 70, ['label' => 'AMOUNT (PHP)', 'font_size' => 10, 'bold' => true]),
            $f('adv_purpose', 'purpose', 12, 83, 90, ['label' => 'PURPOSE', 'font_size' => 9]),
            $f('adv_ref', 'reference', 104, 83, 40, ['label' => 'REF', 'font_size' => 9]),
            [
                'key' => 'breakdown', 'source' => 'breakdown', 'type' => 'table', 'region' => 'advice',
                'x' => 12, 'y' => 96, 'width' => 180, 'font_size' => 9, 'align' => 'left', 'uppercase' => false, 'max_lines' => 1, 'format' => null,
                'row_height' => 4, 'max_rows' => 15, 'header' => true, 'total' => true, 'total_label' => 'TOTAL',
                'overflow' => 'schedule', 'min_font_size' => 7,
                'columns' => [
                    ['key' => 'line_date', 'label' => 'DATE', 'x' => 0, 'width' => 18, 'align' => 'left'],
                    ['key' => 'reference_no', 'label' => 'OR / INV NO.', 'x' => 20, 'width' => 28, 'align' => 'left'],
                    ['key' => 'vendor', 'label' => 'VENDOR', 'x' => 50, 'width' => 42, 'align' => 'left'],
                    ['key' => 'particulars', 'label' => 'PARTICULARS', 'x' => 94, 'width' => 52, 'align' => 'left'],
                    ['key' => 'amount', 'label' => 'AMOUNT', 'x' => 148, 'width' => 32, 'align' => 'right'],
                ],
            ],
            $f('adv_by', 'prepared_by', 12, 186, 62, ['label' => 'Prepared by', 'font_size' => 9]),
            $f('adv_chk', 'free_text', 78, 186, 56, ['text' => 'Checked by: ______________', 'font_size' => 9]),
            $f('adv_apr', 'free_text', 136, 186, 56, ['text' => 'Approved by: ______________', 'font_size' => 9]),
        ];
    }

    /** Replace the advice region of a layout with the standard one. */
    public static function apply(array $layout): array
    {
        $keep = array_values(array_filter($layout['fields'] ?? [], fn ($x) => ($x['region'] ?? null) !== 'advice'));
        $layout['fields'] = array_merge($keep, self::fields());
        $layout['advice_crop'] = self::CROP;

        return $layout;
    }
}
