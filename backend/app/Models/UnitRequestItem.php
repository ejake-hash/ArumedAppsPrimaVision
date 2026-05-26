<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UnitRequestItem extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const TYPE_MEDICATION = 'MEDICATION';
    public const TYPE_BHP        = 'BHP';
    public const TYPE_IOL        = 'IOL';

    protected $fillable = [
        'unit_request_id',
        'item_type',
        'item_id',
        'qty_requested',
        'qty_delivered',
        'batch_no',
        'expiry_date',
        'notes',
    ];

    protected $casts = [
        'qty_requested' => 'decimal:2',
        'qty_delivered' => 'decimal:2',
        'expiry_date'   => 'date',
    ];

    public function unitRequest()
    {
        return $this->belongsTo(UnitRequest::class);
    }
}
