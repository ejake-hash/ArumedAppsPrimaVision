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
        // Item resep pre-op "terserap ke paket": tetap tampil positif di kwitansi,
        // nilainya ikut basis DISKON_PAKET (net tetap = harga jual paket).
        'is_preop_absorbed',
        // Serupa, tapi diputuskan KASIR per baris rincian (obat tambahan/pasca-bedah).
        'is_paket_absorbed',
        // Varian kemasan jual terpilih (di-set Farmasi saat verifikasi). NULL = satuan
        // kecil. INVARIAN: quantity (satuan kecil, sumber kebenaran stok) =
        // sale_unit_qty × isi kemasan. Lihat FarmasiService::setKemasanItem.
        'sale_unit_id',
        'sale_unit_qty',
    ];

    protected $casts = [
        'is_bedah'          => 'boolean',
        'is_preop_absorbed' => 'boolean',
        'is_paket_absorbed' => 'boolean',
        'changed_at'    => 'datetime',
        'sale_unit_qty' => 'integer',
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

    public function saleUnit(): BelongsTo
    {
        return $this->belongsTo(MedicationSaleUnit::class, 'sale_unit_id');
    }
}
