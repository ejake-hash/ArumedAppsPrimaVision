<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Balance cairan (intake/output) rawat inap. Lihat migrasi 2026_07_29_000002.
 */
class FluidBalanceRecord extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'fluid_balance_records';
    protected $keyType = 'string';
    public $incrementing = false;

    public const DIR_INTAKE = 'INTAKE';
    public const DIR_OUTPUT = 'OUTPUT';

    protected $fillable = [
        'visit_id',
        'recorded_at',
        'direction',
        'category',
        'volume_ml',
        'recorded_by_id',
        'notes',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'volume_ml'   => 'integer',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recorded_by_id');
    }
}
