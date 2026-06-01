<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Plafon kuota antrean JKN / non-JKN per poli/dokter/tanggal.
 * Sisa kuota dihitung runtime di AntreanKuotaService (bukan kolom).
 */
class AntreanKuota extends Model
{
    use HasUuids, SoftDeletes;

    protected $table     = 'antrean_kuota';
    protected $keyType   = 'string';
    public $incrementing = false;

    protected $fillable = [
        'poli_code',
        'employee_id',
        'tanggal',
        'kuota_jkn',
        'kuota_nonjkn',
        'is_active',
    ];

    protected $casts = [
        'tanggal'      => 'date',
        'kuota_jkn'    => 'integer',
        'kuota_nonjkn' => 'integer',
        'is_active'    => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
