<?php

namespace App\Models;

use App\Enums\ChequeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Cheque extends Model
{
    protected $fillable = [
        'bank_account_id', 'cheque_number', 'cheque_date', 'payee_id', 'payee_line', 'is_cash', 'amount',
        'amount_words', 'purpose', 'reference_no', 'remarks', 'status', 'stop_payment_note',
        'prepared_at', 'printed_at', 'print_count', 'stale_flagged_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ChequeStatus::class,
            'cheque_date' => 'date',
            'is_cash' => 'boolean',
            'amount' => 'decimal:2',
            'prepared_at' => 'datetime',
            'printed_at' => 'datetime',
            'stale_flagged_at' => 'datetime',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function payee(): BelongsTo
    {
        return $this->belongsTo(Payee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function release(): HasOne
    {
        return $this->hasOne(ChequeRelease::class);
    }

    public function clearing(): HasOne
    {
        return $this->hasOne(ChequeClearing::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ChequeLine::class)->orderBy('line_no');
    }

    /** Sum of the breakdown rows, as a 2-decimal string. */
    public function linesTotal(): string
    {
        return \App\Support\Money::sum($this->lines->pluck('amount'));
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(DocumentFile::class, 'documentable')->where('is_current', true);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(StatusHistory::class, 'subject_id')->where('subject_type', 'cheque')->orderBy('id');
    }

    public function printLogs(): MorphMany
    {
        return $this->morphMany(PrintLog::class, 'printable', 'printable_type', 'printable_id');
    }

    /** The text printed on the "Pay to the order of" line. */
    public function getPrintedPayeeAttribute(): string
    {
        if ($this->is_cash) {
            return 'CASH';
        }

        return $this->payee_line ?: ($this->payee?->name ?? '');
    }

    /** Fields that become read-only once the cheque is Prepared. */
    public function isLocked(): bool
    {
        return $this->status !== ChequeStatus::Draft;
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $user->hasPermission('cheques.view_all') ? $q : $q->where('created_by', $user->id);
    }
}
