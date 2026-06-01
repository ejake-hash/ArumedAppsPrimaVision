<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurgeryPackage extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'legacy_uuid',
        'name',
        'code',
        'category',
        'description',
        'keterangan',
        'estimated_duration',
        'price',
        'total_base_price',
        'is_active',
    ];

    protected $casts = [
        'price'            => 'decimal:2',
        'total_base_price' => 'decimal:2',
        'is_active'        => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function surgerySchedules(): HasMany
    {
        return $this->hasMany(SurgerySchedule::class);
    }

    public function doctorExaminations(): HasMany
    {
        return $this->hasMany(DoctorExamination::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SurgeryPackageItem::class);
    }

    public function packageTariffs(): HasMany
    {
        return $this->hasMany(SurgeryPackageTariff::class);
    }

    /**
     * Hitung ulang total_base_price dari semua items dan simpan.
     * Dipanggil setiap kali item paket ditambah/diubah/dihapus.
     */
    public function recalcTotalBasePrice(): float
    {
        $total = (float) $this->items()
            ->selectRaw('COALESCE(SUM(quantity * default_price), 0) as total')
            ->value('total');

        $this->update(['total_base_price' => $total]);
        return $total;
    }
}
