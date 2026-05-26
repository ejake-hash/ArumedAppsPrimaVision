<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'guard_name',
        'display_name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    /**
     * Superadmin bypass — boleh segalanya tanpa pivot.
     */
    public function isSuperadmin(): bool
    {
        return $this->name === 'superadmin';
    }

    /**
     * Cek apakah role memiliki permission key (mis. "admisi.read").
     * Superadmin selalu true.
     */
    public function hasPermission(string $key): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }
        return $this->permissions()->where('key', $key)->exists();
    }
}
