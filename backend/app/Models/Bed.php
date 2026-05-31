<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Bed extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    // Status bed.
    public const STATUS_AVAILABLE   = 'AVAILABLE';
    public const STATUS_OCCUPIED    = 'OCCUPIED';
    public const STATUS_CLEANING    = 'CLEANING';
    public const STATUS_MAINTENANCE = 'MAINTENANCE';
    public const STATUS_RESERVED    = 'RESERVED';

    protected $fillable = [
        'room_id',
        'code',
        'label',
        'status',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function bedAssignments(): HasMany
    {
        return $this->hasMany(BedAssignment::class);
    }

    /** Penempatan aktif saat ini (released_at null). */
    public function activeAssignment(): HasOne
    {
        return $this->hasOne(BedAssignment::class)->whereNull('released_at');
    }
}
