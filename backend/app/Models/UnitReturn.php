<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UnitReturn extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'unit_returns';
    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_DRAFT     = 'DRAFT';
    public const STATUS_SUBMITTED = 'SUBMITTED';
    public const STATUS_RECEIVED  = 'RECEIVED';
    public const STATUS_REJECTED  = 'REJECTED';

    protected $fillable = [
        'return_number',
        'unit_request_id',
        'returning_station',
        'return_date',
        'status',
        'reason',
        'notes',
        'returned_by',
        'received_by',
        'received_at',
    ];

    protected $casts = [
        'return_date' => 'date',
        'received_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(UnitReturnItem::class);
    }

    public function unitRequest()
    {
        return $this->belongsTo(UnitRequest::class);
    }
}
