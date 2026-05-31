<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use HasUuids, SoftDeletes;

    public const PPA_DOKTER = 'DOKTER';
    public const PPA_PERAWAT = 'PERAWAT';
    public const PPA_APOTEKER = 'APOTEKER';
    public const PPA_GIZI = 'GIZI';
    public const PPA_FISIOTERAPIS = 'FISIOTERAPIS';
    public const PPA_LAINNYA = 'LAINNYA';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'legacy_uuid',
        'name',
        'nip',
        'profession',
        'sip',
        'str',
        'bpjs_dpjp_code',
        'nik',
        'satusehat_ihs',
        'phone',
        'email',
        'address',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function doctorExaminations(): HasMany
    {
        return $this->hasMany(DoctorExamination::class);
    }

    public function refractionRecords(): HasMany
    {
        return $this->hasMany(RefractionRecord::class);
    }

    public function doctorSchedules(): HasMany
    {
        return $this->hasMany(DoctorSchedule::class);
    }

    /** Peran PPA (CPPT terintegrasi) untuk employee ini, di-derive dari profession. */
    public function ppaRole(): string
    {
        return self::resolvePpaRole($this->profession);
    }

    /**
     * Derive peran PPA dari teks bebas profession via keyword match.
     * dokter -> DOKTER; perawat/bidan -> PERAWAT; apoteker/farmasi -> APOTEKER;
     * gizi/dietisien -> GIZI; fisio/rehab/terapis -> FISIOTERAPIS; selain itu -> LAINNYA.
     */
    public static function resolvePpaRole(?string $profession): string
    {
        $p = strtolower(trim((string) $profession));
        if ($p === '') {
            return self::PPA_LAINNYA;
        }
        if (str_contains($p, 'dokter')) {
            return self::PPA_DOKTER;
        }
        if (str_contains($p, 'perawat') || str_contains($p, 'bidan')) {
            return self::PPA_PERAWAT;
        }
        if (str_contains($p, 'apoteker') || str_contains($p, 'farmasi')) {
            return self::PPA_APOTEKER;
        }
        if (str_contains($p, 'gizi') || str_contains($p, 'dietisien')) {
            return self::PPA_GIZI;
        }
        if (str_contains($p, 'fisio') || str_contains($p, 'rehab') || str_contains($p, 'terapis')) {
            return self::PPA_FISIOTERAPIS;
        }

        return self::PPA_LAINNYA;
    }
}
