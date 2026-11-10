<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChequeRelease extends Model
{
    protected $fillable = ['cheque_id', 'released_at', 'released_to', 'released_by', 'remarks', 'supporting_reference', 'recorded_by'];

    protected function casts(): array
    {
        return ['released_at' => 'date'];
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
