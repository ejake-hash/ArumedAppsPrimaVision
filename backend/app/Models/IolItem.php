<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
