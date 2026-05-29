<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurgeryScheduleAuditLog extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'surgery_schedule_id',
        'old_date',
        'new_date',
        'reason',
        'changed_by_id',
        'changed_at',
    ];

    protected $casts = [
        'old_date'   => 'date',
        'new_date'   => 'date',
        'changed_at' => 'datetime',
    ];

    public function surgerySchedule(): BelongsTo
    {
        return $this->belongsTo(SurgerySchedule::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'changed_by_id');
    }
}
