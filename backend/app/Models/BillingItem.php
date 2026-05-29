<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingItem extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'billing_invoice_id',
        'item_type',
        'category',
        'reference_id',
        'description',
        'quantity',
        'unit_price',
        'total_price',
        'discount_amount',
        'discount_percent',
        'net_price',
        'notes',
    ];

    protected $casts = [
        'unit_price'       => 'decimal:2',
        'total_price'      => 'decimal:2',
        'discount_amount'  => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'net_price'        => 'decimal:2',
    ];

    public function billingInvoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class);
    }
}
