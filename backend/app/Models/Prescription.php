<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Prescription extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'legacy_uuid',
        'visit_id',
        'prescribed_by_id',
        'status',
        'dispensed_by_id',
        'dispensed_at',
        'notes',
        'pharmacy_note',
    ];

    protected $casts = [
        'dispensed_at' => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function prescribedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'prescribed_by_id');
    }

    public function dispensedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'dispensed_by_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class);
    }
}
