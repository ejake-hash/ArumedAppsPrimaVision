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

    public const STATUS_PENDING   = 'PENDING';   // menunggu pembayaran di Kasir (channel KASIR)
    public const STATUS_PAID      = 'PAID';
    public const STATUS_CANCELLED = 'CANCELLED';

    // Jalur penjualan: FARMASI = bayar langsung di apotek; KASIR = dibayar di kasir.
    public const CHANNEL_FARMASI = 'FARMASI';
    public const CHANNEL_KASIR   = 'KASIR';

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
        'channel',
        'sold_by_id',
        'settled_by_id',
        'settled_at',
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
        'settled_at'       => 'datetime',
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

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'settled_by_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'cancelled_by_id');
    }
}
