<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorSchedule extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employee_id',
        'day_of_week',
        'start_time',
        'end_time',
        'room',
        'poliklinik',
        'is_active',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'day_of_week' => 'integer',
    ];

    // 1 = Senin, 2 = Selasa, ..., 7 = Minggu
    public const DAY_LABELS = [
        1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis',
        5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function visits(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Visit::class);
    }

    // Prefix antrian dokter berdasarkan nomor ruangan: room="1" → "D1"
    public function queuePrefix(): string
    {
        return $this->room ? 'D' . $this->room : 'D';
    }

    // Scope: jadwal aktif hari ini (hari_of_week sesuai Carbon::isoDayOfWeek 1-7)
    public function scopeAktifHariIni(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        $dow = now()->isoWeekday(); // 1=Senin ... 7=Minggu
        return $q->where('day_of_week', $dow)->where('is_active', true);
    }
}
