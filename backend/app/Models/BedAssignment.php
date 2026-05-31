<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BedAssignment extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    // Alasan penempatan / pindah.
    public const REASON_ADMISSION = 'ADMISSION';
    public const REASON_TRANSFER  = 'TRANSFER';       // pindah bed/room sekelas (tarif tetap)
    public const REASON_TITIP     = 'TITIP_KELAS';    // room hak penuh → hak TETAP (tarif tetap)
    public const REASON_UPGRADE   = 'UPGRADE_KELAS';  // permintaan pasien → hak BERUBAH (tarif ikut)
    public const REASON_DOWNGRADE = 'DOWNGRADE_KELAS';

    protected $fillable = [
        'visit_id',
        'bed_id',
        'room_id',
        'kelas_rawat_hak',
        'kelas_rawat_room',
        'assigned_at',
        'released_at',
        'assigned_by_id',
        'reason',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_by_id');
    }
}
