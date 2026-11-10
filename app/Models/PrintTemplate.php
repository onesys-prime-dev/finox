<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrintTemplate extends Model
{
    protected $fillable = [
        'name', 'kind', 'paper_width_mm', 'paper_height_mm', 'background_path',
        'offset_x_mm', 'offset_y_mm', 'layout', 'version', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'layout' => 'array',
            'is_active' => 'boolean',
            'paper_width_mm' => 'float',
            'paper_height_mm' => 'float',
            'offset_x_mm' => 'float',
            'offset_y_mm' => 'float',
        ];
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    public function printLogs(): HasMany
    {
        return $this->hasMany(PrintLog::class, 'template_id');
    }

    public function getBackgroundUrlAttribute(): ?string
    {
        return $this->background_path ? route('templates.background', $this->id).'?v='.$this->version : null;
    }

    public function isInUse(): bool
    {
        return $this->bankAccounts()->exists() || $this->printLogs()->exists();
    }
}
