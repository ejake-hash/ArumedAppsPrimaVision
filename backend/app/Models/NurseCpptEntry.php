<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan Perkembangan Pasien Terintegrasi (CPPT) untuk perawat.
 *
 * Append-only timeline per visit. Soft-edit lewat kolom edited_at/edited_by_id
 * — versi lama tidak disimpan, hanya jejak siapa edit kapan.
 */
class NurseCpptEntry extends Model
{
    use HasUuids;

    protected $table = 'nurse_cppt_entries';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'nurse_assessment_id',
        'ppa_role',
        'td_sistol',
        'td_diastol',
        'nadi',
        'suhu',
        'respirasi',
        'spo2',
        'kgd',
        'pain_scale',
        'visus_od',
        'visus_os',
        'iop_od',
        'iop_os',
        'iop_method',
        'notes',
        'soap_s',
        'soap_o',
        'soap_a',
        'soap_p',
        'instruksi',
        'created_by_id',
        'edited_at',
        'edited_by_id',
        'verified_by_id',
        'verified_at',
    ];

    protected $casts = [
        'suhu'        => 'decimal:2',
        'spo2'        => 'decimal:2',
        'kgd'         => 'decimal:2',
        'iop_od'      => 'decimal:2',
        'iop_os'      => 'decimal:2',
        'edited_at'   => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(NurseAssessment::class, 'nurse_assessment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by_id');
    }

    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'edited_by_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'verified_by_id');
    }
}
