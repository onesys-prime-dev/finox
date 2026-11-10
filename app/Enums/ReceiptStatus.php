<?php

namespace App\Enums;

enum ReceiptStatus: string
{
    case Draft = 'draft';
    case Recorded = 'recorded';
    case Verified = 'verified';
    case Processed = 'processed';
    case Archived = 'archived';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }

    public static function options(): array
    {
        return array_map(fn ($c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
