<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Workflow klaim asuransi/TPA non-BPJS.
 * DRAFT → SUBMITTED → APPROVED | REJECTED → (revisi) → SUBMITTED
 *                  → APPEALED → APPROVED | REJECTED
 */
class InsuranceClaim extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_DRAFT     = 'DRAFT';
    public const STATUS_SUBMITTED = 'SUBMITTED';
    public const STATUS_APPROVED  = 'APPROVED';
    public const STATUS_REJECTED  = 'REJECTED';
    public const STATUS_APPEALED  = 'APPEALED';

    protected $fillable = [
        'visit_id',
        'insurer_id',
        'billing_invoice_id',
        'insurance_verification_id',
        'submitted_by',
        'status',
        'claim_amount',
        'approved_amount',
        'patient_responsibility',
        'submission_ref',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'documents_checklist',
        'rejection_code',
        'rejection_reason',
        'resubmission_count',
        'appeal_notes',
        'notes',
    ];

    protected $casts = [
        'documents_checklist'    => 'array',
        'claim_amount'           => 'decimal:2',
        'approved_amount'        => 'decimal:2',
        'patient_responsibility' => 'decimal:2',
        'submitted_at'           => 'datetime',
        'approved_at'            => 'datetime',
        'rejected_at'            => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id');
    }

    public function verification(): BelongsTo
    {
        return $this->belongsTo(InsuranceVerification::class, 'insurance_verification_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(InsuranceClaimLog::class)->orderBy('performed_at');
    }
}
