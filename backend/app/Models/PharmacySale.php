<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PharmacySale extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_PAID      = 'PAID';
    public const STATUS_CANCELLED = 'CANCELLED';

    public const PAYMENT_METHODS = ['CASH', 'CARD', 'TRANSFER'];

    protected $fillable = [
        'sale_number',
        'buyer_name',
        'buyer_phone',
        'subtotal',
        'discount',
        'discount_percent',
        'total',
        'payment_method',
        'paid_amount',
        'change_amount',
        'status',
        'sold_by_id',
        'cancelled_by_id',
        'cancelled_at',
        'cancel_reason',
        'notes',
    ];

    protected $casts = [
        'subtotal'         => 'decimal:2',
        'discount'         => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'total'            => 'decimal:2',
        'paid_amount'      => 'decimal:2',
        'change_amount'    => 'decimal:2',
        'cancelled_at'     => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PharmacySaleItem::class);
    }

    public function soldBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'sold_by_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'cancelled_by_id');
    }
}
