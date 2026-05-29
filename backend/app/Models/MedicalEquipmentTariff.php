<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MedicalEquipmentTariff extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'medical_equipment_id', 'insurer_id', 'classification', 'price', 'is_active',
    ];

    protected $casts = [
        'price'     => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(MedicalEquipment::class, 'medical_equipment_id');
    }

    public function insurer(): BelongsTo
    {
        return $this->belongsTo(Insurer::class);
    }
}
