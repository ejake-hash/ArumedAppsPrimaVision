<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StockOpnameItem extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_LEBIH  = 'LEBIH';
    public const STATUS_KURANG = 'KURANG';

    protected $fillable = [
        'stock_opname_session_id',
        'item_type',
        'item_id',
        'item_code',
        'item_name',
        'system_qty',
        'physical_qty',
        'delta',
        'status',
        'note',
    ];

    protected $casts = [
        'system_qty'   => 'decimal:2',
        'physical_qty' => 'decimal:2',
        'delta'        => 'decimal:2',
    ];

    public function session()
    {
        return $this->belongsTo(StockOpnameSession::class, 'stock_opname_session_id');
    }
}
