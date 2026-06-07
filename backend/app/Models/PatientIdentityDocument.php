<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Berkas identitas pasien (KTP — scan/foto/PDF), per-pasien.
 * Disimpan di disk `local` (privat). Lihat migration 2026_06_22_000001.
 * TANPA accessor URL publik — berkas hanya bisa diakses lewat endpoint ber-auth.
 */
class PatientIdentityDocument extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    public const DOC_TYPES = ['KTP', 'KK', 'PASPOR', 'SIM', 'KIA'];

    protected $fillable = [
        'patient_id',
        'doc_type',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'uploaded_by_id',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'uploaded_by_id');
    }
}
