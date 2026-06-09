<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrescriptionItem extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'legacy_uuid',
        'prescription_id',
        'medication_id',
        // Obat asli dokter sebelum disubstitusi Farmasi (audit). Lihat migrasi
        // add_pharmacy_verify_audit.
        'original_medication_id',
        // Asal item: RESEP | TAMBAHAN (obat tambahan apotek / OTC) + petugas penambah.
        'source',
        // Audit perubahan saat verifikasi Farmasi (substitusi/ubah qty/hapus).
        'change_reason',
        'changed_by_id',
        'changed_at',
        'added_by_id',
        'quantity',
        'dosage',
        'instructions',
        'notes',
        // Aturan pakai granular (dikirim & dibaca DokterView) — lihat migration
        // 2026_05_31_000010. Sebelumnya hilang krn belum fillable + kolom belum ada.
        'dose',
        'frequency',
        'route',
        'duration_days',
        // Penanda obat operasi (migrasi Gel-2): tercakup paket bedah, jangan dobel-tagih.
        'is_bedah',
    ];

    protected $casts = [
        'is_bedah'   => 'boolean',
        'changed_at' => 'datetime',
    ];

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'added_by_id');
    }
}
