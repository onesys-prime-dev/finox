<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintLog extends Model
{
    protected $fillable = [
        'print_job_id', 'printable_type', 'printable_id', 'template_id', 'template_version',
        'status', 'is_reprint', 'note', 'user_id',
    ];

    protected function casts(): array
    {
        return ['is_reprint' => 'boolean'];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(PrintJob::class, 'print_job_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PrintTemplate::class, 'template_id');
    }
}
