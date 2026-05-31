<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catatan triase IGD. Struktur disiapkan; belum dipakai sampai modul IGD aktif.
 */
class IgdTriageRecord extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'visit_id',
        'triage_level',
        'triage_color',
        'chief_complaint',
        'td_sistol',
        'td_diastol',
        'nadi',
        'suhu',
        'respirasi',
        'spo2',
        'gcs_e',
        'gcs_v',
        'gcs_m',
        'triaged_by_id',
        'triaged_at',
        'disposition',
    ];

    protected $casts = [
        'suhu'       => 'decimal:1',
        'spo2'       => 'decimal:2',
        'triaged_at' => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function triagedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'triaged_by_id');
    }
}
