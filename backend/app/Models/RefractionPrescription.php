<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefractionPrescription extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'refraction_record_id',
        'visit_id',
        // Rx OD
        'rx_od_sph',
        'rx_od_cyl',
        'rx_od_axis',
        'rx_od_add',
        // Rx OS
        'rx_os_sph',
        'rx_os_cyl',
        'rx_os_axis',
        'rx_os_add',
        'glasses_type',
        'lens_material',
        'coating',
        'notes',
    ];

    protected $casts = [
        'rx_od_sph' => 'decimal:2',
        'rx_od_cyl' => 'decimal:2',
        'rx_od_add' => 'decimal:2',
        'rx_os_sph' => 'decimal:2',
        'rx_os_cyl' => 'decimal:2',
        'rx_os_add' => 'decimal:2',
    ];

    public function refractionRecord(): BelongsTo
    {
        return $this->belongsTo(RefractionRecord::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}
