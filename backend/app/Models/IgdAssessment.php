<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Asesmen Medis Gawat Darurat (RM 3.7) — 1:1 visit. Pola doctor_examinations:
 * blok terstruktur disimpan JSONB, skalar penting jadi kolom.
 */
class IgdAssessment extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'doctor_id',
        'anamnesa',
        'psikososial',
        'perilaku',
        'fisik',
        'mata_od_os',
        'penunjang',
        'diagnosa_kerja',
        'diagnosa_kerja_name',
        'diagnosa_banding',
        'planning',
        'keadaan_pulang',
        'perawatan_lanjutan',
        'waktu_keluar',
        'is_finalized',
        'finalized_at',
        'patient_document_id',
    ];

    protected $casts = [
        'anamnesa'     => 'array',
        'psikososial'  => 'array',
        'perilaku'     => 'array',
        'fisik'        => 'array',
        'mata_od_os'   => 'array',
        'penunjang'    => 'array',
        'planning'     => 'array',
        'is_finalized' => 'boolean',
        'waktu_keluar' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'doctor_id');
    }
}
