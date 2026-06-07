<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'is_active',
    ];

    protected $casts = [
        'sell_price'       => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'is_active'        => 'boolean',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(SurgeryPackage::class, 'surgery_package_id');
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
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
