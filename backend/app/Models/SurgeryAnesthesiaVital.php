<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu pembacaan tanda vital anestesi pada satu titik waktu (durante operasi).
 * Child dari surgery_records.
 */
class SurgeryAnesthesiaVital extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'surgery_anesthesia_vitals';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'surgery_record_id',
        'recorded_at',
        'td_sistol',
        'td_diastol',
        'nadi',
        'spo2',
        'rr',
        'etco2',
        'suhu',
        'obat_kejadian',
        'recorded_by_id',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'spo2'        => 'decimal:2',
        'suhu'        => 'decimal:1',
    ];

    public function surgeryRecord(): BelongsTo
    {
        return $this->belongsTo(SurgeryRecord::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recorded_by_id');
    }
}
