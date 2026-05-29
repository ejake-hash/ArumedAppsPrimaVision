<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MedicalEquipmentUsage extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'medical_equipment_id', 'visit_id', 'surgery_schedule_id',
        'used_by_id', 'used_at', 'notes',
    ];

    protected $casts = [
        'used_at' => 'datetime',
    ];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(MedicalEquipment::class, 'medical_equipment_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function surgerySchedule(): BelongsTo
    {
        return $this->belongsTo(SurgerySchedule::class);
    }

    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'used_by_id');
    }
}
