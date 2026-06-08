<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RefractionRecord extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'legacy_uuid',
        'visit_id',
        'examined_by_id',
        'examination_date',
        'perception_type',
        // Autoref OD
        'autoref_od_sph',
        'autoref_od_cyl',
        'autoref_od_axis',
        // Autoref OS
        'autoref_os_sph',
        'autoref_os_cyl',
        'autoref_os_axis',
        // Keratometri OD
        'keratometri1_od',
        'keratometri2_od',
        'keratometri_axis_od',   // axis K1
        'keratometri_axis2_od',  // axis K2
        // Keratometri OS
        'keratometri1_os',
        'keratometri2_os',
        'keratometri_axis_os',   // axis K1
        'keratometri_axis2_os',  // axis K2
        // Visus OD
        'visus_awal_od',
        'visus_akhir_od',
        'pinhole_od',
        'add_power_od',
        // Visus OS
        'visus_awal_os',
        'visus_akhir_os',
        'pinhole_os',
        'add_power_os',
        // Refraksi Subjektif OD
        'refraksi_subjektif_od_sph',
        'refraksi_subjektif_od_cyl',
        'refraksi_subjektif_od_axis',
        // Refraksi Subjektif OS
        'refraksi_subjektif_os_sph',
        'refraksi_subjektif_os_cyl',
        'refraksi_subjektif_os_axis',
        // Kacamata Lama OD
        'old_glasses_od_sph',
        'old_glasses_od_cyl',
        'old_glasses_od_axis',
        'old_glasses_add_od',
        'old_glasses_visus_od',  // visus dengan kacamata lama (presenting VA)
        // Kacamata Lama OS
        'old_glasses_os_sph',
        'old_glasses_os_cyl',
        'old_glasses_os_axis',
        'old_glasses_add_os',
        'old_glasses_visus_os',
        // IOP
        'iop_od',
        'iop_os',
        'iop_method',
        'iop_extra_readings',    // pengukuran IOP berulang [{od,os},...]
        // Shared
        'pd_distance',
        'clinical_notes',
        'raw_data',
        // SOAP refraksionis (PPA) — O autofill dari data refraksi tapi editable & tersimpan
        'soap_s',
        'soap_o',
        'soap_a',
        'soap_p',
        // Finalisasi
        'is_finalized',
        'is_skipped',
        'finalized_at',
        'finalized_by_id',
        'digital_signature',
        'signature_timestamp',
    ];

    protected $casts = [
        'examination_date'            => 'datetime',
        // Autoref
        'autoref_od_sph'              => 'decimal:2',
        'autoref_od_cyl'              => 'decimal:2',
        'autoref_os_sph'              => 'decimal:2',
        'autoref_os_cyl'              => 'decimal:2',
        // Keratometri
        'keratometri1_od'             => 'decimal:2',
        'keratometri2_od'             => 'decimal:2',
        'keratometri1_os'             => 'decimal:2',
        'keratometri2_os'             => 'decimal:2',
        // ADD
        'add_power_od'                => 'decimal:2',
        'add_power_os'                => 'decimal:2',
        // Refraksi Subjektif
        'refraksi_subjektif_od_sph'   => 'decimal:2',
        'refraksi_subjektif_od_cyl'   => 'decimal:2',
        'refraksi_subjektif_os_sph'   => 'decimal:2',
        'refraksi_subjektif_os_cyl'   => 'decimal:2',
        // Kacamata Lama
        'old_glasses_od_sph'          => 'decimal:2',
        'old_glasses_od_cyl'          => 'decimal:2',
        'old_glasses_add_od'          => 'decimal:2',
        'old_glasses_os_sph'          => 'decimal:2',
        'old_glasses_os_cyl'          => 'decimal:2',
        'old_glasses_add_os'          => 'decimal:2',
        // IOP
        'iop_od'                      => 'decimal:2',
        'iop_os'                      => 'decimal:2',
        'iop_extra_readings'          => 'array',
        // Shared
        'pd_distance'                 => 'decimal:2',
        'raw_data'                    => 'array',
        // Finalisasi
        'is_finalized'                => 'boolean',
        'is_skipped'                  => 'boolean',
        'finalized_at'                => 'datetime',
        'signature_timestamp'         => 'datetime',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function examinedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'examined_by_id');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'finalized_by_id');
    }

    public function prescription(): HasOne
    {
        return $this->hasOne(RefractionPrescription::class, 'refraction_record_id');
    }
}
