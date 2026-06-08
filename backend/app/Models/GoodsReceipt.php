<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GoodsReceipt extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'grn_number',
        'po_id',
        'supplier_id',
        'receipt_date',
        'invoice_number',
        'payment_method',
        'payment_term_days',
        'due_date',
        'notes',
        'total_amount',
        'discount_amount',
        'ppn_percent',
        'ppn_amount',
        'grand_total',
        'received_by',
    ];

    protected $casts = [
        'receipt_date'      => 'date',
        'due_date'          => 'date',
        'payment_term_days' => 'integer',
        'total_amount'      => 'decimal:2',
        'discount_amount'   => 'decimal:2',
        'ppn_percent'       => 'decimal:2',
        'ppn_amount'        => 'decimal:2',
        'grand_total'       => 'decimal:2',
    ];

    public const PAYMENT_TUNAI  = 'TUNAI';
    public const PAYMENT_KREDIT = 'KREDIT';
    public const PAYMENT_METHODS = [self::PAYMENT_TUNAI, self::PAYMENT_KREDIT];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items()
    {
        return $this->hasMany(GoodsReceiptItem::class, 'grn_id');
    }
}
