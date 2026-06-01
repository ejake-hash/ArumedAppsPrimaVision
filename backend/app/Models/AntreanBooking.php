<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Reservasi antrean dari Mobile JKN. Belum jadi Visit sampai pasien check-in.
 */
class AntreanBooking extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType   = 'string';
    public $incrementing = false;

    public const STATUS_DIBOOK  = 'DIBOOK';
    public const STATUS_CHECKIN = 'CHECKIN';
    public const STATUS_BATAL   = 'BATAL';
    public const STATUS_SELESAI = 'SELESAI';

    protected $fillable = [
        'kodebooking',
        'nik',
        'nomorkartu',
        'nohp',
        'norm',
        'patient_id',
        'poli_code',
        'doctor_schedule_id',
        'tanggal_periksa',
        'jam_praktek',
        'jenis_kunjungan',
        'nomor_referensi',
        'nomor_antrean',
        'angka_antrean',
        'status',
        'checkin_at',
        'keterangan_batal',
        'visit_id',
    ];

    protected $casts = [
        'tanggal_periksa' => 'date',
        'jenis_kunjungan' => 'integer',
        'angka_antrean'   => 'integer',
        'checkin_at'      => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctorSchedule(): BelongsTo
    {
        return $this->belongsTo(DoctorSchedule::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** Booking masih aktif (belum batal/selesai). */
    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_DIBOOK, self::STATUS_CHECKIN], true);
    }
}
