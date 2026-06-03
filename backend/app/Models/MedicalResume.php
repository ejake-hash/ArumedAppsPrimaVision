<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalResume extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'doctor_id',
        'resume_s',
        'resume_o',
        'resume_a',
        'resume_p',
        'penunjang_results',
        'rmrj_data',
        'is_editable',
        'is_finalized',
        'finalized_at',
        'generated_at',
        'printed_at',
    ];

    protected $casts = [
        'penunjang_results' => 'array',
        'rmrj_data'         => 'array',
        'is_editable'       => 'boolean',
        'is_finalized'      => 'boolean',
        'finalized_at'      => 'datetime',
        'generated_at'      => 'datetime',
        'printed_at'        => 'datetime',
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
