<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurgeryRecord extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'surgery_schedule_id',
        'visit_id',
        'time_in',
        'time_out',
        'operation_notes',
        'has_complication',
        'complication_detail',
        'post_op_instructions',
        'followup_date',
        'post_op_disposition',
        'finalized_at',
    ];

    protected $casts = [
        'time_in'          => 'datetime',
        'time_out'         => 'datetime',
        'has_complication' => 'boolean',
        'followup_date'    => 'date',
        'finalized_at'     => 'datetime',
    ];

    public function surgerySchedule(): BelongsTo
    {
        return $this->belongsTo(SurgerySchedule::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function iolUsages(): HasMany
    {
        return $this->hasMany(SurgeryIolUsage::class);
    }
}
