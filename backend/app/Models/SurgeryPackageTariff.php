<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SurgeryPackageTariff extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType   = 'string';
    public $incrementing = false;

    protected $fillable = [
        'legacy_uuid',
        'surgery_package_id',
        'insurer_id',
        'display_name',      // nama tampil khusus per-penjamin (mis. promo UMUM); null = pakai nama paket master
        'sell_price',
        'discount_percent',  // metadata: bila diisi, sell_price = base × (1 − pct/100). Billing tetap baca sell_price.
        // Manfaat "kontrol gratis pasca-bedah" (Opsi B) — kini per VARIAN TARIF (per
        // penjamin), bukan per master paket. NULL procedure = varian tak beri manfaat.
        'followup_procedure_id',
        'followup_count',
        'followup_valid_days',
        'is_active',
    ];

    protected $casts = [
        'sell_price'          => 'decimal:2',
        'discount_percent'    => 'decimal:2',
        'followup_count'      => 'integer',
        'followup_valid_days' => 'integer',
        'is_active'           => 'boolean',
    ];

    /** Varian tarif ini memberi manfaat "konsultasi kontrol gratis pasca-bedah"? */
    public function grantsFollowup(): bool
    {
        return $this->followup_procedure_id !== null && (int) $this->followup_count > 0;
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(SurgeryPackage::class, 'surgery_package_id');
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }

    /**
     * Item OVERRIDE varian (scope: IOL) — mengganti item komposisi ber-tipe sama
     * saat snapshot visit. Kosong = varian murni harga/label, komposisi utuh.
     */
    public function overrideItems(): HasMany
    {
        return $this->hasMany(SurgeryPackageTariffItem::class, 'surgery_package_tariff_id');
    }

    /**
     * Hitung diskon = (total_base_price paket) - sell_price.
     * Mengembalikan nilai positif jika ada diskon, 0 jika sama/lebih mahal.
     */
    public function discountAmount(): float
    {
        $base = (float) ($this->package?->total_base_price ?? 0);
        $sell = (float) $this->sell_price;
        return max(0, $base - $sell);
    }

    public function discountPercent(): float
    {
        $base = (float) ($this->package?->total_base_price ?? 0);
        if ($base <= 0) {
            return 0;
        }
        return round($this->discountAmount() / $base * 100, 2);
    }
}
