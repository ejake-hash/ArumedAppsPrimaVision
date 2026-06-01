<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Queue extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    // Station enum (sesuai Section 11.1 Architecture)
    public const STATION_ADMISI       = 'ADMISI';
    public const STATION_TRIASE       = 'TRIASE';
    public const STATION_REFRAKSIONIS = 'REFRAKSIONIS';
    public const STATION_DOKTER       = 'DOKTER';
    public const STATION_PENUNJANG    = 'PENUNJANG';
    public const STATION_BEDAH        = 'BEDAH';
    public const STATION_KASIR        = 'KASIR';
    public const STATION_FARMASI      = 'FARMASI';
    // Rawat Inap: station long-lived (1 baris bertahan berhari-hari = kartu pasien
    // di papan room). BUKAN antrean panggil → di-EXCLUDE dari Antrean TV (lihat STATIONS).
    public const STATION_RANAP        = 'RANAP';
    // IGD: station long-lived (papan triase berlevel by priority, kartu pasien bertahan
    // lewat tengah malam). Pola sama RANAP → di-EXCLUDE dari Antrean TV (bukan antrean panggil).
    public const STATION_IGD          = 'IGD';

    // Station yang muncul di Antrean TV / alur antrean panggil (FIFO harian).
    // RANAP sengaja TIDAK di sini (bukan antrean panggil).
    public const STATIONS = [
        self::STATION_ADMISI,
        self::STATION_TRIASE,
        self::STATION_REFRAKSIONIS,
        self::STATION_DOKTER,
        self::STATION_PENUNJANG,
        self::STATION_BEDAH,
        self::STATION_KASIR,
        self::STATION_FARMASI,
    ];

    // Semua station termasuk yang long-lived (RANAP, IGD). Dipakai untuk validasi
    // station yang sah, TANPA memasukkan RANAP/IGD ke papan TV.
    public const ALL_STATIONS = [
        self::STATION_ADMISI,
        self::STATION_TRIASE,
        self::STATION_REFRAKSIONIS,
        self::STATION_DOKTER,
        self::STATION_PENUNJANG,
        self::STATION_BEDAH,
        self::STATION_KASIR,
        self::STATION_FARMASI,
        self::STATION_RANAP,
        self::STATION_IGD,
    ];

    // Prefix mapping (queue_number = prefix + sequence).
    // TRIASE & REFRAKSIONIS share prefix "TR" — pasien yg sama dilayani paralel
    // di kedua stasiun dengan satu nomor antrean (mis. TR-007).
    public const PREFIX_MAP = [
        self::STATION_ADMISI       => 'A',
        self::STATION_TRIASE       => 'TR',
        self::STATION_REFRAKSIONIS => 'TR',
        self::STATION_DOKTER       => 'D',
        self::STATION_PENUNJANG    => 'P',
        self::STATION_BEDAH        => 'B',
        self::STATION_KASIR        => 'K',
        self::STATION_FARMASI      => 'F',
        self::STATION_RANAP        => 'RI',
        self::STATION_IGD          => 'IGD',
    ];

    // Stasiun yang berbagi prefix & sequence (paralel).
    public const SHARED_PREFIX_GROUPS = [
        'TR' => [self::STATION_TRIASE, self::STATION_REFRAKSIONIS],
    ];

    // Status enum
    public const STATUS_WAITING     = 'WAITING';
    public const STATUS_CALLED      = 'CALLED';
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';
    public const STATUS_COMPLETED   = 'COMPLETED';
    public const STATUS_CANCELLED   = 'CANCELLED';

    // Sub-status khusus baris DOKTER saat alur penunjang (pasien tetap milik dokter):
    //   DI_PENUNJANG      = pasien sedang di stasiun penunjang (baris turun ke bawah)
    //   SELESAI_PENUNJANG = semua hasil selesai, siap dilanjut dokter (baris naik ke atas)
    public const STATUS_AT_PENUNJANG    = 'DI_PENUNJANG';
    public const STATUS_PENUNJANG_DONE  = 'SELESAI_PENUNJANG';

    public const ACTIVE_STATUSES = [
        self::STATUS_WAITING,
        self::STATUS_CALLED,
        self::STATUS_IN_PROGRESS,
    ];

    protected $fillable = [
        'visit_id',
        'station',
        'queue_prefix',
        'queue_sequence',
        'queue_number',
        'status',
        'called_at',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'called_at'    => 'datetime',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    // ---------------------------------------------------------------------
    // SCOPES
    // ---------------------------------------------------------------------

    public function scopeToday(Builder $q): Builder
    {
        return $q->whereDate('created_at', today());
    }

    public function scopeByStation(Builder $q, string $station): Builder
    {
        return $q->where('station', $station);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function scopeWaiting(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_WAITING);
    }

    // ---------------------------------------------------------------------
    // HELPERS
    // ---------------------------------------------------------------------

    public static function prefixFor(string $station): string
    {
        return self::PREFIX_MAP[$station] ?? 'X';
    }
}
