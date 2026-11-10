<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProblemReport extends Model
{
    protected $fillable = ['user_id', 'url', 'message', 'is_resolved'];

    protected function casts(): array
    {
        return ['is_resolved' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
