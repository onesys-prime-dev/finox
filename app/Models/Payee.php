<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payee extends Model
{
    protected $fillable = ['name', 'tin', 'default_payee_line', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
