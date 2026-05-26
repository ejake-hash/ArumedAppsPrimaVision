<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InventoryStock extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const TYPE_MEDICATION = 'MEDICATION';
    public const TYPE_BHP        = 'BHP';
    public const TYPE_IOL        = 'IOL';

    protected $fillable = [
        'item_type',
        'item_id',
        'batch_no',
        'expiry_date',
        'qty_on_hand',
        'last_received_at',
    ];

    protected $casts = [
        'expiry_date'      => 'date',
        'qty_on_hand'      => 'decimal:2',
        'last_received_at' => 'datetime',
    ];
}
