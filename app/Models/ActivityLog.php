<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit row. Updates and deletes are refused at the model level;
 * there is no route that edits or removes entries.
 */
class ActivityLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Activity log entries are immutable.'));
        static::deleting(fn () => throw new \LogicException('Activity log entries cannot be deleted.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
