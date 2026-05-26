<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GoodsReceiptItem extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const TYPE_MEDICATION = 'MEDICATION';
    public const TYPE_BHP        = 'BHP';
    public const TYPE_IOL        = 'IOL';

    protected $fillable = [
        'grn_id',
        'po_item_id',
        'item_type',
        'item_id',
        'qty_received',
        'batch_no',
        'expiry_date',
        'unit_price',
        'subtotal',
        'notes',
    ];

    protected $casts = [
        'qty_received' => 'decimal:2',
        'expiry_date'  => 'date',
        'unit_price'   => 'decimal:2',
        'subtotal'     => 'decimal:2',
    ];

    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class, 'grn_id');
    }

    public function poItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'po_item_id');
    }
}
