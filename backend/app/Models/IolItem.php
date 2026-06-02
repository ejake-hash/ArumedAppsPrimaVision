<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
// InventoryStock dipakai oleh onHandStock() — sumber stok tunggal IOL.

class IolItem extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    public const IOL_TYPES = ['MONOFOCAL', 'MULTIFOCAL', 'TORIC', 'TRIFOCAL', 'EDOF', 'PHAKIC'];
    public const MATERIALS = ['Acrylic', 'Silicone', 'PMMA'];

    protected $fillable = [
        'brand',
        'manufacturer',
        'model',
        'iol_type',
        'material',
        'power',
        'cylinder',
        'axis',
        'lot_number',
        'serial_number',
        'gs1_barcode',
        'gtin',
        'expiry_date',
        'stock',
        'is_used',
        'price',
        'is_active',
    ];

    protected $casts = [
        'power'       => 'decimal:2',
        'cylinder'    => 'decimal:2',
        'axis'        => 'integer',
        'price'       => 'decimal:2',
        'expiry_date' => 'date',
        'is_used'     => 'boolean',
        'is_active'   => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Total stok on-hand IOL ini dari `inventory_stocks` (sumber stok tunggal
     * pasca-redesign per-tipe). Default jumlahkan SEMUA lokasi (gudang+unit) agar
     * "tersedia untuk dipakai" tidak bias ke satu depo. Kolom legacy `stock`
     * TIDAK lagi otoritatif.
     */
    public function onHandStock(?string $location = null): float
    {
        $q = InventoryStock::where('item_type', InventoryStock::TYPE_IOL)
            ->where('item_id', $this->id);
        if ($location !== null) {
            $q->where('location', $location);
        }

        return (float) $q->sum('qty_on_hand');
    }

    public function iolTariffs(): HasMany
    {
        return $this->hasMany(IolTariff::class);
    }

    public function surgeryRequestIols(): HasMany
    {
        return $this->hasMany(SurgeryRequestIol::class);
    }

    public function surgeryIolUsage(): HasOne
    {
        return $this->hasOne(SurgeryIolUsage::class);
    }
}
