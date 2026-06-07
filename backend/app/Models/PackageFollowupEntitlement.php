<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Hak "konsultasi kontrol gratis pasca-bedah" milik pasien (ledger).
 * Lihat migrasi create_package_followup_entitlements_table.
 *
 * Terbit saat operasi selesai; ditebus di visit kontrol UMUM berikutnya.
 */
class PackageFollowupEntitlement extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType   = 'string';
    public $incrementing = false;

    protected $fillable = [
        'patient_id',
        'source_visit_id',
        'surgery_schedule_id',
        'source_surgery_package_id',
        'procedure_id',
        'total_count',
        'used_count',
        'valid_until',
        'is_active',
        'redeemed_visit_id',
        'redeemed_at',
        'notes',
    ];

    protected $casts = [
        'total_count' => 'integer',
        'used_count'  => 'integer',
        'valid_until' => 'date',
        'is_active'   => 'boolean',
        'redeemed_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function sourceVisit(): BelongsTo
    {
        return $this->belongsTo(Visit::class, 'source_visit_id');
    }

    public function sourcePackage(): BelongsTo
    {
        return $this->belongsTo(SurgeryPackage::class, 'source_surgery_package_id');
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    /** Masih bisa ditebus? (aktif, ada sisa jatah, belum kedaluwarsa) */
    public function isRedeemable(): bool
    {
        if (! $this->is_active || $this->used_count >= $this->total_count) {
            return false;
        }
        return $this->valid_until === null || ! $this->valid_until->isPast();
    }

    /**
     * Scope: hak yang masih bisa ditebus untuk satu pasien — aktif, jatah tersisa,
     * belum kedaluwarsa. Dipakai Kasir (penebusan) & Admisi (badge info).
     */
    public function scopeRedeemableForPatient(Builder $query, string $patientId): Builder
    {
        return $query->where('patient_id', $patientId)
            ->where('is_active', true)
            ->whereColumn('used_count', '<', 'total_count')
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()));
    }
}
