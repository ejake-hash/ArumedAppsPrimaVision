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
        'poli_code',
        'service_type',
        'week_start',
        'is_active',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'day_of_week' => 'integer',
        'week_start'  => 'date',
    ];

    // 1 = Senin, 2 = Selasa, ..., 7 = Minggu
    public const DAY_LABELS = [
        1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis',
        5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu',
    ];

    // Jenis layanan jadwal praktik.
    public const SERVICE_BPJS      = 'BPJS';
    public const SERVICE_EKSEKUTIF = 'EKSEKUTIF';
    public const SERVICE_TYPES     = [self::SERVICE_BPJS, self::SERVICE_EKSEKUTIF];

    /**
     * Tanggal Senin dari minggu berjalan (timezone Asia/Jakarta).
     * Dipakai sebagai sumber kebenaran "minggu ini" — transisi Minggu→Senin
     * terjadi otomatis tanpa cron karena startOfWeek dihitung dari now().
     */
    public static function currentWeekStart(): string
    {
        return now('Asia/Jakarta')->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString();
    }

    /**
     * Tanggal Senin dari minggu yang memuat tanggal $date (default: hari ini).
     */
    public static function weekStartFor(?string $date = null): string
    {
        $c = $date
            ? \Carbon\Carbon::parse($date, 'Asia/Jakarta')
            : now('Asia/Jakarta');

        return $c->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function visits(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Visit::class);
    }

    /**
     * Prefix antrian dokter = {poli_code}{room}.
     *   poli_code="GLA", room="1" → "GLA1"
     * Fallback (baris lama tanpa poli_code): "D{room}", lalu "D".
     * Counter antrian dihitung per prefix per hari di QueueService, sehingga
     * poli yang berbeda (GLA1 vs EKS1) otomatis punya antrean terpisah.
     */
    public function queuePrefix(): string
    {
        $code = $this->poli_code ?: 'D';
        return $this->room ? $code . $this->room : $code;
    }

    /**
     * Scope: jadwal aktif hari ini = minggu berjalan + hari ini (ISO 1-7).
     * week_start mengikat jadwal ke minggu tertentu; transisi minggu otomatis.
     */
    public function scopeAktifHariIni(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        $dow = now('Asia/Jakarta')->isoWeekday(); // 1=Senin ... 7=Minggu
        return $q->where('week_start', self::currentWeekStart())
            ->where('day_of_week', $dow)
            ->where('is_active', true);
    }

    // Scope: jadwal milik minggu tertentu (week_start = Senin minggu itu).
    public function scopeForWeek(\Illuminate\Database\Eloquent\Builder $q, string $weekStart): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where('week_start', $weekStart);
    }
}
