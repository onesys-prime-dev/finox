<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role_id', 'is_active', 'must_change_password', 'preferences',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'preferences' => 'array',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class);
    }

    public function savedFilters(string $scope): array
    {
        return SavedFilter::where('user_id', $this->id)->where('scope', $scope)->orderBy('name')
            ->get(['id', 'name', 'filters'])->toArray();
    }

    public function isAdmin(): bool
    {
        return $this->role?->name === 'admin';
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        return $this->role?->permissionKeys() ?? [];
    }

    public function hasPermission(string $key): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($key, $this->permissionKeys(), true);
    }
}
