<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockOpnameSession extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_DRAFT   = 'DRAFT';
    public const STATUS_APPLIED = 'APPLIED';

    protected $fillable = [
        'session_number',
        'location',
        'item_type',
        'opname_date',
        'status',
        'total_items',
        'total_plus',
        'total_minus',
        'notes',
        'counted_by',
        'applied_by',
        'applied_at',
    ];

    protected $casts = [
        'opname_date' => 'date',
        'applied_at'  => 'datetime',
        'total_items' => 'integer',
        'total_plus'  => 'integer',
        'total_minus' => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(StockOpnameItem::class);
    }

    public function countedBy()
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function appliedBy()
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
