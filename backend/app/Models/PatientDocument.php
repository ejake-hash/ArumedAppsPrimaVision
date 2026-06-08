<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PatientDocument extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'visit_id',
        'bpjs_claim_id',
        'document_type_id',
        'document_number',
        'status',
        'revision',
        'supersedes_document_id',
        'created_by_station',
        'pending_signature_roles',
        'signatures',
        'reject_reason',
        'void_reason',
        'printed_count',
        'finalized_at',
        // Form Registry — snapshot fields (Fase 1)
        'template_code',
        'template_version',
        'rendered_html',
        'rendered_html_gz',
        'final_integrity_hash',
        // Lembar Klaim — tautan ke klaim + sidik koding saat di-generate.
        'claim_coding_hash',
    ];

    protected $casts = [
        'pending_signature_roles' => 'array',
        'signatures'              => 'array',
        'finalized_at'            => 'datetime',
        'revision'                => 'integer',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function bpjsClaim(): BelongsTo
    {
        return $this->belongsTo(BpjsClaim::class, 'bpjs_claim_id');
    }

    public function verification(): HasOne
    {
        return $this->hasOne(DocumentVerification::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function documentSignatures(): HasMany
    {
        return $this->hasMany(DocumentSignature::class)->orderBy('captured_at');
    }

    public function addenda(): HasMany
    {
        return $this->hasMany(DocumentAddendum::class);
    }
}
