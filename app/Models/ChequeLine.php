<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChequeLine extends Model
{
    protected $fillable = ['cheque_id', 'line_no', 'line_date', 'reference_no', 'vendor', 'particulars', 'amount', 'receipt_id'];

    protected function casts(): array
    {
        return ['line_date' => 'date', 'amount' => 'decimal:2'];
    }

    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }
}
