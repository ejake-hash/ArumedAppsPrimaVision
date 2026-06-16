<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * eMAR — catatan pemberian obat ke pasien rawat inap (PKPO 4.3).
 * Lihat migrasi 2026_07_29_000001. Bukan tagihan (billing tetap saat dispensing).
 */
class MedicationAdministration extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'medication_administrations';
    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_GIVEN = 'GIVEN';
    public const STATUS_HELD = 'HELD';
    public const STATUS_SKIPPED = 'SKIPPED';

    protected $fillable = [
        'visit_id',
        'prescription_item_id',
        'medication_id',
        'medication_name',
        'dose',
        'route',
        'scheduled_at',
        'administered_at',
        'administered_by_id',
        'status',
        'reason',
        'notes',
    ];

    protected $casts = [
        'scheduled_at'    => 'datetime',
        'administered_at' => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }

    public function administeredBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'administered_by_id');
    }
}
