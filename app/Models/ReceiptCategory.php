<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceiptCategory extends Model
{
    protected $fillable = ['name', 'is_active'];

    public function receipts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Receipt::class, 'category_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
