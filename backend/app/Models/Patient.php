<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Patient extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'legacy_uuid',
        'no_rm',
        'identity_type',
        'nik',
        'satusehat_ihs',
        'name',
        'gender',
        'date_of_birth',
        'tempat_lahir',
        'pekerjaan',
        'phone',
        'family_phone',
        'email',
        'address',
        'province',
        'nama_kab_kota',
        'nama_kecamatan',
        'nama_kelurahan',
        'bpjs_number',
        'blood_type',
        'allergy_notes',
        'photo_path',
        'is_active',
    ];

    protected $casts = [
        'date_of_birth' => 'date:Y-m-d',
        'is_active'     => 'boolean',
    ];

    /** URL foto pasien ikut diserialisasi ke semua response (search, visit.patient, dll). */
    protected $appends = ['photo_url'];

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path
            ? Storage::disk('public')->url($this->photo_path)
            : null;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function patientDocuments(): HasMany
    {
        return $this->hasMany(PatientDocument::class);
    }

    /** Berkas identitas (KTP — foto/PDF) milik pasien, disk privat. */
    public function identityDocuments(): HasMany
    {
        return $this->hasMany(PatientIdentityDocument::class);
    }
}
