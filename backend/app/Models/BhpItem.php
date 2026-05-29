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

    public const CATEGORY_MEDICAL_BHP      = 'MEDICAL_BHP';
    public const CATEGORY_CSSD             = 'CSSD';
    public const CATEGORY_INSTRUMENT_SET   = 'INSTRUMENT_SET';
    public const CATEGORY_MEDICAL_SUPPLIES = 'MEDICAL_SUPPLIES';

    public const CATEGORIES = [
        self::CATEGORY_MEDICAL_BHP,
        self::CATEGORY_CSSD,
        self::CATEGORY_INSTRUMENT_SET,
        self::CATEGORY_MEDICAL_SUPPLIES,
    ];

    protected $fillable = [
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
