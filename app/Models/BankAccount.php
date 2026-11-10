<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    protected $fillable = [
        'bank_id', 'branch', 'account_name', 'account_number', 'account_reference', 'cheque_format',
        'print_template_id', 'starting_number', 'is_active', 'remarks',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PrintTemplate::class, 'print_template_id');
    }

    public function cheques(): HasMany
    {
        return $this->hasMany(Cheque::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return "{$this->bank?->name} · {$this->account_name} ({$this->account_number})";
    }

    /** Suggest the next cheque number, keeping the width (and leading zeros) of the last one. */
    public function nextChequeNumber(): ?string
    {
        $last = $this->cheques()->orderByDesc('id')->value('cheque_number');
        $last = $last ?: $this->starting_number;
        if (! $last || ! preg_match('/^(.*?)(\d+)$/', $last, $m)) {
            return null;
        }
        // If a starting number is set and no cheque exists yet, suggest it as-is.
        if (! $this->cheques()->exists()) {
            return $last;
        }
        $next = (string) ((int) $m[2] + 1);

        return $m[1].str_pad($next, strlen($m[2]), '0', STR_PAD_LEFT);
    }
}
