<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Laporan Anestesi terstruktur (RM 5.2) — 1 per surgery_record.
 * Field hal 1-2 di `form_data` (JSON); grafik vital durante di
 * surgery_anesthesia_vitals (terpisah).
 */
class SurgeryAnesthesiaReport extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'surgery_anesthesia_reports';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'surgery_record_id',
        'visit_id',
        'asa_class',
        'teknik_anestesi',
        'form_data',
        'recorded_by_id',
        'finalized_at',
    ];

    protected $casts = [
        'teknik_anestesi' => 'array',
        'form_data'       => 'array',
        'finalized_at'    => 'datetime',
    ];

    public function surgeryRecord(): BelongsTo
    {
        return $this->belongsTo(SurgeryRecord::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recorded_by_id');
    }
}
