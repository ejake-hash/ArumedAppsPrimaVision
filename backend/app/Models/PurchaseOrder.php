<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_DRAFT    = 'DRAFT';
    public const STATUS_SENT     = 'SENT';
    public const STATUS_PARTIAL  = 'PARTIAL';
    public const STATUS_RECEIVED = 'RECEIVED';
    public const STATUS_CANCELED = 'CANCELED';

    protected $fillable = [
        'po_number',
        'supplier_id',
        'po_date',
        'expected_date',
        'status',
        'notes',
        'total_amount',
        'created_by',
    ];

    protected $casts = [
        'po_date'       => 'date',
        'expected_date' => 'date',
        'total_amount'  => 'decimal:2',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class, 'po_id');
    }

    public function goodsReceipts()
    {
        return $this->hasMany(GoodsReceipt::class, 'po_id');
    }
}
