<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrintJob extends Model
{
    protected $fillable = [
        'user_id', 'category', 'is_reprint', 'reason', 'status', 'item_count', 'settings', 'note', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return ['is_reprint' => 'boolean', 'settings' => 'array', 'confirmed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(PrintLog::class);
    }
}
