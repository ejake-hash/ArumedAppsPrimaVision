<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SPM (menit per pasien) per poli/dokter — dasar estimasi waktu antrean.
 */
class AntreanSpm extends Model
{
    use HasUuids, SoftDeletes;

    protected $table     = 'antrean_spm';
    protected $keyType   = 'string';
    public $incrementing = false;

    protected $fillable = [
        'poli_code',
        'employee_id',
        'menit_per_pasien',
        'is_active',
    ];

    protected $casts = [
        'menit_per_pasien' => 'integer',
        'is_active'        => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
