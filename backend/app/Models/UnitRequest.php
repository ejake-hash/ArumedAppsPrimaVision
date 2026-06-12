<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UnitRequest extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_DRAFT     = 'DRAFT';
    public const STATUS_SUBMITTED = 'SUBMITTED';
    public const STATUS_APPROVED  = 'APPROVED';
    public const STATUS_DELIVERED = 'DELIVERED';
    public const STATUS_CLOSED    = 'CLOSED';
    public const STATUS_REJECTED  = 'REJECTED';

    public const STATIONS = [
        'ADMISI', 'TRIASE', 'REFRAKSIONIS', 'DOKTER',
        'PENUNJANG', 'BEDAH', 'KASIR', 'FARMASI',
        'RANAP', 'IGD',
    ];

    protected $fillable = [
        'request_number',
        'requesting_station',
        'request_date',
        'status',
        'notes',
        'requested_by',
        'approved_by',
        'approved_at',
        'delivered_by',
        'delivered_at',
    ];

    protected $casts = [
        'request_date' => 'date',
        'approved_at'  => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(UnitRequestItem::class);
    }

    public function returns()
    {
        return $this->hasMany(UnitReturn::class);
    }
}
