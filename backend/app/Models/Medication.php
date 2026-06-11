<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Medication extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    public const FORM_SEDIAAN = ['TABLET', 'KAPSUL', 'SIRUP', 'TETES_MATA', 'SALEP_MATA', 'INJEKSI', 'LAIN'];
    public const GOLONGAN    = ['BEBAS', 'BEBAS_TERBATAS', 'KERAS', 'NARKOTIKA', 'PSIKOTROPIKA'];
    // Golongan yang boleh dijual sebagai obat tambahan apotek tanpa resep dokter.
    public const GOLONGAN_OTC = ['BEBAS', 'BEBAS_TERBATAS'];

    protected $fillable = [
        'legacy_uuid',
        'code',
        'kfa_code',
        'name',
        'generic_name',
        'composition',
        'manufacturer',
        'formularium',
        'form_sediaan',
        'golongan',
        'unit',
        'unit_besar',
        'unit_kecil',
        'konversi',
        'stock',
        'min_stock',
        'price',
        'expiry_date',
        'batch_number',
        'description',
        'is_active',
    ];

    protected $casts = [
        'price'       => 'decimal:2',
        'konversi'    => 'integer',
        'expiry_date' => 'date',
        'is_active'   => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function medicationTariffs(): HasMany
    {
        return $this->hasMany(MedicationTariff::class);
    }

    public function prescriptionItems(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class);
    }

    /** Varian kemasan jual (per Strip/Box) — lihat MedicationSaleUnit. */
    public function saleUnits(): HasMany
    {
        return $this->hasMany(MedicationSaleUnit::class);
    }
}
