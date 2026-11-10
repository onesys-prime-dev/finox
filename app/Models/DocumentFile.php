<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DocumentFile extends Model
{
    protected $fillable = [
        'group_uuid', 'version', 'is_current', 'category', 'original_name', 'path', 'mime', 'size',
        'documentable_type', 'documentable_id', 'replace_reason', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'size' => 'integer'];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }
}
