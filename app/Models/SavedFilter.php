<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedFilter extends Model
{
    protected $fillable = ['user_id', 'scope', 'name', 'filters'];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}
