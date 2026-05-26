<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const TYPE_MEDICATION = 'MEDICATION';
    public const TYPE_BHP        = 'BHP';
    public const TYPE_IOL        = 'IOL';

    protected $fillable = [
        'po_id',
        'item_type',
        'item_id',
        'qty_ordered',
        'qty_received',
        'unit_price',
        'subtotal',
        'notes',
    ];

    protected $casts = [
        'qty_ordered'  => 'decimal:2',
        'qty_received' => 'decimal:2',
        'unit_price'   => 'decimal:2',
        'subtotal'     => 'decimal:2',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function receiptItems()
    {
        return $this->hasMany(GoodsReceiptItem::class, 'po_item_id');
    }
}
