<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Komponen snapshot paket pasien. PROCEDURE/BHP/IOL (paket bedah) + MEDICATION
 * (khusus paket PEMERIKSAAN: daftar obat "ekspektasi" untuk absorpsi diskon — obat
 * tetap ditagih lewat resep, bukan dari snapshot ini).
 */
class VisitSurgeryPackageItem extends Model
{
    use HasUuids;

    protected $keyType   = 'string';
    public $incrementing = false;

    public const TYPE_PROCEDURE  = 'PROCEDURE';
    public const TYPE_BHP        = 'BHP';
    public const TYPE_IOL        = 'IOL';
    public const TYPE_MEDICATION = 'MEDICATION';

    /** Komponen yang boleh diedit operator di modul Bedah (incl. obat komposisi). */
    public const EDITABLE_TYPES = [self::TYPE_PROCEDURE, self::TYPE_BHP, self::TYPE_MEDICATION];

    /** Komponen yang masuk basis perhitungan diskon (obat diserap terpisah via resep). */
    public const BILLABLE_TYPES = [self::TYPE_PROCEDURE, self::TYPE_BHP, self::TYPE_IOL];

    protected $fillable = [
        'visit_surgery_package_id',
        'item_type',
        'item_id',
        // Pos kwitansi obat komposisi (OBAT_TINDAKAN/OBAT_INJEKSI/OBAT_PULANG) — dipilih
        // operator di Intraoperatif. NULL = ikut default master tarif. Lihat migrasi
        // 2026_07_28_000002. Hanya relevan untuk item MEDICATION.
        'pos_kwitansi',
        'quantity',
        'unit_price',
        'notes',
    ];

    protected $casts = [
        'quantity'   => 'integer',
        'unit_price' => 'decimal:2',
    ];

    public function visitPackage(): BelongsTo
    {
        return $this->belongsTo(VisitSurgeryPackage::class, 'visit_surgery_package_id');
    }

    /** Resolve item terkait (Procedure/BhpItem/IolItem/Medication). */
    public function resolveItem(): ?Model
    {
        // withTrashed: snapshot = rekaman saat-itu komponen yang BENAR-BENAR dipakai
        // pasien. Master yang di-soft-delete BELAKANGAN (dikeluarkan dari katalog)
        // tak boleh menghapus identitas komponen historis — dgn find() biasa nama &
        // kategori jadi "-"/"BHP" (orphan semu) padahal datanya masih ada. Keempat
        // model memakai SoftDeletes.
        return match ($this->item_type) {
            self::TYPE_PROCEDURE  => Procedure::withTrashed()->find($this->item_id),
            self::TYPE_BHP        => BhpItem::withTrashed()->find($this->item_id),
            self::TYPE_IOL        => IolItem::withTrashed()->find($this->item_id),
            self::TYPE_MEDICATION => Medication::withTrashed()->find($this->item_id),
            default               => null,
        };
    }

    public function subtotal(): float
    {
        return (float) $this->quantity * (float) $this->unit_price;
    }
}
