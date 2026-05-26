<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurgeryRequest extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'surgery_schedule_id',
        'requested_by_id',
        'status',
        'notes',
        'sent_at',
        'received_at',
    ];

    protected $casts = [
        'sent_at'     => 'datetime',
        'received_at' => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function surgerySchedule(): BelongsTo
    {
        return $this->belongsTo(SurgerySchedule::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by_id');
    }

    public function bhpItems(): HasMany
    {
        return $this->hasMany(SurgeryRequestBhp::class);
    }

    public function iolItems(): HasMany
    {
        return $this->hasMany(SurgeryRequestIol::class);
    }
}
