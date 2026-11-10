<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChequeClearing extends Model
{
    protected $fillable = ['cheque_id', 'result', 'cleared_at', 'remarks', 'document_id', 'recorded_by'];

    protected function casts(): array
    {
        return ['cleared_at' => 'date'];
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
