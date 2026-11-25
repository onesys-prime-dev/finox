<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceiptCategory extends Model
{
    protected $fillable = ['name', 'is_active'];

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class, 'category_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
