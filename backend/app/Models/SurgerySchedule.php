<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SurgerySchedule extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    /** Lokasi pelaksanaan jadwal (lihat migrasi add_location_type_to_surgery_schedules). */
    public const LOCATION_RUANG_BEDAH    = 'RUANG_BEDAH';    // operasi (alur Bedah)
    public const LOCATION_RUANG_TINDAKAN = 'RUANG_TINDAKAN'; // tindakan laser (YAG/PRP)

    protected $fillable = [
        'surgery_package_id',
        'location_type',
        'lead_surgeon_id',
        'anesthesiologist_id',
        'scheduled_date',
        'scheduled_time',
        'operation_room',
        'status',
        'requires_inpatient',
        'notes',
    ];

    protected $casts = [
        'scheduled_date'     => 'date',
        'requires_inpatient' => 'boolean',
    ];

    public function surgeryPackage(): BelongsTo
    {
        return $this->belongsTo(SurgeryPackage::class);
    }

    public function leadSurgeon(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'lead_surgeon_id');
    }

    public function anesthesiologist(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'anesthesiologist_id');
    }

    public function surgeryRecord(): HasOne
    {
        return $this->hasOne(SurgeryRecord::class);
    }

    /**
     * Kunjungan yang terhubung ke jadwal ini (lewat visits.surgery_schedule_id).
     * Sumber data pasien/diagnosa untuk Bedah · Pasien Terjadwal.
     */
    public function visit(): HasOne
    {
        return $this->hasOne(Visit::class);
    }

    public function surgeryRequests(): HasMany
    {
        return $this->hasMany(SurgeryRequest::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(SurgeryScheduleAuditLog::class)->orderByDesc('changed_at');
    }
}
