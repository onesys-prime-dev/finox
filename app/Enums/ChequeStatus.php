<?php

namespace App\Enums;

enum ChequeStatus: string
{
    case Draft = 'draft';
    case Prepared = 'prepared';
    case Printed = 'printed';
    case Released = 'released';
    case ForClearing = 'for_clearing';
    case Cleared = 'cleared';
    case Bounced = 'bounced';
    case Cancelled = 'cancelled';
    case Voided = 'voided';
    case Stale = 'stale';

    public function label(): string
    {
        return match ($this) {
            self::ForClearing => 'For Clearing',
            default => ucfirst($this->value),
        };
    }

    /** Statuses from which a cheque may still be cancelled or voided. */
    public static function stoppable(): array
    {
        return [self::Draft, self::Prepared, self::Printed, self::Released];
    }

    /** "Outstanding" = handed out but not yet resolved. */
    public static function outstanding(): array
    {
        return [self::Released, self::ForClearing];
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
