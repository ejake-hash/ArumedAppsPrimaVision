<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NurseAssessment extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'assessed_by_id',
        'td_sistol',
        'td_diastol',
        'nadi',
        'suhu',
        'respirasi',
        'spo2',
        'kgd',
        'berat_badan',
        'tinggi_badan',
        'bmi',
        'has_allergy',
        'allergy_detail',
        'chief_complaint',
        'rps',
        'pain_scale',
        'assessment_notes',
        'is_finalized',
        'is_skipped',
        'finalized_at',
        'finalized_by_id',
    ];

    protected $casts = [
        'suhu'         => 'decimal:2',
        'spo2'         => 'decimal:2',
        'kgd'          => 'decimal:2',
        'berat_badan'  => 'decimal:2',
        'tinggi_badan' => 'decimal:2',
        'bmi'          => 'decimal:2',
        'has_allergy'  => 'boolean',
        'is_finalized' => 'boolean',
        'is_skipped'   => 'boolean',
        'finalized_at' => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assessed_by_id');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'finalized_by_id');
    }
}
