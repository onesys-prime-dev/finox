<?php

namespace App\Enums;

enum DocumentCategory: string
{
    case Receipt = 'receipt';
    case ChequeSupport = 'cheque_support';
    case ClearingProof = 'clearing_proof';
    case FinancialReference = 'financial_reference';
    case Report = 'report';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Receipt',
            self::ChequeSupport => 'Cheque supporting document',
            self::ClearingProof => 'Clearing proof',
            self::FinancialReference => 'Financial reference',
            self::Report => 'Report',
            self::Other => 'Other',
        };
    }

    public static function options(): array
    {
        return array_map(fn ($c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
