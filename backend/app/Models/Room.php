<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    // Tipe room.
    public const TYPE_KAMAR   = 'KAMAR';
    public const TYPE_ICU     = 'ICU';
    public const TYPE_ISOLASI = 'ISOLASI';
    public const TYPE_HCU     = 'HCU';

    protected $fillable = [
        'code',
        'name',
        'kelas_rawat',
        'type',
        'bpjs_kelas_code',
        'bpjs_ruang_code',
        'gender_policy',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class);
    }

    /** Bed yang aktif dipakai untuk papan room (status occupancy real-time). */
    public function activeBeds(): HasMany
    {
        return $this->hasMany(Bed::class)->where('is_active', true);
    }
}
