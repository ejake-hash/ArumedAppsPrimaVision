<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail status klaim TPA. Immutable: tidak ada SoftDeletes.
 */
class InsuranceClaimLog extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const ACTION_CREATED     = 'CREATED';
    public const ACTION_SUBMITTED   = 'SUBMITTED';
    public const ACTION_APPROVED    = 'APPROVED';
    public const ACTION_REJECTED    = 'REJECTED';
    public const ACTION_APPEALED    = 'APPEALED';
    public const ACTION_RESUBMITTED = 'RESUBMITTED';
    public const ACTION_NOTE_ADDED  = 'NOTE_ADDED';

    protected $fillable = [
        'insurance_claim_id',
        'performed_by',
        'action',
        'from_status',
        'to_status',
        'notes',
        'metadata',
        'performed_at',
    ];

    protected $casts = [
        'metadata'     => 'array',
        'performed_at' => 'datetime',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(InsuranceClaim::class, 'insurance_claim_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
