<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Hasil verifikasi eligibility asuransi/TPA per kunjungan.
 * Diisi billing setelah cek manual ke portal TPA.
 */
class InsuranceVerification extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_PENDING             = 'PENDING';
    public const STATUS_VERIFIED            = 'VERIFIED';
    public const STATUS_NEEDS_CLARIFICATION = 'NEEDS_CLARIFICATION';
    public const STATUS_REJECTED            = 'REJECTED';

    protected $fillable = [
        'visit_id',
        'insurer_id',
        'verified_by',
        'status',
        'policy_number',
        'member_name',
        'member_card_number',
        'plafon_amount',
        'copayment_percent',
        'copayment_amount',
        'covered_amount',
        'coverage_notes',
        'exclusion_flags',
        'issue_notes',
        'verified_at',
    ];

    protected $casts = [
        'exclusion_flags'    => 'array',
        'plafon_amount'      => 'decimal:2',
        'copayment_percent'  => 'decimal:2',
        'copayment_amount'   => 'decimal:2',
        'covered_amount'     => 'decimal:2',
        'verified_at'        => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(InsuranceClaim::class);
    }
}
