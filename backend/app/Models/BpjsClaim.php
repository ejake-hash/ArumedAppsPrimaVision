<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BpjsClaim extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'no_sep',
        'patient_nik',
        'diagnosis_utama',
        'diagnosis_sekunder',
        'procedure_codes',
        'inacbgs_kode',
        'inacbgs_tarif',
        'lupis_data',
        'status',
        'bpjs_status',
        'bpjs_response',
        'submitted_at',
    ];

    protected $casts = [
        'diagnosis_sekunder' => 'array',
        'procedure_codes'    => 'array',
        'lupis_data'         => 'array',
        'bpjs_response'      => 'array',
        'inacbgs_tarif'      => 'decimal:2',
        'submitted_at'       => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(ClaimAuditLog::class);
    }
}
