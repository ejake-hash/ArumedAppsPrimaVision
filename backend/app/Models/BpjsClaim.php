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
        'special_cmg_options',
        'special_cmg',
        'tarif_top_up',
        'total_cost_weight',
        'lupis_data',
        'status',
        'bpjs_status',
        'bpjs_response',
        'submitted_at',
        'resubmission_count',
        'rejection_reason',
        'rejected_at',
        'assigned_to_id',
        'assigned_at',
        // K2 — status pengiriman Data Center.
        'kemkes_dc_status',
        'bpjs_dc_status',
        'cob_dc_status',
        'dc_sent_at',
        // K3 — verifikasi, dispute/pending, pembayaran.
        'verif_status_code',
        'verif_status_name',
        'verif_checked_at',
        'jenis_dispute',
        'dispute_state',
        'bahv_no',
        'pending_note',
        'nominal_diajukan',
        'nominal_disetujui',
        'paid_at',
        'berita_acara_bayar_ref',
    ];

    protected $casts = [
        'diagnosis_sekunder' => 'array',
        'procedure_codes'    => 'array',
        'lupis_data'         => 'array',
        'bpjs_response'      => 'array',
        'special_cmg_options' => 'array',
        'inacbgs_tarif'      => 'decimal:2',
        'tarif_top_up'       => 'decimal:2',
        'total_cost_weight'  => 'decimal:4',
        'dc_sent_at'         => 'datetime',
        'verif_checked_at'   => 'datetime',
        'nominal_diajukan'   => 'decimal:2',
        'nominal_disetujui'  => 'decimal:2',
        'paid_at'            => 'datetime',
        'submitted_at'       => 'datetime',
        'resubmission_count' => 'integer',
        'rejected_at'        => 'datetime',
        'assigned_at'        => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(ClaimAuditLog::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ClaimAttachment::class, 'bpjs_claim_id');
    }
}
