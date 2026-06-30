<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasUuids, SoftDeletes, Notifiable;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employee_id',
        'role_id',
        'name',
        'username',
        'email',
        'password',
        'pin',
        'email_verified_at',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'pin',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at'     => 'datetime',
        'is_active'         => 'boolean',
        'password'          => 'hashed',
        // PIN tanda tangan digital (e-sign legal dokumen RM): di-hash sama seperti
        // password. Cast 'hashed' otomatis Hash::make saat di-set (tidak re-hash
        // nilai yang sudah ter-hash). Verifikasi via Hash::check di SEMUA gate PIN
        // (DokterController/DokterService/Refraksi/Perawat/SignatureService).
        'pin'               => 'hashed',
    ];

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'role'    => $this->role?->name,
            'user_id' => $this->id,
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Cek permission key di role user (mis. "admisi.write").
     * Superadmin: selalu true. User tanpa role: selalu false.
     */
    public function hasPermission(string $key): bool
    {
        return $this->role?->hasPermission($key) ?? false;
    }

    public function isSuperadmin(): bool
    {
        return $this->role?->isSuperadmin() ?? false;
    }
}
