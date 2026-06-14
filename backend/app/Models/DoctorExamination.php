<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorExamination extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'legacy_uuid',
        'visit_id',
        'doctor_id',
        // Tab 2 — Anamnese
        'anamnese',
        // Palpebra (anterior, di atas Kornea pada UI)
        'sa_palpebra_od',
        'sa_palpebra_os',
        // Konjungtiva (anterior, di bawah Palpebra)
        'sa_konjungtiva_od',
        'sa_konjungtiva_os',
        // Segmen Anterior OD
        'sa_kornea_od',
        'sa_coa_od',
        'sa_iris_od',
        'sa_pupil_od',
        'sa_lensa_od',
        // Segmen Anterior OS
        'sa_kornea_os',
        'sa_coa_os',
        'sa_iris_os',
        'sa_pupil_os',
        'sa_lensa_os',
        // Catatan bebas segmen anterior (di bawah blok Anterior)
        'sa_notes',
        // Segmen Posterior OD
        'sp_papil_od',
        'sp_macula_od',
        'sp_retina_od',
        'sp_vitreous_od',
        // Segmen Posterior OS
        'sp_papil_os',
        'sp_macula_os',
        'sp_retina_os',
        'sp_vitreous_os',
        // Catatan bebas segmen posterior (di bawah blok Posterior)
        'sp_notes',
        // Sketsa/anotasi mata (OD/OS) — JSONB {od:{strokes,png_base64,template}, os:{...}}
        'eye_drawings',
        'slitlamp_notes',
        // Tab 4 — SOAP & Planning
        'soap_subjective',
        'soap_objective',
        'soap_assessment',
        'soap_plan',
        'diagnosis_utama',
        'diagnosis_utama_name',
        'diagnosis_sekunder',
        'diagnosis_text',
        'tindakan_codes',
        'planning',
        'surgery_package_id',
        'surgery_schedule_id',
        'external_referral_facility',
        'external_referral_reason',
        'medical_resume_id',
        // Finalisasi
        'is_finalized',
        'finalized_at',
        'digital_signature',
        'signature_timestamp',
    ];

    protected $casts = [
        'diagnosis_sekunder'  => 'array',
        'tindakan_codes'      => 'array',
        'eye_drawings'        => 'array',
        'is_finalized'        => 'boolean',
        'finalized_at'        => 'datetime',
        'signature_timestamp' => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'doctor_id');
    }

    public function surgeryPackage(): BelongsTo
    {
        return $this->belongsTo(SurgeryPackage::class);
    }

    public function surgerySchedule(): BelongsTo
    {
        return $this->belongsTo(SurgerySchedule::class);
    }

    public function medicalResume(): BelongsTo
    {
        return $this->belongsTo(MedicalResume::class);
    }
}
