<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BhpItem extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    public const CATEGORY_MEDICAL_BHP    = 'MEDICAL_BHP';
    public const CATEGORY_CSSD           = 'CSSD';
    public const CATEGORY_INSTRUMENT_SET = 'INSTRUMENT_SET';

    // Kategori "MEDICAL_SUPPLIES" dihapus 2026-06-03 — item digabung ke MEDICAL_BHP
    // (migrasi 2026_06_18_000008). Jangan tambahkan kembali.
    public const CATEGORIES = [
        self::CATEGORY_MEDICAL_BHP,
        self::CATEGORY_CSSD,
        self::CATEGORY_INSTRUMENT_SET,
    ];

    /**
     * Kategori internal → label kategori tagihan (billing_categories) untuk grouping
     * kwitansi & tampilan komposisi paket. Selaras MasterDataService::bukuTarifUnion
     * (Buku Tarif) dan seed migrasi 2026_07_14. Tanpa kategori → 'BHP' (tetap tergrup).
     */
    public static function billingCategoryLabel(?string $category): string
    {
        return match ($category) {
            self::CATEGORY_MEDICAL_BHP    => 'BAHAN HABIS PAKAI',
            self::CATEGORY_CSSD           => 'CSSD',
            self::CATEGORY_INSTRUMENT_SET => 'INSTRUMENT',
            default                       => 'BHP',
        };
    }

    protected $fillable = [
        'legacy_uuid',
        'name',
        'code',
        'category',
        'unit',
        'manufacturer',
        'stock',
        'min_stock',
        'price',
        'expiry_date',
        'batch_number',
        'description',
        'is_active',
    ];

    protected $casts = [
        'price'       => 'decimal:2',
        'expiry_date' => 'date',
        'is_active'   => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function bhpTariffs(): HasMany
    {
        return $this->hasMany(BhpTariff::class);
    }

    public function surgeryRequestBhps(): HasMany
    {
        return $this->hasMany(SurgeryRequestBhp::class);
    }
}
