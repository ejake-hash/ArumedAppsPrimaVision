<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item OVERRIDE per varian tarif paket bedah (scope saat ini: IOL saja).
 * Varian ber-override mengganti item komposisi paket ber-tipe sama saat snapshot —
 * lihat DokterService::syncVisitPackageSnapshot.
 */
class SurgeryPackageTariffItem extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'surgery_package_tariff_id',
        'item_type',
        'item_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(SurgeryPackageTariff::class, 'surgery_package_tariff_id');
    }
}
