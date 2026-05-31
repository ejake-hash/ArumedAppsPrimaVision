<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PharmacySaleItem extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'pharmacy_sale_id',
        'medication_id',
        'medication_name',
        'unit_price',
        'quantity',
        'discount_amount',
        'discount_percent',
        'total_price',
        'consumed_batches',
    ];

    protected $casts = [
        'unit_price'       => 'decimal:2',
        'discount_amount'  => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'total_price'      => 'decimal:2',
        'consumed_batches' => 'array',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PharmacySale::class, 'pharmacy_sale_id');
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }
}
