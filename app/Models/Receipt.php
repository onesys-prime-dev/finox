<?php

namespace App\Models;

use App\Enums\ReceiptStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Receipt extends Model
{
    protected $fillable = [
        'receipt_number', 'receipt_date', 'payee_id', 'description', 'amount', 'tax_amount', 'tax_rate',
        'category_id', 'cheque_id', 'reference_no', 'remarks', 'status', 'duplicate_override_reason',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReceiptStatus::class,
            'receipt_date' => 'date',
            'amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
        ];
    }

    public function payee(): BelongsTo
    {
        return $this->belongsTo(Payee::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ReceiptCategory::class, 'category_id');
    }

    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
    }

    public function chequeLines(): HasMany
    {
        return $this->hasMany(ChequeLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(DocumentFile::class, 'documentable')->where('is_current', true);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(StatusHistory::class, 'subject_id')->where('subject_type', 'receipt')->orderBy('id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [ReceiptStatus::Draft, ReceiptStatus::Recorded, ReceiptStatus::Verified], true);
    }

    /** Still a draft: uploaded or started, but never recorded. */
    public function isDraft(): bool
    {
        return $this->getAttributeValue('status') === ReceiptStatus::Draft;
    }

    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        return $user->hasPermission('receipts.view_all') ? $q : $q->where('created_by', $user->id);
    }
}
