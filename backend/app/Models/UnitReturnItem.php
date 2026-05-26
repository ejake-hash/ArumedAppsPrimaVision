<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UnitReturnItem extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const TYPE_MEDICATION = 'MEDICATION';
    public const TYPE_BHP        = 'BHP';
    public const TYPE_IOL        = 'IOL';

    public const CONDITION_GOOD        = 'GOOD';
    public const CONDITION_DAMAGED     = 'DAMAGED';
    public const CONDITION_EXPIRED     = 'EXPIRED';
    public const CONDITION_NEAR_EXPIRY = 'NEAR_EXPIRY';

    protected $fillable = [
        'unit_return_id',
        'item_type',
        'item_id',
        'qty_returned',
        'batch_no',
        'expiry_date',
        'condition',
        'notes',
    ];

    protected $casts = [
        'qty_returned' => 'decimal:2',
        'expiry_date'  => 'date',
    ];

    public function unitReturn()
    {
        return $this->belongsTo(UnitReturn::class);
    }
}
