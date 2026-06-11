<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prescription extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    // Diskriminator alur resep (lihat migrasi add_type_to_prescriptions).
    public const TYPE_RAJAL = 'RAJAL';   // rawat jalan + obat pulang (lewat antrean Farmasi)
    public const TYPE_RANAP = 'RANAP';   // permintaan obat rawat inap (dispensing ke ruangan)

    protected $fillable = [
        'legacy_uuid',
        'visit_id',
        'prescribed_by_id',
        'status',
        'type',
        // Penanda resep PASCA-BEDAH (dibuat di BedahView) → revisi "Buka Kembali"
        // hanya mengganti resep ini, bukan resep dokter pada visit yang sama.
        'is_post_op',
        // Verifikasi Farmasi (gate sebelum tagihan Kasir). verified_at NULL = belum
        // diverifikasi → consolidateBilling menolak. Lihat migrasi add_pharmacy_verify_audit.
        'verified_by_id',
        'verified_at',
        'dispensed_by_id',
        'dispensed_at',
        'notes',
        'pharmacy_note',
    ];

    protected $casts = [
        'dispensed_at' => 'datetime',
        'verified_at'  => 'datetime',
        'is_post_op'   => 'boolean',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function prescribedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'prescribed_by_id');
    }

    public function dispensedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'dispensed_by_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'verified_by_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class);
    }
}
