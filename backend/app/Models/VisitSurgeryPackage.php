<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Snapshot paket pasien (header). Lihat migrasi create_visit_surgery_packages.
 * Lapisan metadata harga untuk diskon paket di kwitansi — bukan sumber tagih.
 */
class VisitSurgeryPackage extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType   = 'string';
    public $incrementing = false;

    public const TYPE_BEDAH = 'BEDAH';
    public const TYPE_PEMERIKSAAN = 'PEMERIKSAAN';

    protected $fillable = [
        'visit_id',
        'surgery_schedule_id',
        'source_surgery_package_id',
        'surgery_package_tariff_id',
        'package_type',
        'package_name',
        'package_code',
        'sell_price',
        'total_base_price',
        'label',
        'is_active',
    ];

    protected $casts = [
        'sell_price'       => 'decimal:2',
        'total_base_price' => 'decimal:2',
        'is_active'        => 'boolean',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(VisitSurgeryPackageItem::class);
    }

    public function sourcePackage(): BelongsTo
    {
        return $this->belongsTo(SurgeryPackage::class, 'source_surgery_package_id');
    }

    public function surgerySchedule(): BelongsTo
    {
        return $this->belongsTo(SurgerySchedule::class);
    }

    /** Hitung ulang total_base_price dari items snapshot dan simpan. */
    public function recalcTotalBasePrice(): float
    {
        $total = (float) $this->items()
            ->selectRaw('COALESCE(SUM(quantity * unit_price), 0) as total')
            ->value('total');

        $this->update(['total_base_price' => $total]);
        return $total;
    }

    /** Diskon paket (tampilan UI). Angka billing dihitung LIVE di KasirService. */
    public function discountAmount(): float
    {
        return max(0, (float) $this->total_base_price - (float) $this->sell_price);
    }

    /** Redaksi baris diskon di kwitansi — label custom atau fallback nama paket. */
    public function effectiveLabel(): string
    {
        return $this->label ?: ($this->package_name ?: 'Diskon Paket');
    }
}
