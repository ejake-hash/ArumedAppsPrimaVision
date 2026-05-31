<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InpatientCharge extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    // Jenis biaya.
    public const TYPE_ROOM      = 'ROOM';
    public const TYPE_VISITE    = 'VISITE';
    public const TYPE_TINDAKAN  = 'TINDAKAN';
    public const TYPE_OBAT      = 'OBAT';
    public const TYPE_BHP       = 'BHP';
    public const TYPE_PENUNJANG = 'PENUNJANG';
    public const TYPE_LAINNYA   = 'LAINNYA';

    protected $fillable = [
        'visit_id',
        'charge_date',
        'charge_type',
        'reference_type',
        'reference_id',
        'description',
        'quantity',
        'unit_price',
        'total_price',
        'is_billed',
        'created_by_id',
    ];

    protected $casts = [
        'charge_date' => 'date',
        'quantity'    => 'decimal:2',
        'unit_price'  => 'decimal:2',
        'total_price' => 'decimal:2',
        'is_billed'   => 'boolean',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by_id');
    }
}
