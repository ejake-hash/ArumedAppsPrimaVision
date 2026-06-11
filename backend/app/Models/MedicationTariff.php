<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MedicationTariff extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType   = 'string';
    public $incrementing = false;

    protected $fillable = ['medication_id', 'insurer_id', 'price', 'is_active', 'pos_kwitansi'];

    protected $casts = ['price' => 'decimal:2', 'is_active' => 'boolean'];

    /**
     * Pos kwitansi obat — pemisah baris OBAT di kwitansi (1 obat = 1 pos tetap,
     * disimpan di tarif, kasir baca dari baris UMUM). Enum disimpan UPPERCASE;
     * label = string `billing_items.category` yang dibaca grouping kwitansi
     * (lihat KasirView::groupItemsByCategory + seed billing_categories).
     */
    public const POS_OBAT_PULANG   = 'OBAT_PULANG';
    public const POS_OBAT_TINDAKAN = 'OBAT_TINDAKAN';
    public const POS_OBAT_INJEKSI  = 'OBAT_INJEKSI';

    /** enum → label/category kwitansi. */
    public const POS_LABELS = [
        self::POS_OBAT_PULANG   => 'Obat Pulang',
        self::POS_OBAT_TINDAKAN => 'Obat Tindakan',
        self::POS_OBAT_INJEKSI  => 'Obat Injeksi',
    ];

    /** nilai valid untuk validasi (Rule::in). */
    public const POS_VALUES = [
        self::POS_OBAT_PULANG,
        self::POS_OBAT_TINDAKAN,
        self::POS_OBAT_INJEKSI,
    ];

    /** Label kwitansi dari enum pos; fallback "Obat Pulang" (data lama/kosong). */
    public static function posLabel(?string $pos): string
    {
        return self::POS_LABELS[$pos] ?? self::POS_LABELS[self::POS_OBAT_PULANG];
    }

    /** Prefix kode item obat per pos — selaras Buku Tarif (sheet Kategori: OBT/OBP/OBI). */
    public const POS_CODE_PREFIX = [
        self::POS_OBAT_TINDAKAN => 'OBT',
        self::POS_OBAT_PULANG   => 'OBP',
        self::POS_OBAT_INJEKSI  => 'OBI',
    ];

    public static function posCodePrefix(?string $pos): string
    {
        return self::POS_CODE_PREFIX[$pos] ?? self::POS_CODE_PREFIX[self::POS_OBAT_PULANG];
    }

    public function medication(): BelongsTo { return $this->belongsTo(Medication::class); }
    public function insurer(): BelongsTo    { return $this->belongsTo(Insurer::class); }
}
